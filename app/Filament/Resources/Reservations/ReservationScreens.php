<?php

namespace App\Filament\Resources\Reservations;

use App\Domain\Approvals\Exceptions\ApprovalException;
use App\Domain\Availability\AvailabilityQuery;
use App\Domain\Availability\Exceptions\InvalidPeriod;
use App\Domain\Availability\Exceptions\ReservationException;
use App\Domain\Availability\Exceptions\VehicleUnavailable;
use App\Domain\Availability\Period;
use App\Domain\Crm\Exceptions\CrmException;
use App\Domain\Crm\Exceptions\CustomerBlocked;
use App\Domain\Fleet\Exceptions\VehicleNotRentable;
use App\Domain\Pricing\Exceptions\QuoteUnavailable;
use App\Domain\Rental\Exceptions\ContractException;
use App\Enums\VehicleDocumentType;
use Closure;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Throwable;

/**
 * Aides communes aux écrans des réservations : refus métier affichés en notification
 * (la saisie reste à l'écran), véhicules disponibles pour une sélection. Aucune règle
 * ici : tout est décidé par le domaine.
 */
final class ReservationScreens
{
    /** Refus métier affichables tels quels, sans donnée client. */
    private const BUSINESS_REFUSALS = [
        ReservationException::class, VehicleUnavailable::class, VehicleNotRentable::class, QuoteUnavailable::class,
        CustomerBlocked::class, InvalidPeriod::class, CrmException::class, ApprovalException::class, ContractException::class,
    ];

    /**
     * @template T
     *
     * @param  Closure(): T  $action
     * @return T
     *
     * @throws Halt
     */
    public static function run(Closure $action): mixed
    {
        try {
            return $action();
        } catch (Throwable $exception) {
            foreach (self::BUSINESS_REFUSALS as $refusal) {
                if ($exception instanceof $refusal) {
                    Notification::make()->danger()->title($exception->getMessage())->persistent()->send();

                    throw (new Halt)->rollBackDatabaseTransaction();
                }
            }

            throw $exception;
        }
    }

    /**
     * Véhicules disponibles pour la sélection : libellé, documents manquants signalés
     * (le domaine refusera l'affectation).
     *
     * @return array<int, string>
     */
    public static function vehicleOptions(mixed $agencyId, mixed $categoryId, mixed $startsAt, mixed $endsAt): array
    {
        if (! is_numeric($agencyId) || ! is_numeric($categoryId) || ! is_string($startsAt) || ! is_string($endsAt) || $startsAt === '' || $endsAt === '') {
            return [];
        }

        try {
            $period = Period::between($startsAt, $endsAt);
        } catch (InvalidPeriod) {
            return [];
        }

        $options = [];
        foreach (app(AvailabilityQuery::class)->vehicles((int) $agencyId, (int) $categoryId, $period) as $vehicle) {
            $missing = array_map(fn (VehicleDocumentType $type): string => mb_strtolower($type->label()), $vehicle['missing_documents']);
            $options[$vehicle['vehicle_id']] = $vehicle['label'].($missing === [] ? '' : ' — '.implode(' et ', $missing).' non valide au départ');
        }

        return $options;
    }
}
