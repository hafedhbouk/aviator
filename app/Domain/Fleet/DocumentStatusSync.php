<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Enums\VehicleDocumentType;
use App\Enums\VehicleStatus;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleStatusLog;
use Carbon\CarbonImmutable;

/**
 * Statut dérivé des documents (cahier §5.1 : « Indisponible : assurance ou visite
 * expirée ») :
 *
 * - véhicule disponible sans assurance ou visite technique valide aujourd'hui → indisponible ;
 * - véhicule rendu indisponible **pour ce motif** et de nouveau en règle → disponible.
 *
 * Un véhicule indisponible pour une autre raison (sinistre, immobilisation) n'est
 * jamais remis en service ici. Réservé, loué ou en maintenance : aucun changement ;
 * l'affectation et le départ sont contrôlés par DepartureGuard.
 * Appelé à l'enregistrement d'un document et par fleet:check-status (chaque jour).
 */
final class DocumentStatusSync
{
    public const REASON_PREFIX = 'Documents obligatoires non valides';

    public function __construct(
        private readonly DepartureGuard $guard,
        private readonly VehicleStatusService $statuses,
    ) {}

    /**
     * @return VehicleStatus|null nouveau statut, ou null si rien ne change
     */
    public function sync(Vehicle $vehicle, ?User $actor = null, ?CarbonImmutable $today = null): ?VehicleStatus
    {
        $today ??= CarbonImmutable::today(config('app.timezone'));
        $missing = $this->guard->missingOn($vehicle, $today);

        if ($vehicle->status === VehicleStatus::Available && $missing !== []) {
            $labels = array_map(fn (VehicleDocumentType $type): string => mb_strtolower($type->label()), $missing);
            $this->statuses->transition($vehicle, VehicleStatus::Unavailable, actor: $actor,
                reason: self::REASON_PREFIX.' : '.implode(', ', $labels));

            return VehicleStatus::Unavailable;
        }

        if ($vehicle->status === VehicleStatus::Unavailable && $missing === [] && $this->unavailableForDocuments($vehicle)) {
            $this->statuses->transition($vehicle, VehicleStatus::Available, actor: $actor,
                reason: 'Documents obligatoires de nouveau valides');

            return VehicleStatus::Available;
        }

        return null;
    }

    private function unavailableForDocuments(Vehicle $vehicle): bool
    {
        $last = VehicleStatusLog::query()
            ->where('vehicle_id', $vehicle->id)
            ->latest('occurred_at')->latest('id')
            ->first();

        return $last !== null
            && $last->to_status === VehicleStatus::Unavailable
            && str_starts_with((string) $last->reason, self::REASON_PREFIX);
    }
}
