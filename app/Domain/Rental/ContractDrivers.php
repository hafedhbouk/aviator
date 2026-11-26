<?php

declare(strict_types=1);

namespace App\Domain\Rental;

use App\Domain\Crm\CustomerRiskGate;
use App\Domain\Rental\Exceptions\ContractException;
use App\Models\Contract;
use App\Models\Driver;
use App\Models\VehicleCategory;
use Carbon\CarbonImmutable;

/**
 * Conducteurs d'un contrat au moment du départ (D-02, D-03, Q47) : un conducteur
 * principal obligatoire, et chaque conducteur contrôlé de nouveau à la date réelle du
 * départ (une liste noire ou une échéance a pu changer depuis l'ajout). Appelé par le
 * départ (lot L5).
 */
final class ContractDrivers
{
    public function __construct(
        private readonly DriverEligibility $eligibility,
        private readonly CustomerRiskGate $risk,
    ) {}

    /**
     * @throws ContractException
     */
    public function assertReadyForDeparture(Contract $contract, CarbonImmutable $departureAt): void
    {
        $drivers = $contract->drivers()->get();

        if (! $drivers->contains(fn (Driver $driver): bool => (bool) $driver->getRelationValue('pivot')?->getAttribute('is_primary'))) {
            throw ContractException::primaryDriverRequired();
        }

        $category = VehicleCategory::query()->findOrFail($contract->vehicle_category_id);

        foreach ($drivers as $driver) {
            $checks = $this->eligibility->check($driver, $category, $departureAt, $contract->ends_at);

            if ($checks['refusals'] !== []) {
                throw ContractException::driverRefused("{$driver->first_name} {$driver->last_name}", $checks['refusals']);
            }

            if ($this->risk->isDriverBlacklisted($driver, except: $contract->customer_id)) {
                throw ContractException::driverBlocked();
            }
        }
    }
}
