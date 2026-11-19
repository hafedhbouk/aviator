<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Enums\VehicleStatus;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;

/**
 * Contrôle quotidien des statuts (architecture §7, commande fleet:check-status) :
 * applique la règle des documents (DocumentStatusSync) à tout le parc, hors véhicules
 * sortis. Idempotent : une seconde exécution le même jour ne change rien.
 * Les règles liées aux réservations (« Réservé » avant le départ, Q28) s'y ajoutent
 * au lot L11.
 */
final class StatusConsistencyCheck
{
    public function __construct(private readonly DocumentStatusSync $documents) {}

    /**
     * @return array{checked: int, unavailable: int, available: int}
     */
    public function run(?CarbonImmutable $today = null): array
    {
        $report = ['checked' => 0, 'unavailable' => 0, 'available' => 0];

        Vehicle::query()->withoutGlobalScopes()
            ->where('status', '!=', VehicleStatus::Retired->value)
            ->orderBy('id')
            ->each(function (Vehicle $vehicle) use (&$report, $today): void {
                $report['checked']++;

                $changed = $this->documents->sync($vehicle, today: $today);

                if ($changed === VehicleStatus::Unavailable) {
                    $report['unavailable']++;
                } elseif ($changed === VehicleStatus::Available) {
                    $report['available']++;
                }
            });

        return $report;
    }
}
