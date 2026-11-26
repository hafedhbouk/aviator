<?php

declare(strict_types=1);

namespace App\Domain\Availability\Actions;

use App\Domain\Availability\Exceptions\ReservationException;
use App\Domain\Availability\Period;
use App\Domain\Availability\ReservationHold;
use App\Domain\Fleet\ReservedStatusSync;
use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

/**
 * Affecte (ou change) le véhicule d'une réservation confirmée (C-05 : au plus tard au
 * départ). Le devis figé ne change pas. Mêmes contrôles qu'à la création, sous verrou
 * de la catégorie puis du véhicule (ReservationHold) : véhicule libre, de la catégorie,
 * localisé dans l'agence de départ, assurance et visite valides au départ (scénario 10),
 * sans priver les autres réservations sans véhicule (Q36).
 */
final class AssignVehicle
{
    public function __construct(
        private readonly ReservationHold $hold,
        private readonly ReservedStatusSync $reserved,
    ) {}

    public function handle(Reservation $reservation, int $vehicleId, User $actor): Reservation
    {
        if (! $actor->can('update', $reservation)) {
            throw ReservationException::notAllowed('cette réservation');
        }

        if ($reservation->status !== ReservationStatus::Confirmed) {
            throw ReservationException::invalidStatus($reservation->status->label(), 'affectation d\'un véhicule');
        }

        $previous = $reservation->vehicle_id;

        DB::transaction(function () use ($reservation, $vehicleId): void {
            $period = new Period($reservation->starts_at, $reservation->ends_at);
            $buffer = $this->hold->check($reservation->vehicle_category_id, $reservation->agency_id, $period, $vehicleId, $reservation->id);

            $reservation->vehicle_id = $vehicleId;
            $reservation->save();

            $this->hold->apply($reservation, $buffer);
        });

        foreach (array_unique(array_filter([$previous, $vehicleId])) as $id) {
            $this->reserved->sync(Vehicle::query()->withoutGlobalScopes()->findOrFail($id));
        }

        return $reservation->refresh();
    }
}
