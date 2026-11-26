<?php

declare(strict_types=1);

namespace App\Domain\Availability\Planning;

use App\Enums\VehicleStatus;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Models\VehicleBlock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use UnexpectedValueException;

/**
 * Données du planning (C-02, D35, lot L12), sur la base réelle :
 * - véhicules du parc (hors sortis), regroupés par agence de **localisation**, filtres
 *   facultatifs par agence et par catégorie ; tous les profils voient tous les véhicules,
 *   la projection masque ce qui n'est pas à eux (PlanningProjection, Q38) ;
 * - occupations actives qui chevauchent **strictement** la fenêtre affichée
 *   `[from, to)` (index `vehicle_id, active, starts_at, ends_at`).
 *
 * Nombre de requêtes constant, quel que soit le nombre de véhicules ou de blocs
 * (condition 3 de D35) : véhicules, occupations, puis sources chargées par type
 * (réservations avec leur client).
 */
final class PlanningQuery
{
    /**
     * @return list<Vehicle>
     */
    public function vehicles(?int $agencyId = null, ?int $categoryId = null): array
    {
        return Vehicle::query()->withoutGlobalScopes()
            ->with(['currentAgency', 'category'])
            ->where('status', '!=', VehicleStatus::Retired->value)
            ->when($agencyId !== null, fn ($query) => $query->where('current_agency_id', $agencyId))
            ->when($categoryId !== null, fn ($query) => $query->where('vehicle_category_id', $categoryId))
            ->orderBy('current_agency_id')->orderBy('plate_number')
            ->get()
            ->all();
    }

    /**
     * @param  list<int>  $vehicleIds
     * @return list<PlanningBlock>
     */
    public function blocks(array $vehicleIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($vehicleIds === []) {
            return [];
        }

        $blocks = VehicleBlock::query()
            ->whereIn('vehicle_id', $vehicleIds)
            ->where('active', true)
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->with(['source' => fn (MorphTo $morph) => $morph->morphWith([Reservation::class => ['customer']])])
            ->orderBy('starts_at')
            ->get();

        // Dates lues brutes et analysées une seule fois : les casts d'Eloquent coûtent
        // environ dix fois plus, sensible sur un mois de 50 véhicules (condition 3 de D35).
        $timezone = (string) config('app.timezone');
        $parse = static fn (string $value): CarbonImmutable => CarbonImmutable::createFromFormat('Y-m-d H:i:s', $value, $timezone)
            ?: throw new UnexpectedValueException("Date d'occupation illisible : {$value}");
        // Modèle d'URL calculé une fois, puis complété par l'identifiant.
        $urlTemplate = route('filament.admin.resources.comptoir.reservations.view', ['record' => '__RESERVATION__']);

        $reservationType = (new Reservation)->getMorphClass();
        $result = [];

        foreach ($blocks as $block) {
            // Type lu sur la ligne d'occupation : la portée d'agence ne charge pas les
            // réservations des autres agences (source nulle), qui restent des réservations.
            $isReservation = $block->source_type === $reservationType;
            $source = $block->source;
            $reservation = $source instanceof Reservation ? $source : null;
            $buffer = (int) $block->getRawOriginal('buffer_minutes');
            $endsWithBuffer = $parse((string) $block->getRawOriginal('ends_at'));

            $result[] = new PlanningBlock(
                vehicleId: $block->vehicle_id,
                agencyId: $block->agency_id,
                type: $isReservation ? PlanningBlockType::Reservation : PlanningBlockType::Unavailability,
                startsAt: $parse((string) $block->getRawOriginal('starts_at')),
                endsAt: $endsWithBuffer->subMinutes($buffer),
                bufferEndsAt: $buffer > 0 ? $endsWithBuffer : null,
                reference: $reservation?->number,
                customerName: $reservation?->customer->displayName(),
                late: false,
                url: $reservation !== null ? str_replace('__RESERVATION__', (string) $reservation->id, $urlTemplate) : null,
            );
        }

        return $result;
    }
}
