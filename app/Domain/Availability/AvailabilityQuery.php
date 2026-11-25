<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use App\Domain\Fleet\DepartureGuard;
use App\Domain\Fleet\DocumentStatusSync;
use App\Domain\Setup\CategoryRules;
use App\Enums\VehicleDocumentType;
use App\Enums\VehicleStatus;
use App\Models\Vehicle;
use App\Models\VehicleBlock;
use App\Models\VehicleCategory;
use App\Models\VehicleDocument;
use App\Models\VehicleStatusLog;

/**
 * Recherche des véhicules disponibles (C-01) : agence de départ, catégorie facultative,
 * période. Lecture seule, sans verrou ; le contrôle qui fait foi est celui de
 * BlockRegistry au moment de l'enregistrement (RG-02).
 *
 * - Véhicules **localisés** dans l'agence de départ (retour à la même agence, Q3).
 * - Exclus : sortis du parc, en maintenance, indisponibles pour une autre raison que
 *   leurs documents ; ceux qui ont une occupation active qui chevauche la période,
 *   délai de préparation de leur catégorie compris.
 * - Pour chaque véhicule : documents obligatoires non valides au départ (scénario 10),
 *   pour avertir l'agent ; l'affectation les refusera (lot L11).
 * - Aucune donnée client : lisible depuis toutes les agences (Q37, Q38).
 * - Nombre de requêtes indépendant du nombre de véhicules (aucune requête N+1).
 */
final class AvailabilityQuery
{
    /**
     * @return list<array{vehicle_id: int, label: string, category_id: int, buffer_minutes: int, missing_documents: list<VehicleDocumentType>}>
     */
    public function vehicles(int $agencyId, ?int $categoryId, Period $period, bool $locking = false): array
    {
        $vehicles = Vehicle::query()->withoutGlobalScopes()
            ->where('current_agency_id', $agencyId)
            ->whereIn('status', [VehicleStatus::Available->value, VehicleStatus::Reserved->value, VehicleStatus::Rented->value, VehicleStatus::Unavailable->value])
            ->when($categoryId !== null, fn ($query) => $query->where('vehicle_category_id', $categoryId))
            ->orderBy('plate_number')
            ->when($locking, fn ($query) => $query->sharedLock())
            ->get();

        if ($vehicles->isEmpty()) {
            return [];
        }

        $buffers = VehicleCategory::query()
            ->whereIn('id', $vehicles->pluck('vehicle_category_id')->unique()->all())
            ->get()
            ->mapWithKeys(fn (VehicleCategory $category): array => [$category->id => CategoryRules::for($category)->preparationBufferMinutes]);

        $blocked = $this->blockedVehicleIds($vehicles->all(), $buffers->all(), $period, $locking);
        $unavailableForDocuments = $this->unavailableForDocuments($vehicles->where('status', VehicleStatus::Unavailable)->pluck('id')->all());

        $documents = VehicleDocument::query()
            ->whereIn('vehicle_id', $vehicles->pluck('id')->all())
            ->whereIn('type', array_map(fn (VehicleDocumentType $type): string => $type->value, DepartureGuard::required()))
            ->get()
            ->groupBy('vehicle_id');

        $results = [];

        foreach ($vehicles as $vehicle) {
            if (in_array($vehicle->id, $blocked, true)) {
                continue;
            }

            if ($vehicle->status === VehicleStatus::Unavailable && ! in_array($vehicle->id, $unavailableForDocuments, true)) {
                continue;
            }

            $results[] = [
                'vehicle_id' => $vehicle->id,
                'label' => $vehicle->label(),
                'category_id' => $vehicle->vehicle_category_id,
                'buffer_minutes' => (int) $buffers[$vehicle->vehicle_category_id],
                'missing_documents' => DepartureGuard::missingAmong(collect($documents->get($vehicle->id, [])), $period->startsAt),
            ];
        }

        return $results;
    }

    /**
     * Une requête par catégorie présente (le délai de préparation en dépend).
     *
     * @param  list<Vehicle>  $vehicles
     * @param  array<int, int>  $buffers
     * @return list<int>
     */
    private function blockedVehicleIds(array $vehicles, array $buffers, Period $period, bool $locking): array
    {
        $byCategory = [];
        foreach ($vehicles as $vehicle) {
            $byCategory[$vehicle->vehicle_category_id][] = $vehicle->id;
        }

        $blocked = [];

        foreach ($byCategory as $categoryId => $ids) {
            $endsAt = $period->endsWithBuffer($buffers[$categoryId] ?? 0);

            $blocked = array_merge($blocked, VehicleBlock::query()
                ->whereIn('vehicle_id', $ids)
                ->where('active', true)
                ->where('starts_at', '<', $endsAt)
                ->where('ends_at', '>', $period->startsAt)
                ->when($locking, fn ($query) => $query->sharedLock())
                ->distinct()
                ->pluck('vehicle_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all());
        }

        return $blocked;
    }

    /**
     * Véhicules indisponibles **pour leurs documents** (D41) : leur dernier changement
     * de statut porte ce motif. Une requête pour tous.
     *
     * @param  list<int>  $vehicleIds
     * @return list<int>
     */
    private function unavailableForDocuments(array $vehicleIds): array
    {
        if ($vehicleIds === []) {
            return [];
        }

        return VehicleStatusLog::query()
            ->whereIn('vehicle_id', $vehicleIds)
            ->orderBy('vehicle_id')->orderByDesc('occurred_at')->orderByDesc('id')
            ->get()
            ->unique('vehicle_id')
            ->filter(fn (VehicleStatusLog $log): bool => $log->to_status === VehicleStatus::Unavailable
                && str_starts_with((string) $log->reason, DocumentStatusSync::REASON_PREFIX))
            ->pluck('vehicle_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }
}
