<?php

/*
 * Processus PHP distinct, lancé par ExtensionConcurrencyTest : sa propre application
 * Laravel, sa propre connexion MariaDB à aviator_test. Prolonge un contrat et affiche
 * le résultat en JSON sur sa dernière ligne.
 *
 * Usage : php extension_worker.php <utilisateur> <contrat> <nouvelle fin>
 */

use App\Domain\Availability\Exceptions\VehicleUnavailable;
use App\Domain\Rental\Actions\ExtendContract;
use App\Domain\Rental\Exceptions\ContractException;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $userId, $contractId, $endsAt] = $argv;

$actor = User::query()->findOrFail((int) $userId);
Auth::login($actor);
$contract = Contract::query()->withoutGlobalScopes()->findOrFail((int) $contractId);

try {
    $extension = $app->make(ExtendContract::class)->handle($contract, ['ends_at' => $endsAt], $actor);

    echo json_encode(['ok' => true, 'id' => $extension->id], JSON_THROW_ON_ERROR), PHP_EOL;
} catch (ContractException|VehicleUnavailable $exception) {
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR), PHP_EOL;
}
