<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMileageLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Écriture du kilométrage (B-03) : seul endroit qui modifie `vehicles.mileage`, avec
 * le relevé correspondant. Appelé par RecordMileage et CorrectMileage, toujours dans
 * leur transaction et sur la ligne du véhicule verrouillée.
 */
final class MileageLedger
{
    private static bool $writing = false;

    /**
     * Vrai pendant l'écriture du kilométrage par ce service (contrôle du modèle Vehicle).
     */
    public static function isWriting(): bool
    {
        return self::$writing;
    }

    public function write(
        Vehicle $locked,
        int $mileage,
        ?User $actor,
        CarbonImmutable $recordedAt,
        bool $isCorrection = false,
        ?string $reason = null,
        ?Model $source = null,
    ): VehicleMileageLog {
        $previous = $locked->mileage;

        self::$writing = true;

        try {
            $locked->forceFill(['mileage' => $mileage])->save();
        } finally {
            self::$writing = false;
        }

        return VehicleMileageLog::query()->create([
            'vehicle_id' => $locked->id,
            'previous_mileage' => $previous,
            'mileage' => $mileage,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'is_correction' => $isCorrection,
            'reason' => $reason,
            'user_id' => $actor?->id,
            'recorded_at' => $recordedAt,
        ]);
    }

    /**
     * Date du dernier relevé du véhicule, ou null s'il n'en a aucun.
     */
    public function lastRecordedAt(Vehicle $vehicle): ?CarbonImmutable
    {
        return VehicleMileageLog::query()
            ->where('vehicle_id', $vehicle->id)
            ->latest('recorded_at')->latest('id')
            ->first()?->recorded_at;
    }
}
