<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Enums\ReservationStatus;
use App\Enums\VehicleStatus;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Settings\ReservationSettings;
use Carbon\CarbonImmutable;

/**
 * Statut « Réservé » (Q28, valeur par défaut) : le statut décrit l'état courant du
 * véhicule ; il est « Réservé » quand une réservation confirmée lui est affectée et que
 * son départ est à moins de `reservations.reserved_status_window_hours` (24 h), ou
 * déjà passé sans être clos. La disponibilité se calcule toujours sur les périodes,
 * jamais sur ce statut.
 *
 * Seulement entre « Disponible » et « Réservé » : un véhicule loué, en maintenance ou
 * indisponible n'est pas touché. Appelé par les actions de réservation et chaque heure.
 */
final class ReservedStatusSync
{
    public function __construct(
        private readonly VehicleStatusService $statuses,
        private readonly ReservationSettings $settings,
    ) {}

    public function sync(Vehicle $vehicle, ?CarbonImmutable $now = null): ?VehicleStatus
    {
        $now ??= CarbonImmutable::now();
        $vehicle = Vehicle::query()->withoutGlobalScopes()->findOrFail($vehicle->id);

        $next = Reservation::query()->withoutGlobalScopes()
            ->where('vehicle_id', $vehicle->id)
            ->where('status', ReservationStatus::Confirmed->value)
            ->where('starts_at', '<=', $now->addHours(max(0, $this->settings->reserved_status_window_hours)))
            ->where('ends_at', '>', $now)
            ->orderBy('starts_at')
            ->first();

        if ($vehicle->status === VehicleStatus::Available && $next !== null) {
            $this->statuses->transition($vehicle, VehicleStatus::Reserved, cause: $next, reason: "Départ prévu le {$next->starts_at->timezone((string) config('app.timezone'))->format('d/m/Y H:i')}");

            return VehicleStatus::Reserved;
        }

        if ($vehicle->status === VehicleStatus::Reserved && $next === null) {
            $this->statuses->transition($vehicle, VehicleStatus::Available, reason: 'Plus de départ prévu dans le délai');

            return VehicleStatus::Available;
        }

        return null;
    }

    /**
     * Contrôle de tout le parc (planifié chaque heure).
     */
    public function syncAll(?CarbonImmutable $now = null): void
    {
        Vehicle::query()->withoutGlobalScopes()
            ->whereIn('status', [VehicleStatus::Available->value, VehicleStatus::Reserved->value])
            ->orderBy('id')
            ->each(fn (Vehicle $vehicle) => $this->sync($vehicle, $now));
    }
}
