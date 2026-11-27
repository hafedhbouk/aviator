<?php

declare(strict_types=1);

namespace App\Domain\Rental\Actions;

use App\Domain\Approvals\Actions\RequestApproval;
use App\Domain\Approvals\ApprovalGate;
use App\Domain\Approvals\Exceptions\ApprovalException;
use App\Domain\Availability\BlockRegistry;
use App\Domain\Availability\Period;
use App\Domain\Availability\ReservationHold;
use App\Domain\Crm\CustomerRiskGate;
use App\Domain\Crm\Exceptions\CustomerBlocked;
use App\Domain\Fleet\ReservedStatusSync;
use App\Domain\Pricing\PriceQuote;
use App\Domain\Pricing\QuoteCalculator;
use App\Domain\Rental\Exceptions\ContractException;
use App\Domain\Setup\CategoryRules;
use App\Enums\ApprovalType;
use App\Enums\ContractStatus;
use App\Enums\ReservationStatus;
use App\Models\Contract;
use App\Models\ContractOption;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Settings\PricingSettings;
use App\Support\Agency\CurrentAgency;
use App\Support\Money\AmountInput;
use App\Support\Money\Money;
use App\Support\Sequences\SequenceAllocator;
use App\Support\Sequences\SequenceDefinition;
use App\Support\Sequences\SequencePeriod;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Ouverture d'un contrat (D-01, RG-03), en brouillon jusqu'au départ (D49).
 *
 * - **Depuis une réservation confirmée** : devis, options et remise repris tels quels
 *   (prix convenu), avec leurs demandes d'approbation ; véhicule de la réservation ou
 *   choisi à cet instant (C-05), contrôlé comme une affectation ; la réservation passe
 *   « convertie » et son occupation est transférée au contrat sous le même verrou,
 *   sans trou. Deux conversions simultanées de la même réservation : une seule passe.
 * - **Au comptoir** (« Louer maintenant ») : mêmes contrôles qu'une réservation
 *   (ReservationHold : capacité, véhicule libre, documents valides au départ) et même
 *   devis (QuoteCalculator) ; remise au-delà du seuil et dérogation à la liste noire
 *   ouvrent une demande au super admin (D46), le départ l'attendra.
 *
 * Conditions figées à la création (D-05, D48, D50, D51) : kilométrage (illimité ou
 * forfait par jour et prix du km supplémentaire de la catégorie), carburant (même
 * niveau au retour ; capacité du réservoir du véhicule, prix du litre et frais de
 * service du paramétrage), tarif journalier du dernier jour du devis et tarif horaire
 * de la catégorie (prolongation et retard), franchise et caution prévue de la catégorie.
 * Un paramétrage modifié ensuite ne change pas un contrat ouvert.
 */
final class OpenContract
{
    public const SEQUENCE_PREFIX = 'CTR';

    public function __construct(
        private readonly ReservationHold $hold,
        private readonly BlockRegistry $blocks,
        private readonly QuoteCalculator $quotes,
        private readonly CustomerRiskGate $risk,
        private readonly ApprovalGate $approvals,
        private readonly RequestApproval $requestApproval,
        private readonly SequenceAllocator $sequences,
        private readonly CurrentAgency $currentAgency,
        private readonly ReservedStatusSync $reserved,
        private readonly PricingSettings $pricing,
    ) {}

    public function fromReservation(Reservation $reservation, ?int $vehicleId, User $actor): Contract
    {
        if (! $actor->can('update', $reservation) || ! $actor->can('create', Contract::class)) {
            throw ContractException::notAllowed('cette réservation');
        }

        $customer = Customer::query()->withoutGlobalScopes()->findOrFail($reservation->customer_id);

        if ($this->risk->isBlacklisted($customer) && ! $this->approvals->overrideApproved($reservation, $reservation->override_approval_id)) {
            throw CustomerBlocked::blacklisted();
        }

        $previousVehicleId = $reservation->vehicle_id;
        $this->sequences->prepare(self::sequence());

        $contract = DB::transaction(function () use ($reservation, $vehicleId, $actor): Contract {
            $locked = Reservation::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($reservation->id);

            if ($locked->status !== ReservationStatus::Confirmed) {
                throw ContractException::reservationNotConfirmed($locked->status->label());
            }

            $vehicleId ??= $locked->vehicle_id ?? throw ContractException::vehicleRequired();
            $period = new Period($locked->starts_at, $locked->ends_at);
            $buffer = $this->hold->check($locked->vehicle_category_id, $locked->agency_id, $period, $vehicleId, $locked->id);

            $contract = $this->create([
                'agency_id' => $locked->agency_id,
                'return_agency_id' => $locked->return_agency_id,
                'reservation_id' => $locked->id,
                'vehicle_id' => $vehicleId,
                'vehicle_category_id' => $locked->vehicle_category_id,
                'customer_id' => $locked->customer_id,
                'discount_approval_id' => $locked->discount_approval_id,
                'override_approval_id' => $locked->override_approval_id,
            ], $period, $locked->quote, $actor);

            foreach ($locked->options as $option) {
                ContractOption::query()->create([
                    'contract_id' => $contract->id, 'option_id' => $option->option_id, 'quantity' => $option->quantity,
                    'unit_amount' => $option->unit_amount, 'total_amount' => $option->total_amount,
                ]);
            }

            $locked->forceFill(['status' => ReservationStatus::Converted, 'vehicle_id' => $vehicleId])->save();
            $this->blocks->release($locked);
            $this->blocks->occupy($vehicleId, $period, $contract, $contract->agency_id, $buffer);

            return $contract;
        });

        $this->syncVehicles([$previousVehicleId, $contract->vehicle_id]);

        return $contract->refresh();
    }

