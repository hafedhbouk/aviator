<?php

declare(strict_types=1);

namespace App\Domain\Rental\Actions;

use App\Domain\Crm\CustomerRiskGate;
use App\Domain\Rental\DriverEligibility;
use App\Domain\Rental\Exceptions\ContractException;
use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\Driver;
use App\Models\User;
use App\Models\VehicleCategory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Ajoute ou met à jour un conducteur autorisé d'un contrat en brouillon (D-02, D-03,
 * Q42, Q47, Q48).
 *
 * - Conducteur déjà connu du client (`driver_id`) : ses dates manquantes peuvent être
 *   complétées ; ou nouveau conducteur, rattaché au client du contrat (le particulier
 *   lui-même, ou une personne désignée par l'entreprise, Q47).
 * - Contrôles aux dates du contrat (DriverEligibility) : refus motivé ; avertissements
 *   (permis étranger, date de fin non renseignée) enregistrés avec le résultat.
 * - Conducteur rattaché par son permis ou sa pièce à un client sur liste noire : refus
 *   sans motif (Q48, RG-15). Le client du contrat lui-même relève de la dérogation du
 *   contrat (D46).
 * - Un seul conducteur principal : en désigner un retire ce rôle aux autres.
 */
final class SaveContractDriver
{
    public function __construct(
        private readonly DriverEligibility $eligibility,
        private readonly CustomerRiskGate $risk,
    ) {}

    /**
     * @param  array<string, mixed>  $data  driver_id, ou first_name, last_name, license_number,
     *                                      id_document_number ; birth_date, license_issued_on,
     *                                      license_expires_on, license_country ; is_primary
     */
    public function handle(Contract $contract, array $data, User $actor): Driver
    {
        if (! $actor->can('update', $contract)) {
            throw ContractException::notAllowed('ce contrat');
        }

        if ($contract->status !== ContractStatus::Draft) {
            throw ContractException::invalidStatus($contract->status->label(), 'modification des conducteurs');
        }

        $values = $this->validated($data);

        return DB::transaction(function () use ($contract, $values, $actor): Driver {
            $locked = Contract::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($contract->id);

            if ($locked->status !== ContractStatus::Draft) {
                throw ContractException::invalidStatus($locked->status->label(), 'modification des conducteurs');
            }

            $driver = $this->driver($locked, $values, $actor);
            $category = VehicleCategory::query()->findOrFail($locked->vehicle_category_id);
            $checks = $this->eligibility->check($driver, $category, $locked->starts_at, $locked->ends_at);

            if ($checks['refusals'] !== []) {
                throw ContractException::driverRefused("{$driver->first_name} {$driver->last_name}", $checks['refusals']);
            }

            if ($this->risk->isDriverBlacklisted($driver, except: $locked->customer_id)) {
                throw ContractException::driverBlocked();
            }

            $primary = (bool) $values['is_primary'];

            if ($primary) {
                $locked->drivers()->newPivotQuery()->update(['is_primary' => false]);
            }

            $locked->drivers()->syncWithoutDetaching([
                $driver->id => ['is_primary' => $primary, 'checks' => json_encode($checks + ['checked_at' => CarbonImmutable::now()->toIso8601String()], JSON_THROW_ON_ERROR)],
            ]);

            activity('audit')->causedBy($actor)->performedOn($locked)->event('contract_driver_saved')
                ->withProperties(['driver_id' => $driver->id, 'is_primary' => $primary, 'warnings' => $checks['warnings']])
                ->log('Conducteur du contrat enregistré');

            return $driver;
        });
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function driver(Contract $contract, array $values, User $actor): Driver
    {
        $dates = array_filter([
            'birth_date' => $values['birth_date'],
            'license_issued_on' => $values['license_issued_on'],
            'license_expires_on' => $values['license_expires_on'],
            'license_country' => $values['license_country'],
        ], fn (mixed $value): bool => $value !== null);

        if ($values['driver_id'] !== null) {
            $driver = Driver::query()->lockForUpdate()->findOrFail($values['driver_id']);

            if ($driver->customer_id !== $contract->customer_id) {
                throw ContractException::driverNotOfCustomer();
            }

            if ($dates !== []) {
                $driver->fill($dates)->save();
            }

            return $driver;
        }

        return Driver::query()->create([
            'customer_id' => $contract->customer_id,
            'first_name' => $values['first_name'],
            'last_name' => $values['last_name'],
            'license_number' => $values['license_number'],
            'id_document_number' => $values['id_document_number'],
            'created_by' => $actor->id,
        ] + $dates);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validated(array $data): array
    {
        $text = fn (string $key): ?string => is_scalar($data[$key] ?? null) && trim((string) $data[$key]) !== '' ? trim((string) $data[$key]) : null;
        $existing = $text('driver_id') !== null;

        $values = [
            'driver_id' => $text('driver_id'),
            'first_name' => $text('first_name'),
            'last_name' => $text('last_name'),
            'license_number' => $text('license_number'),
            'id_document_number' => $text('id_document_number'),
            'birth_date' => $text('birth_date'),
            'license_issued_on' => $text('license_issued_on'),
            'license_expires_on' => $text('license_expires_on'),
            'license_country' => ($country = $text('license_country')) === null ? null : mb_strtoupper($country),
            'is_primary' => (bool) ($data['is_primary'] ?? false),
        ];

        Validator::make($values, [
            'driver_id' => ['nullable', 'integer', 'exists:drivers,id'],
            'first_name' => [$existing ? 'nullable' : 'required', 'string', 'max:100'],
            'last_name' => [$existing ? 'nullable' : 'required', 'string', 'max:100'],
            'license_number' => [$existing ? 'nullable' : 'required', 'string', 'max:64'],
            'id_document_number' => ['nullable', 'string', 'max:64'],
            'birth_date' => [$existing ? 'nullable' : 'required', 'date_format:Y-m-d', 'before:today'],
            'license_issued_on' => [$existing ? 'nullable' : 'required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'license_expires_on' => ['nullable', 'date_format:Y-m-d'],
            'license_country' => ['nullable', 'regex:/^[A-Z]{2}$/'],
        ], attributes: [
            'first_name' => 'prénom', 'last_name' => 'nom', 'license_number' => 'numéro de permis',
            'birth_date' => 'date de naissance', 'license_issued_on' => 'date d\'obtention du permis',
            'license_expires_on' => 'fin de validité du permis', 'license_country' => 'pays du permis',
        ])->validate();

        $values['driver_id'] = $values['driver_id'] === null ? null : (int) $values['driver_id'];

        return $values;
    }
}
