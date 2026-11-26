<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Actions;

use App\Domain\Fleet\Exceptions\FleetException;
use App\Domain\Fleet\MileageLedger;
use App\Enums\VehicleStatus;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMileageLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Relevé de kilométrage (B-03) : départ et retour d'un contrat (phase 5), relevé
 * initial à la création, relevé ponctuel par le parc.
 *
 * - Jamais en baisse : une valeur inférieure au dernier relevé est refusée, la baisse
 *   passe par une correction motivée (CorrectMileage). Une valeur égale est enregistrée.
 * - Jamais dans le futur, jamais antérieur au dernier relevé : l'historique reste
 *   chronologique.
 * - Sans document source (relevé ponctuel), réservé à fleet.manage ; avec une source
 *   (contrat), l'action qui l'appelle a déjà contrôlé les droits.
 * - Sous verrou de la ligne du véhicule ; relevé en ajout seul.
 */
final class RecordMileage
{
    public const MAX_MILEAGE = 2_000_000;

    public function __construct(private readonly MileageLedger $ledger) {}

    public function handle(
        Vehicle $vehicle,
        int $mileage,
        ?User $actor,
        ?Model $source = null,
        ?CarbonImmutable $recordedAt = null,
        ?string $reason = null,
    ): VehicleMileageLog {
        if ($source === null && ($actor === null || ! $actor->can('update', $vehicle))) {
            throw FleetException::notAllowed('le kilométrage');
        }

        if ($mileage < 0 || $mileage > self::MAX_MILEAGE) {
            throw FleetException::invalidMileage(self::MAX_MILEAGE);
        }

        $recordedAt ??= CarbonImmutable::now();

        if ($recordedAt->isFuture()) {
            throw FleetException::mileageInFuture();
        }

        return DB::transaction(function () use ($vehicle, $mileage, $actor, $source, $recordedAt, $reason): VehicleMileageLog {
            $locked = Vehicle::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($vehicle->id);

            if ($locked->status === VehicleStatus::Retired) {
                throw FleetException::vehicleRetired($locked->label());
            }

            if ($mileage < $locked->mileage) {
                throw FleetException::mileageDecrease($locked->mileage, $mileage);
            }

            $last = $this->ledger->lastRecordedAt($locked);
            if ($last !== null && $recordedAt->lessThan($last)) {
                throw FleetException::mileageBeforeLastReading($last->timezone(config('app.timezone'))->format('d/m/Y H:i'));
            }

            $log = $this->ledger->write($locked, $mileage, $actor, $recordedAt, reason: $reason, source: $source);
            $vehicle->setRawAttributes($locked->getAttributes(), true);

            return $log;
        });
    }
}
