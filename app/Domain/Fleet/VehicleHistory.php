<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Models\VehicleMileageLog;
use App\Models\VehicleStatusLog;
use Carbon\CarbonImmutable;

/**
 * Historique consolidé d'un véhicule (B-06), du plus récent au plus ancien :
 * changements de statut, relevés de kilométrage, documents enregistrés. Les contrats
 * (phase 5), maintenances, sinistres et dépenses (phase 8) s'y ajouteront ; chaque
 * source garde sa propre table, rien n'est recopié.
 *
 * Lecture seule ; l'appelant a déjà vérifié le droit de voir le véhicule.
 */
final class VehicleHistory
{
    public const LIMIT = 100;

    /**
     * @return list<array{at: CarbonImmutable, kind: string, title: string, detail: string|null, by: string|null}>
     */
    public function for(Vehicle $vehicle, int $limit = self::LIMIT): array
    {
        $entries = [];

        $statuses = VehicleStatusLog::query()->with('user')
            ->where('vehicle_id', $vehicle->id)
            ->latest('occurred_at')->latest('id')->limit($limit)->get();

        foreach ($statuses as $log) {
            $entries[] = [
                'at' => $log->occurred_at,
                'kind' => 'Statut',
                'title' => $log->from_status === null
                    ? "Création : {$log->to_status->label()}"
                    : "{$log->from_status->label()} → {$log->to_status->label()}",
                'detail' => $log->reason,
                'by' => $log->user?->name,
            ];
        }

        $readings = VehicleMileageLog::query()->with('user')
            ->where('vehicle_id', $vehicle->id)
            ->latest('recorded_at')->latest('id')->limit($limit)->get();

        foreach ($readings as $log) {
            $entries[] = [
                'at' => $log->recorded_at,
                'kind' => $log->is_correction ? 'Correction du kilométrage' : 'Kilométrage',
                'title' => $log->is_correction
                    ? self::km($log->previous_mileage).' → '.self::km($log->mileage)
                    : self::km($log->mileage),
                'detail' => $log->reason,
                'by' => $log->user?->name,
            ];
        }

        $documents = VehicleDocument::query()
            ->where('vehicle_id', $vehicle->id)
            ->latest('created_at')->latest('id')->limit($limit)->get();

        foreach ($documents as $document) {
            $entries[] = [
                'at' => CarbonImmutable::instance($document->created_at ?? CarbonImmutable::now()),
                'kind' => 'Document',
                'title' => $document->type->label(),
                'detail' => $document->valid_until !== null
                    ? 'Échéance : '.$document->valid_until->format('d/m/Y')
                    : null,
                'by' => null,
            ];
        }

        usort($entries, fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        return array_slice($entries, 0, $limit);
    }

    private static function km(int $value): string
    {
        return number_format($value, 0, ',', ' ').' km';
    }
}
