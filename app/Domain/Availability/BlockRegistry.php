<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use App\Domain\Availability\Exceptions\VehicleUnavailable;
use App\Enums\VehicleStatus;
use App\Models\VehicleBlock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Seul point d'écriture des occupations de véhicule (D1, RG-01, RG-02).
 *
 * Séquence, dans une transaction : verrou de la ligne du véhicule ; refus d'un véhicule
 * sorti du parc ; recherche d'une occupation active qui chevauche
 * `[départ, retour + délai de préparation)` ; écriture. La seconde saisie simultanée
 * attend le verrou, puis voit la première et reçoit VehicleUnavailable.
 *
 * Bornes : occupation existante `[a, b)` (b = retour + délai), demande `[s, e + délai)` ;
 * chevauchement si `a < e + délai` et `b > s`. Un véhicule prêt à 11:00 peut donc
 * repartir à 11:00, pas à 10:59.
 *
 * Une source (réservation, contrat…) porte au plus une occupation : occuper de nouveau
 * déplace la sienne, sans se compter elle-même. Libérer la désactive, sans suppression.
 */
final class BlockRegistry
{
    private static bool $writing = false;

    public function __construct(private readonly VehicleLock $lock) {}

    public static function isWriting(): bool
    {
        return self::$writing;
    }

    /**
     * @throws VehicleUnavailable
     */
    public function occupy(int $vehicleId, Period $period, Model $source, int $agencyId, int $bufferMinutes): VehicleBlock
    {
        return DB::transaction(function () use ($vehicleId, $period, $source, $agencyId, $bufferMinutes): VehicleBlock {
            $vehicle = $this->lock->vehicle($vehicleId);

            if ($vehicle->status === VehicleStatus::Retired) {
                throw VehicleUnavailable::retired($vehicle->label());
            }

            $existing = VehicleBlock::query()
                ->where('source_type', $source->getMorphClass())
                ->where('source_id', $source->getKey())
                ->lockForUpdate()
                ->first();

            $endsAt = $period->endsWithBuffer($bufferMinutes);

            // Lecture verrouillante : elle voit la dernière version validée, même si la
            // transaction appelante avait déjà lu avant de prendre le verrou du véhicule
            // (instantané de REPEATABLE READ ; test « instantané ancien »).
            $overlapping = self::overlapping($vehicleId, $period->startsAt, $endsAt)
                ->when($existing !== null, fn (Builder $query) => $query->whereKeyNot($existing?->id))
                ->sharedLock()
                ->exists();

            if ($overlapping) {
                throw VehicleUnavailable::overlapping($vehicle->label());
            }

            $block = $existing ?? new VehicleBlock([
                'source_type' => $source->getMorphClass(),
                'source_id' => $source->getKey(),
            ]);

            $block->fill([
                'vehicle_id' => $vehicleId,
                'agency_id' => $agencyId,
                'starts_at' => $period->startsAt,
                'ends_at' => $endsAt,
                'buffer_minutes' => max(0, $bufferMinutes),
                'active' => true,
                'released_at' => null,
            ]);

            $this->write(fn () => $block->save());

            return $block;
        });
    }

    /**
     * Libère l'occupation de la source (annulation, expiration, retour clôturé).
     *
     * @return bool vrai si une occupation active a été libérée
     */
    public function release(Model $source): bool
    {
        return DB::transaction(function () use ($source): bool {
            $block = VehicleBlock::query()
                ->where('source_type', $source->getMorphClass())
                ->where('source_id', $source->getKey())
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if ($block === null) {
                return false;
            }

            $this->lock->vehicle($block->vehicle_id);
            $block->fill(['active' => false, 'released_at' => CarbonImmutable::now()]);
            $this->write(fn () => $block->save());

            return true;
        });
    }

    /**
     * Occupations actives d'un véhicule qui chevauchent `[startsAt, endsAt)`.
     *
     * @return Builder<VehicleBlock>
     */
    public static function overlapping(int $vehicleId, CarbonImmutable $startsAt, CarbonImmutable $endsAt): Builder
    {
        return VehicleBlock::query()
            ->where('vehicle_id', $vehicleId)
            ->where('active', true)
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt);
    }

    private function write(callable $save): void
    {
        self::$writing = true;

        try {
            $save();
        } finally {
            self::$writing = false;
        }
    }
}
