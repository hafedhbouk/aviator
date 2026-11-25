<?php

/*
 * Processus PHP distinct, lancé par ReservationConcurrencyTest : sa propre application
 * Laravel, sa propre connexion MariaDB à aviator_test. Tente une réservation et affiche
 * le résultat en JSON sur sa dernière ligne.
 *
 * Usage : php reservation_worker.php <utilisateur> <client> <agence> <catégorie> <véhicule|none> <départ> <retour> <mode> [<attente ms>]
 *   mode « new »   : réservation simple ;
 *   mode « stale » : la transaction appelante lit d'abord (instantané), affiche READY, attend, puis réserve.
 */

use App\Domain\Availability\Actions\SaveReservation;
use App\Domain\Availability\Exceptions\ReservationException;
use App\Domain\Availability\Exceptions\VehicleUnavailable;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $userId, $customerId, $agencyId, $categoryId, $vehicleId, $startsAt, $endsAt, $mode] = $argv;
$waitMs = (int) ($argv[9] ?? 0);

$actor = User::query()->findOrFail((int) $userId);
$data = [
    'customer_id' => (int) $customerId,
    'agency_id' => (int) $agencyId,
    'vehicle_category_id' => (int) $categoryId,
    'vehicle_id' => $vehicleId === 'none' ? null : (int) $vehicleId,
    'starts_at' => $startsAt,
    'ends_at' => $endsAt,
];
$save = $app->make(SaveReservation::class);

try {
    if ($mode === 'stale') {
        $reservation = DB::transaction(function () use ($save, $data, $actor, $waitMs): Reservation {
            Reservation::query()->withoutGlobalScopes()->count(); // première lecture : fixe l'instantané
            fwrite(STDOUT, "READY\n");
            fflush(STDOUT);
            usleep($waitMs * 1000);

            return $save->handle(null, $data, $actor);
        });
    } else {
        $reservation = $save->handle(null, $data, $actor);
    }

    echo json_encode(['ok' => true, 'number' => $reservation->number], JSON_THROW_ON_ERROR), PHP_EOL;
} catch (ReservationException|VehicleUnavailable $exception) {
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR), PHP_EOL;
}
