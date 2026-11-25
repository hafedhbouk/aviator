<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use App\Domain\Availability\Exceptions\ReservationException;
use App\Domain\Fleet\DepartureGuard;
use App\Domain\Fleet\Exceptions\VehicleNotRentable;
use App\Domain\Setup\CategoryRules;
use App\Enums\ReservationStatus;
use App\Enums\VehicleDocumentType;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Contrôle de capacité et pose de l'occupation d'une réservation (RG-01, RG-02, Q36,
 * scénario 10). Commun à la création, à la modification et à l'affectation.
 *
 * Toujours dans la transaction de l'appelant, dans cet ordre :
 * 1. verrou de la ligne de la **catégorie** (toutes les réservations d'une catégorie
 *    sont sérialisées, avec ou sans véhicule) ;
 * 2. lectures verrouillantes : véhicules disponibles (AvailabilityQuery) et réservations
 *    sans véhicule qui chevauchent la période, délai de préparation compris ;
 * 3. capacité restante = véhicules disponibles − réservations sans véhicule ≥ 1 (Q36 :
 *    pas de surréservation ; décompte prudent : deux réservations sans véhicule qui ne
 *    se chevauchent pas entre elles comptent chacune) ;
 * 4. véhicule demandé : parmi les disponibles, de la catégorie, avec assurance et visite
 *    valides au départ (DepartureGuard) ;
 * 5. après l'enregistrement de la réservation : occupation du véhicule (BlockRegistry,
 *    sous verrou du véhicule), ou libération si la réservation n'a plus de véhicule.
 */
final class ReservationHold
{
    public function __construct(
        private readonly VehicleLock $lock,
        private readonly AvailabilityQuery $availability,
        private readonly BlockRegistry $blocks,
        private readonly DepartureGuard $departure,
    ) {}

    /**
     * Étapes 1 à 4, avant l'enregistrement.
     *
     * @return int délai de préparation de la catégorie, en minutes
     */
    public function check(int $categoryId, int $agencyId, Period $period, ?int $vehicleId, ?int $exceptReservationId): int
    {
        $this->assertInTransaction();

        $category = $this->lock->category($categoryId);
        $buffer = CategoryRules::for($category)->preparationBufferMinutes;

        $available = $this->availability->vehicles($agencyId, $categoryId, $period, locking: true);
        $ownVehicleId = $exceptReservationId === null ? null : Reservation::query()->withoutGlobalScopes()->whereKey($exceptReservationId)->value('vehicle_id');

        // Le véhicule déjà tenu par la réservation modifiée reste le sien.
        $availableIds = array_column($available, 'vehicle_id');
        if ($ownVehicleId !== null && ! in_array((int) $ownVehicleId, $availableIds, true) && (int) $ownVehicleId === $vehicleId) {
            $own = $this->ownVehicleEntry((int) $ownVehicleId, $categoryId, $agencyId, $period, $exceptReservationId, $buffer);
            if ($own !== null) {
                $available[] = $own;
            }
        }

        $unassigned = $this->unassignedOverlapping($category, $agencyId, $period, $buffer, $exceptReservationId);
        $free = count($available) - $unassigned;

        if ($vehicleId === null) {
            if ($free < 1) {
                throw ReservationException::categoryFull($category->name);
            }

            return $buffer;
        }

        $entry = collect($available)->firstWhere('vehicle_id', $vehicleId);
        $vehicle = Vehicle::query()->withoutGlobalScopes()->find($vehicleId);

        if ($vehicle !== null && $vehicle->vehicle_category_id !== $categoryId) {
            throw ReservationException::vehicleCategoryMismatch($vehicle->label(), $category->name);
        }

        if ($entry === null || $vehicle === null) {
            throw ReservationException::vehicleNotAvailable($vehicle?->label() ?? "n° {$vehicleId}");
        }

        if ($entry['missing_documents'] !== []) {
            throw VehicleNotRentable::missingDocuments(
                $vehicle->label(),
                array_map(fn (VehicleDocumentType $type): string => mb_strtolower($type->label()), $entry['missing_documents']),
                $period->startsAt->format('d/m/Y'),
            );
        }

        // Prendre ce véhicule ne doit pas priver les réservations sans véhicule.
        if ($free < 1) {
            throw ReservationException::categoryFull($category->name);
        }

        return $buffer;
    }

    /**
     * Étape 5, après l'enregistrement : occupe ou libère selon le véhicule de la réservation.
     */
    public function apply(Reservation $reservation, int $buffer): void
    {
        $this->assertInTransaction();

        if ($reservation->vehicle_id !== null && $reservation->status->holdsCapacity()) {
            $this->blocks->occupy(
                $reservation->vehicle_id,
                new Period($reservation->starts_at, $reservation->ends_at),
                $reservation,
                $reservation->agency_id,
                $buffer,
            );

            return;
        }

        $this->blocks->release($reservation);
    }

    private function unassignedOverlapping(VehicleCategory $category, int $agencyId, Period $period, int $buffer, ?int $except): int
    {
        return Reservation::query()->withoutGlobalScopes()
            ->where('vehicle_category_id', $category->id)
            ->where('agency_id', $agencyId)
            ->whereNull('vehicle_id')
            ->whereIn('status', [ReservationStatus::Confirmed->value, ReservationStatus::Pending->value])
            ->where('starts_at', '<', $period->endsWithBuffer($buffer))
            ->where('ends_at', '>', $period->startsAt->subMinutes($buffer))
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->sharedLock()
            ->count();
    }

    /**
     * Véhicule tenu par la réservation modifiée : disponible pour elle si aucune autre
     * occupation ne chevauche la nouvelle période.
     *
     * @return array{vehicle_id: int, label: string, category_id: int, buffer_minutes: int, missing_documents: list<VehicleDocumentType>}|null
     */
    private function ownVehicleEntry(int $vehicleId, int $categoryId, int $agencyId, Period $period, int $reservationId, int $buffer): ?array
    {
        $vehicle = Vehicle::query()->withoutGlobalScopes()->find($vehicleId);

        if ($vehicle === null || $vehicle->vehicle_category_id !== $categoryId || $vehicle->current_agency_id !== $agencyId) {
            return null;
        }

        $others = BlockRegistry::overlapping($vehicleId, $period->startsAt, $period->endsWithBuffer($buffer))
            ->where(fn ($query) => $query->where('source_type', '!=', (new Reservation)->getMorphClass())->orWhere('source_id', '!=', $reservationId))
            ->sharedLock()
            ->exists();

        if ($others) {
            return null;
        }

        return [
            'vehicle_id' => $vehicleId,
            'label' => $vehicle->label(),
            'category_id' => $categoryId,
            'buffer_minutes' => $buffer,
            'missing_documents' => $this->departure->missingOn($vehicle, $period->startsAt),
        ];
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Contrôle de réservation hors transaction.');
        }
    }
}
