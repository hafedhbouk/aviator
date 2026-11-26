<?php

declare(strict_types=1);

namespace App\Domain\Availability\Actions;

use App\Domain\Availability\BlockRegistry;
use App\Domain\Availability\Exceptions\ReservationException;
use App\Domain\Fleet\ReservedStatusSync;
use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use App\Settings\ReservationSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Fin d'une réservation sans contrat : annulation avec motif obligatoire (C-03),
 * non-présentation (Q39 : proposée après `reservations.no_show_after_minutes`,
 * confirmée par l'agence), expiration (C-09, tâche planifiée). Le véhicule et la
 * capacité sont libérés (scénario 2) ; la réservation est conservée (RG-11).
 */
final class CloseReservation
{
    public const MIN_REASON_LENGTH = 5;

    public function __construct(
        private readonly BlockRegistry $blocks,
        private readonly ReservedStatusSync $reserved,
        private readonly ReservationSettings $settings,
    ) {}

    public function cancel(Reservation $reservation, string $reason, User $actor): Reservation
    {
        if (! $actor->can('update', $reservation)) {
            throw ReservationException::notAllowed('cette réservation');
        }

        $reason = trim($reason);
        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            throw ReservationException::reasonRequired(self::MIN_REASON_LENGTH);
        }

        return $this->close($reservation, ReservationStatus::Cancelled, 'annulation', [
            'cancellation_reason' => $reason,
            'cancelled_at' => CarbonImmutable::now(),
        ]);
    }

    public function markNoShow(Reservation $reservation, User $actor, ?CarbonImmutable $now = null): Reservation
    {
        if (! $actor->can('update', $reservation)) {
            throw ReservationException::notAllowed('cette réservation');
        }

        $from = $reservation->starts_at->addMinutes($this->settings->no_show_after_minutes);

        if ($reservation->status === ReservationStatus::Confirmed && ($now ?? CarbonImmutable::now())->lessThan($from)) {
            throw ReservationException::noShowTooEarly($from->timezone((string) config('app.timezone'))->format('d/m/Y à H:i'));
        }

        return $this->close($reservation, ReservationStatus::NoShow, 'non-présentation', [], [ReservationStatus::Confirmed]);
    }

    /**
     * Expiration automatique (C-09) : brouillons et réservations en attente dont
     * l'échéance est passée. Idempotent.
     *
     * @return int nombre de réservations expirées
     */
    public function expireDue(?CarbonImmutable $now = null): int
    {
        $count = 0;

        Reservation::query()->withoutGlobalScopes()
            ->whereIn('status', [ReservationStatus::Draft->value, ReservationStatus::Pending->value])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now ?? CarbonImmutable::now())
            ->orderBy('id')
            ->each(function (Reservation $reservation) use (&$count): void {
                $this->close($reservation, ReservationStatus::Expired, 'expiration');
                $count++;
            });

        return $count;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  list<ReservationStatus>|null  $from
     */
    private function close(Reservation $reservation, ReservationStatus $to, string $action, array $extra = [], ?array $from = null): Reservation
    {
        $vehicleId = DB::transaction(function () use ($reservation, $to, $action, $extra, $from): ?int {
            $locked = Reservation::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($reservation->id);
            $allowed = $from ?? ReservationStatus::open();

            if (! in_array($locked->status, $allowed, true)) {
                throw ReservationException::invalidStatus($locked->status->label(), $action);
            }

            $locked->fill(['status' => $to] + $extra);
            $locked->save();

            $this->blocks->release($locked);

            return $locked->vehicle_id;
        });

        if ($vehicleId !== null) {
            $this->reserved->sync(Vehicle::query()->withoutGlobalScopes()->findOrFail($vehicleId));
        }

        return $reservation->refresh();
    }
}
