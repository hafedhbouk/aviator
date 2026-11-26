<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use App\Models\Vehicle;
use App\Models\VehicleCategory;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Verrous de ligne de la disponibilité (RG-02, architecture §6.1) : la ligne du
 * véhicule pour une occupation, la ligne de la catégorie pour une réservation sans
 * véhicule (Q36, lot L11). Tenus jusqu'à la fin de la transaction : une seconde
 * saisie attend, puis voit la première.
 */
final class VehicleLock
{
    public function vehicle(int $vehicleId): Vehicle
    {
        $this->assertInTransaction();

        return Vehicle::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($vehicleId);
    }

    public function category(int $categoryId): VehicleCategory
    {
        $this->assertInTransaction();

        return VehicleCategory::query()->lockForUpdate()->findOrFail($categoryId);
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Verrou de disponibilité hors transaction : il serait rendu aussitôt.');
        }
    }
}
