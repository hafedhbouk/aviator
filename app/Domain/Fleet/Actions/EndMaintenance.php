<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Actions;

use App\Domain\Fleet\Exceptions\FleetException;
use App\Domain\Fleet\VehicleStatusService;
use App\Enums\VehicleStatus;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Authorization\Permission;

/**
 * Fin d'intervention (D47) : ramène un véhicule « En maintenance » à « Disponible », en
 * attendant les ordres de maintenance de la phase 8. Réservée à l'admin et au super admin
 * (`fleet.manage`), motif obligatoire, transition journalisée par VehicleStatusService.
 */
final class EndMaintenance
{
    public const MIN_REASON_LENGTH = 5;

    public function __construct(private readonly VehicleStatusService $statuses) {}

    public function handle(Vehicle $vehicle, string $reason, User $actor): Vehicle
    {
        if (! $actor->hasPermissionTo(Permission::FleetManage->value) || ! $actor->can('update', $vehicle)) {
            throw FleetException::notAllowed('la fin d\'intervention');
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            throw FleetException::reasonRequired(self::MIN_REASON_LENGTH);
        }

        if ($vehicle->status !== VehicleStatus::Maintenance) {
            throw FleetException::notInMaintenance($vehicle->label());
        }

        return $this->statuses->transition($vehicle, VehicleStatus::Available, actor: $actor, reason: "Fin d'intervention : {$reason}");
    }
}
