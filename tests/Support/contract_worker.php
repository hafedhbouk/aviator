<?php

/*
 * Processus PHP distinct, lancé par ContractConcurrencyTest : sa propre application
 * Laravel, sa propre connexion MariaDB à aviator_test. Convertit une réservation en
 * contrat et affiche le résultat en JSON sur sa dernière ligne.
 *
 * Usage : php contract_worker.php <utilisateur> <réservation>
 */

use App\Domain\Availability\Exceptions\ReservationException;
use App\Domain\Availability\Exceptions\VehicleUnavailable;
use App\Domain\Rental\Actions\OpenContract;
use App\Domain\Rental\Exceptions\ContractException;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $userId, $reservationId] = $argv;

$actor = User::query()->findOrFail((int) $userId);
Auth::login($actor);
$reservation = Reservation::query()->withoutGlobalScopes()->findOrFail((int) $reservationId);

try {
    $contract = $app->make(OpenContract::class)->fromReservation($reservation, null, $actor);

    echo json_encode(['ok' => true, 'number' => $contract->number], JSON_THROW_ON_ERROR), PHP_EOL;
} catch (ContractException|ReservationException|VehicleUnavailable $exception) {
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR), PHP_EOL;
}
