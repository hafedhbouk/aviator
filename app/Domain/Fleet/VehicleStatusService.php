<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Fleet\Exceptions\IllegalVehicleTransition;
use App\Enums\VehicleStatus;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleStatusLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Seul point d'écriture du statut d'un véhicule (B-09, architecture §7).
 *
 * - Machine à états du cahier §5.1, plus les cinq transitions de Q29 (valeur par
 *   défaut, à confirmer) ; toute autre transition lève IllegalVehicleTransition.
 * - Le passage à « Loué » exige une assurance et une visite technique valides à la
 *   date de départ (DepartureGuard, scénario 10).
 * - Chaque transition est écrite dans vehicle_status_logs (ajout seul), sous verrou
 *   de la ligne du véhicule.
 * - Le modèle Vehicle refuse toute écriture du statut hors de ce service.
 */
final class VehicleStatusService
{
    /** @var array<string, list<VehicleStatus>> */
    private const TRANSITIONS = [
        'available' => [VehicleStatus::Reserved, VehicleStatus::Rented, VehicleStatus::Maintenance, VehicleStatus::Unavailable, VehicleStatus::Retired],
        'reserved' => [VehicleStatus::Available, VehicleStatus::Rented, VehicleStatus::Maintenance, VehicleStatus::Unavailable],
        'rented' => [VehicleStatus::Available, VehicleStatus::Maintenance, VehicleStatus::Unavailable],
        'maintenance' => [VehicleStatus::Available, VehicleStatus::Unavailable, VehicleStatus::Retired],
        'unavailable' => [VehicleStatus::Available, VehicleStatus::Maintenance, VehicleStatus::Retired],
        'retired' => [],
    ];

    private static bool $writing = false;

    public function __construct(private readonly DepartureGuard $departureGuard) {}

    public static function allows(VehicleStatus $from, VehicleStatus $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from->value], true);
    }

    /**
     * Vrai pendant l'écriture du statut par ce service (contrôle du modèle Vehicle).
     */
    public static function isWriting(): bool
    {
        return self::$writing;
    }

    /**
     * @param  Model|null  $cause  opération à l'origine du changement (réservation, contrat…)
     * @param  CarbonImmutable|null  $departureAt  date de départ, pour le passage à « Loué »
     *
     * @throws IllegalVehicleTransition
     */
    public function transition(
        Vehicle $vehicle,
        VehicleStatus $to,
        ?Model $cause = null,
        ?User $actor = null,
        ?string $reason = null,
        ?CarbonImmutable $departureAt = null,
    ): Vehicle {
        return DB::transaction(function () use ($vehicle, $to, $cause, $actor, $reason, $departureAt): Vehicle {
            $locked = Vehicle::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($vehicle->id);
            $from = $locked->status;

            if ($from === $to) {
                return $locked;
            }

            if (! self::allows($from, $to)) {
                throw IllegalVehicleTransition::between($from, $to);
            }

            if ($to === VehicleStatus::Rented) {
                $this->departureGuard->assertCanDepart($locked, $departureAt ?? CarbonImmutable::now());
            }

            $this->write($locked, $to);

            VehicleStatusLog::query()->create([
                'vehicle_id' => $locked->id,
                'from_status' => $from,
                'to_status' => $to,
                'cause_type' => $cause?->getMorphClass(),
                'cause_id' => $cause?->getKey(),
                'reason' => $reason,
                'user_id' => $actor?->id,
                'occurred_at' => CarbonImmutable::now(),
            ]);

            $vehicle->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    /**
     * Première ligne de l'historique, à la création du véhicule.
     */
    public function recordCreation(Vehicle $vehicle, ?User $actor): void
    {
        VehicleStatusLog::query()->create([
            'vehicle_id' => $vehicle->id,
            'from_status' => null,
            'to_status' => $vehicle->status,
            'reason' => 'Création du véhicule',
            'user_id' => $actor?->id,
            'occurred_at' => CarbonImmutable::now(),
        ]);
    }

    private function write(Vehicle $vehicle, VehicleStatus $to): void
    {
        self::$writing = true;

        try {
            $vehicle->forceFill(['status' => $to])->save();
        } finally {
            self::$writing = false;
        }
    }
}