    /**
     * @param  array<string, mixed>  $data  customer_id, agency_id, vehicle_id, starts_at, ends_at, options
     *                                      (identifiant => quantité), discount_amount, discount_reason,
     *                                      override_reason
     */
    public function atCounter(array $data, User $actor): Contract
    {
        $values = $this->validated($data, $actor);

        if (! $actor->can('create', Contract::class) || ! $actor->canActOnAgency($values['agency_id'])) {
            throw ContractException::notAllowed('les contrats de cette agence');
        }

        $customer = Customer::query()->findOrFail($values['customer_id']);
        $needsOverride = $this->risk->isBlacklisted($customer);

        if ($needsOverride && mb_strlen((string) $values['override_reason']) < RequestApproval::MIN_REASON_LENGTH) {
            throw CustomerBlocked::blacklisted();
        }

        $vehicle = Vehicle::query()->withoutGlobalScopes()->findOrFail($values['vehicle_id']);
        $period = new Period($values['starts_at'], $values['ends_at']);

        if ($period->startsAt->lessThan(CarbonImmutable::now()->subMinutes(15))) {
            throw ContractException::startInPast();
        }

        $quote = $this->quotes->quote($vehicle->vehicle_category_id, $values['agency_id'], $period, $values['options'], $values['discount_amount'], allowApproval: true);

        if ($quote->discountRequiresApproval && mb_strlen((string) $values['discount_reason']) < RequestApproval::MIN_REASON_LENGTH) {
            throw ApprovalException::reasonRequired('une remise au-delà du seuil de '.$this->quotes->thresholdLabel().' %', RequestApproval::MIN_REASON_LENGTH);
        }

        $this->sequences->prepare(self::sequence());

        $contract = DB::transaction(function () use ($values, $vehicle, $period, $quote, $actor, $needsOverride): Contract {
            $buffer = $this->hold->check($vehicle->vehicle_category_id, $values['agency_id'], $period, $vehicle->id, null);

            $contract = $this->create([
                'agency_id' => $values['agency_id'],
                'return_agency_id' => $values['agency_id'],
                'reservation_id' => null,
                'vehicle_id' => $vehicle->id,
                'vehicle_category_id' => $vehicle->vehicle_category_id,
                'customer_id' => $values['customer_id'],
            ], $period, $quote->toArray(), $actor);

            foreach ($quote->options as $line) {
                ContractOption::query()->create([
                    'contract_id' => $contract->id, 'option_id' => $line['option_id'], 'quantity' => $line['quantity'],
                    'unit_amount' => $line['unit_amount'], 'total_amount' => $line['amount'],
                ]);
            }

            $this->blocks->occupy($vehicle->id, $period, $contract, $contract->agency_id, $buffer);
            $this->requestApprovals($contract, $quote, $values, $actor, $needsOverride);

            return $contract;
        });

        $this->syncVehicles([$contract->vehicle_id]);

        return $contract->refresh();
    }

    /**
     * @param  array<string, mixed>  $links
     * @param  array<string, mixed>  $quote  devis figé (PriceQuote::toArray)
     */
    private function create(array $links, Period $period, array $quote, User $actor): Contract
    {
        $category = VehicleCategory::query()->findOrFail($links['vehicle_category_id']);
        $rules = CategoryRules::for($category);
        $days = is_array($quote['days'] ?? null) ? $quote['days'] : [];
        $lastDay = $days === [] ? null : end($days);

        return Contract::query()->create($links + [
            'number' => $this->sequences->next(self::sequence()),
            'starts_at' => $period->startsAt,
            'ends_at' => $period->endsAt,
            'status' => ContractStatus::Draft,
            'mileage_policy' => $rules->hasUnlimitedMileage() ? Contract::MILEAGE_UNLIMITED : Contract::MILEAGE_DAILY_ALLOWANCE,
            'included_km_per_day' => $rules->hasUnlimitedMileage() ? null : $category->included_km_per_day,
            'extra_km_amount' => $rules->hasUnlimitedMileage() ? null : $category->extra_km_amount,
            'fuel_policy' => Contract::FUEL_SAME_LEVEL,
            'fuel_tank_liters' => Vehicle::query()->withoutGlobalScopes()->whereKey($links['vehicle_id'])->value('fuel_tank_liters'),
            'fuel_liter_amount' => $this->pricing->fuel_liter_amount === null ? null : Money::of($this->pricing->fuel_liter_amount),
            'fuel_service_amount' => $this->pricing->fuel_service_amount === null ? null : Money::of($this->pricing->fuel_service_amount),
            'late_hour_amount' => $rules->lateHourAmount,
            'insurance_excess_amount' => $rules->insuranceExcessAmount,
            'daily_rate_amount' => Money::of(is_array($lastDay) ? (string) $lastDay['daily_amount'] : '0'),
            'rental_amount' => Money::of((string) $quote['rental_amount']),
            'options_amount' => Money::of((string) $quote['options_amount']),
            'discount_amount' => Money::of((string) $quote['discount_amount']),
            'total_amount' => Money::of((string) $quote['total_excluding_tax']),
            'quote' => $quote,
            'deposit_expected_amount' => $category->deposit_amount,
            'created_by' => $actor->id,
        ]);
    }

