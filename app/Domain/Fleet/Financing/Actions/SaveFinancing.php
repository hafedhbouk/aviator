<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Financing\Actions;

use App\Domain\Fleet\Exceptions\FleetException;
use App\Domain\Fleet\Financing\FinancingSchedule;
use App\Enums\FinancingType;
use App\Enums\VehicleStatus;
use App\Models\FinancingInstallment;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleFinancing;
use App\Support\Money\AmountInput;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Enregistre un leasing (B-11) ou un crédit (B-15) et génère son échéancier (B-12).
 *
 * - Super admin et admin (fleet.manage). Véhicule non sorti du parc, dont le mode de
 *   financement (fiche, B-02) correspond au type ; un seul financement en cours par
 *   véhicule, contrôlé sous verrou de la ligne du véhicule.
 * - Leasing : société de leasing, référence, début, fin, première échéance, apport
 *   (premier loyer majoré), loyer HT et TTC saisis (TTC ≥ HT ; aucun taux de TVA
 *   déduit), valeur résiduelle, date de levée d'option.
 * - Crédit : banque, référence, capital, taux annuel, nombre de mensualités, première
 *   échéance ; mensualité calculée.
 * - Modification seulement tant qu'aucune échéance n'est pointée : l'échéancier est
 *   alors régénéré en entier (jamais de double échéance : unique par rang).
 */
final class SaveFinancing
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Vehicle $vehicle, ?VehicleFinancing $financing, array $data, User $actor): VehicleFinancing
    {
        if (! $actor->can($financing === null ? 'create' : 'update', $financing ?? VehicleFinancing::class)) {
            throw FleetException::notAllowed('les financements');
        }

        if ($financing !== null && $financing->vehicle_id !== $vehicle->id) {
            throw FleetException::notAllowed('ce financement');
        }

        $values = $this->validated($data);

        return DB::transaction(function () use ($vehicle, $financing, $values, $actor): VehicleFinancing {
            $locked = Vehicle::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($vehicle->id);

            if ($locked->status === VehicleStatus::Retired) {
                throw FleetException::vehicleRetired($locked->label());
            }

            if ($locked->financing_mode->value !== $values['type']->value) {
                throw FleetException::financingModeMismatch($locked->financing_mode->label(), $values['type']->label());
            }

            $otherOpen = VehicleFinancing::query()
                ->where('vehicle_id', $locked->id)
                ->whereNull('outcome')
                ->when($financing !== null, fn ($query) => $query->whereKeyNot($financing?->id))
                ->exists();

            if ($otherOpen) {
                throw FleetException::financingAlreadyOpen();
            }

            if ($financing !== null) {
                if (! $financing->isOpen()) {
                    throw FleetException::financingClosed();
                }

                if ($financing->installments()->whereNotNull('paid_on')->exists()) {
                    throw FleetException::financingHasPaidInstallments();
                }
            }

            $financing ??= new VehicleFinancing(['vehicle_id' => $locked->id, 'created_by' => $actor->id]);
            $financing->fill($values['attributes']);
            $financing->save();

            $financing->installments()->delete();

            foreach ($values['schedule'] as $row) {
                FinancingInstallment::query()->create(['vehicle_financing_id' => $financing->id] + $row);
            }

            return $financing->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{type: FinancingType, attributes: array<string, mixed>, schedule: list<array<string, mixed>>}
     */
    private function validated(array $data): array
    {
        $type = FinancingType::tryFrom(is_string($data['type'] ?? null) ? $data['type'] : '');
        $credit = $type === FinancingType::Credit;

        $validated = Validator::make($data, [
            'type' => ['required', Rule::enum(FinancingType::class)],
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'reference' => ['required', 'string', 'max:64'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'first_due_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'ends_on' => [$credit ? 'nullable' : 'required', 'date_format:Y-m-d', 'after_or_equal:first_due_on'],
            'installments_count' => [$credit ? 'required' : 'nullable', 'integer', 'between:1,360'],
            'interest_rate' => [$credit ? 'required' : 'nullable', 'regex:/^\d{1,2}([.,]\d{1,4})?$/'],
            'purchase_option_on' => ['nullable', 'date_format:Y-m-d'],
        ], attributes: [
            'type' => 'type', 'supplier_id' => 'société de leasing ou banque', 'reference' => 'référence',
            'starts_on' => 'début', 'first_due_on' => 'première échéance', 'ends_on' => 'fin',
            'installments_count' => 'nombre de mensualités', 'interest_rate' => 'taux annuel', 'purchase_option_on' => 'date de levée d\'option',
        ])->validate();

        if ($type === null) {
            throw ValidationException::withMessages(['type' => 'Le type de financement est obligatoire.']);
        }

        $startsOn = CarbonImmutable::parse((string) $validated['starts_on']);
        $firstDueOn = CarbonImmutable::parse((string) $validated['first_due_on']);
        $initial = self::optionalAmount($data, 'initial_payment_amount', 'apport initial');
        $residual = self::optionalAmount($data, 'residual_value_amount', 'valeur résiduelle');

        if ($credit) {
            $principal = self::requiredAmount($data, 'principal_amount', 'capital emprunté');
            $count = (int) $validated['installments_count'];
            $rate = str_replace(',', '.', (string) $validated['interest_rate']);
            $monthlyExcl = FinancingSchedule::creditPayment($principal, $rate, $count);
            $monthlyIncl = $monthlyExcl;
            $endsOn = $firstDueOn->addMonthsNoOverflow($count - 1);
        } else {
            $principal = null;
            $rate = null;
            $monthlyExcl = self::requiredAmount($data, 'monthly_amount_excl_tax', 'loyer mensuel HT');
            $monthlyIncl = self::requiredAmount($data, 'monthly_amount_incl_tax', 'loyer mensuel TTC');
            if ($monthlyIncl->compareTo($monthlyExcl) < 0) {
                throw ValidationException::withMessages(['monthly_amount_incl_tax' => 'Le loyer TTC ne peut pas être inférieur au loyer HT.']);
            }
            $endsOn = CarbonImmutable::parse((string) $validated['ends_on']);
            $count = FinancingSchedule::leasingCount($firstDueOn, $endsOn);
        }

        return [
            'type' => $type,
            'attributes' => [
                'type' => $type,
                'supplier_id' => (int) $validated['supplier_id'],
                'reference' => trim((string) $validated['reference']),
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'first_due_on' => $firstDueOn,
                'installments_count' => $count,
                'initial_payment_amount' => $initial,
                'monthly_amount_excl_tax' => $monthlyExcl,
                'monthly_amount_incl_tax' => $monthlyIncl,
                'residual_value_amount' => $residual,
                'principal_amount' => $principal,
                'interest_rate' => $rate,
                'purchase_option_on' => $validated['purchase_option_on'] ?? null,
            ],
            'schedule' => FinancingSchedule::build($type, $firstDueOn, $count, $monthlyExcl, $monthlyIncl, $principal, $rate),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function optionalAmount(array $data, string $key, string $label): ?Money
    {
        try {
            return AmountInput::parse($data[$key] ?? null);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([$key => "Le champ {$label} : {$exception->getMessage()}"]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function requiredAmount(array $data, string $key, string $label): Money
    {
        return self::optionalAmount($data, $key, $label)
            ?? throw ValidationException::withMessages([$key => "Le champ {$label} est obligatoire."]);
    }
}
