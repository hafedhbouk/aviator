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
use Illuminate\Support\Facades\DB;

/**
 * Correction du kilométrage (B-03, « baisse interdite sauf correction tracée ») :
 * super admin et admin (fleet.manage), motif obligatoire d'au moins 10 caractères,
 * valeur différente de l'actuelle, dans les deux sens. Le relevé de correction garde
 * la valeur précédente ; il est journalisé et ne se supprime pas.
 */
final class CorrectMileage
{
    public const MIN_REASON_LENGTH = 10;

    public function __construct(private readonly MileageLedger $ledger) {}

    public function handle(Vehicle $vehicle, int $mileage, string $reason, User $actor): VehicleMileageLog
    {
        if (! $actor->can('update', $vehicle)) {
            throw FleetException::notAllowed('la correction du kilométrage');
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            throw FleetException::correctionReasonRequired(self::MIN_REASON_LENGTH);
        }

        if ($mileage < 0 || $mileage > RecordMileage::MAX_MILEAGE) {
            throw FleetException::invalidMileage(RecordMileage::MAX_MILEAGE);
        }

        return DB::transaction(function () use ($vehicle, $mileage, $reason, $actor): VehicleMileageLog {
            $locked = Vehicle::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($vehicle->id);

            if ($locked->status === VehicleStatus::Retired) {
                throw FleetException::vehicleRetired($locked->label());
            }

            if ($mileage === $locked->mileage) {
                throw FleetException::correctionUnchanged($mileage);
            }

            $log = $this->ledger->write($locked, $mileage, $actor, CarbonImmutable::now(), isCorrection: true, reason: $reason);
            $vehicle->setRawAttributes($locked->getAttributes(), true);

            return $log;
        });
    }
}