    /**
     * @param  array{discount_reason: string|null, override_reason: string|null}  $values
     */
    private function requestApprovals(Contract $contract, PriceQuote $quote, array $values, User $actor, bool $needsOverride): void
    {
        $ids = [];

        if ($quote->discountRequiresApproval) {
            $subtotal = $quote->subtotal();
            $ids['discount_approval_id'] = $this->requestApproval->handle(ApprovalType::Discount, $contract, $contract->agency_id, [
                'document_number' => $contract->number,
                'starts_at' => $quote->startsAt->toIso8601String(),
                'ends_at' => $quote->endsAt->toIso8601String(),
                'subtotal_amount' => $subtotal->toDecimalString(),
                'discount_amount' => $quote->discountAmount->toDecimalString(),
                'discount_percent' => (string) $quote->discountAmount->toBigDecimal()->multipliedBy(100)->dividedBy($subtotal->toBigDecimal(), 2, RoundingMode::HalfUp),
                'threshold_percent' => $this->quotes->thresholdLabel(),
                'total_excluding_tax' => $quote->totalExcludingTax->toDecimalString(),
            ], $values['discount_reason'], $actor)->id;
        }

        if ($needsOverride) {
            $ids['override_approval_id'] = $this->requestApproval->handle(ApprovalType::BlacklistOverride, $contract, $contract->agency_id, [
                'document_number' => $contract->number,
                'customer_id' => $contract->customer_id,
                'starts_at' => $quote->startsAt->toIso8601String(),
                'ends_at' => $quote->endsAt->toIso8601String(),
            ], $values['override_reason'], $actor)->id;
        }

        if ($ids !== []) {
            $contract->forceFill($ids)->save();
        }
    }

    /** Numérotation `CTR-AAAA-NNNNN` (Q49), séquence commune aux agences. */
    public static function sequence(): SequenceDefinition
    {
        return new SequenceDefinition(self::SEQUENCE_PREFIX, SequencePeriod::Year, 5);
    }

    /**
     * @param  list<int|null>  $vehicleIds
     */
    private function syncVehicles(array $vehicleIds): void
    {
        foreach (array_unique(array_filter($vehicleIds)) as $vehicleId) {
            $this->reserved->sync(Vehicle::query()->withoutGlobalScopes()->findOrFail($vehicleId));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{customer_id: int, agency_id: int, vehicle_id: int, starts_at: CarbonImmutable, ends_at: CarbonImmutable, options: array<int, int>, discount_amount: Money|null, discount_reason: string|null, override_reason: string|null}
     */
    private function validated(array $data, User $actor): array
    {
        $validated = Validator::make([
            'customer_id' => $data['customer_id'] ?? null,
            'agency_id' => $data['agency_id'] ?? $this->currentAgency->id($actor) ?? $actor->agency_id,
            'vehicle_id' => $data['vehicle_id'] ?? null,
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
        ], [
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'agency_id' => ['required', 'integer', 'exists:agencies,id'],
            'vehicle_id' => ['required', 'integer', 'exists:vehicles,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ], attributes: [
            'customer_id' => 'client', 'agency_id' => 'agence de départ', 'vehicle_id' => 'véhicule',
            'starts_at' => 'départ', 'ends_at' => 'retour',
        ])->validate();

        $options = [];
        foreach ((array) ($data['options'] ?? []) as $optionId => $quantity) {
            if (is_numeric($optionId) && is_numeric($quantity) && (int) $quantity > 0) {
                $options[(int) $optionId] = (int) $quantity;
            }
        }

        try {
            $discount = AmountInput::parse($data['discount_amount'] ?? null);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['discount_amount' => "Le champ remise : {$exception->getMessage()}"]);
        }

        $timezone = (string) config('app.timezone');
        $text = fn (string $key): ?string => is_scalar($data[$key] ?? null) ? trim((string) $data[$key]) : null;

        return [
            'customer_id' => (int) $validated['customer_id'],
            'agency_id' => (int) $validated['agency_id'],
            'vehicle_id' => (int) $validated['vehicle_id'],
            'starts_at' => CarbonImmutable::parse((string) $validated['starts_at'], $timezone),
            'ends_at' => CarbonImmutable::parse((string) $validated['ends_at'], $timezone),
            'options' => $options,
            'discount_amount' => $discount,
            'discount_reason' => $text('discount_reason'),
            'override_reason' => $text('override_reason'),
        ];
    }
}
