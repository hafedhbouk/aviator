<?php

declare(strict_types=1);

namespace App\Domain\Rental\Actions;

use App\Domain\Crm\CustomerRiskGate;
use App\Domain\Rental\DriverEligibility;
use App\Domain\Rental\Exceptions\ContractException;
use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\Driver;
use App\Models\User;
use App\Models\VehicleCategory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Avenant à un contrat clôturé (D-11), motif obligatoire, en ajout seul :
 *
 * - **mention corrective** : texte figé dans l'avenant (erreur de saisie, précision) ;
 * - **conducteur additionnel** déclaré après coup : contrôlé comme au contrat (âge,
 *   permis aux dates du contrat, liste noire, Q48), rattaché au client, ajouté aux
 *   conducteurs du contrat, jamais comme conducteur principal.
 *
 * Tout écart financier (montant, frais, remise) est refusé ici : il relève d'une facture
 * complémentaire ou d'un avoir (phase 6).
 */
final class AmendContract
{
    public const MIN_REASON_LENGTH = 10;

    public function __construct(
        private readonly DriverEligibility $eligibility,
        private readonly CustomerRiskGate $risk,
    ) {}

    /**
     * @param  array<string, mixed>  $data  type (note|additional_driver), reason, note, ou les
     *                                      champs du conducteur (first_name, last_name, license_number,
     *                                      birth_date, license_issued_on, license_expires_on, license_country)
     */
    public function handle(Contract $contract, array $data, User $actor): ContractAmendment
    {
        if (! $actor->can('update', $contract)) {
            throw ContractException::notAllowed('ce contrat');
        }

        $type = is_string($data['type'] ?? null) ? $data['type'] : '';
        $reason = is_scalar($data['reason'] ?? null) ? trim((string) $data['reason']) : '';

        if (! in_array($type, [ContractAmendment::NOTE, ContractAmendment::ADDITIONAL_DRIVER], true)) {
            throw ContractException::amendmentTypeRefused();
        }

        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            throw ContractException::reasonRequired(self::MIN_REASON_LENGTH);
        }

        return DB::transaction(function () use ($contract, $data, $type, $reason, $actor): ContractAmendment {
            $locked = Contract::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($contract->id);

            if ($locked->status !== ContractStatus::Closed) {
                throw ContractException::invalidStatus($locked->status->label(), 'avenant (contrat non clôturé : modifiez-le directement)');
            }

            $changes = $type === ContractAmendment::NOTE
                ? ['note' => $this->note($data)]
                : ['driver' => $this->addDriver($locked, $data, $actor)];

            $amendment = ContractAmendment::query()->create([
                'contract_id' => $locked->id,
                'type' => $type,
                'reason' => $reason,
                'changes' => $changes,
                'created_by' => $actor->id,
            ]);

            activity('audit')->causedBy($actor)->performedOn($locked)->event('contract_amended')
                ->withProperties(['amendment_id' => $amendment->id, 'type' => $type])
                ->log('Avenant au contrat');

            return $amendment;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function note(array $data): string
    {
        $note = is_scalar($data['note'] ?? null) ? trim((string) $data['note']) : '';

        Validator::make(['note' => $note], ['note' => ['required', 'string', 'max:2000']], attributes: ['note' => 'mention'])->validate();

        return $note;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{driver_id: int, name: string, warnings: list<string>}
     */
    private function addDriver(Contract $contract, array $data, User $actor): array
    {
        $values = Validator::make($data, [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'license_number' => ['required', 'string', 'max:64'],
            'birth_date' => ['required', 'date_format:Y-m-d', 'before:today'],
            'license_issued_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'license_expires_on' => ['nullable', 'date_format:Y-m-d'],
            'license_country' => ['nullable', 'regex:/^[A-Za-z]{2}$/'],
        ], attributes: [
            'first_name' => 'prénom', 'last_name' => 'nom', 'license_number' => 'numéro de permis',
            'birth_date' => 'date de naissance', 'license_issued_on' => 'date d\'obtention du permis',
        ])->validate();

        $driver = Driver::query()->create([
            'customer_id' => $contract->customer_id,
            'first_name' => trim((string) $values['first_name']),
            'last_name' => trim((string) $values['last_name']),
            'license_number' => trim((string) $values['license_number']),
            'birth_date' => $values['birth_date'],
            'license_issued_on' => $values['license_issued_on'],
            'license_expires_on' => $values['license_expires_on'] ?? null,
            'license_country' => isset($values['license_country']) ? strtoupper((string) $values['license_country']) : null,
            'created_by' => $actor->id,
        ]);

        $category = VehicleCategory::query()->findOrFail($contract->vehicle_category_id);
        $checks = $this->eligibility->check($driver, $category, $contract->departed_at ?? $contract->starts_at, $contract->ends_at);

        if ($checks['refusals'] !== []) {
            throw ContractException::driverRefused("{$driver->first_name} {$driver->last_name}", $checks['refusals']);
        }

        if ($this->risk->isDriverBlacklisted($driver, except: $contract->customer_id)) {
            throw ContractException::driverBlocked();
        }

        $contract->drivers()->attach($driver->id, [
            'is_primary' => false,
            'checks' => json_encode($checks + ['checked_at' => CarbonImmutable::now()->toIso8601String(), 'by_amendment' => true], JSON_THROW_ON_ERROR),
        ]);

        return ['driver_id' => $driver->id, 'name' => "{$driver->first_name} {$driver->last_name}", 'warnings' => $checks['warnings']];
    }
}
