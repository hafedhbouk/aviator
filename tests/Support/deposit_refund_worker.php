<?php

/*
 * Processus PHP distinct, lancé par DepositConcurrencyTest : sa propre application
 * Laravel, sa propre connexion MariaDB à aviator_test. Restitue une partie d'une caution
 * et affiche le résultat en JSON sur sa dernière ligne.
 *
 * Usage : php deposit_refund_worker.php <utilisateur> <caution> <mode> <montant>
 */

use App\Domain\Billing\Deposits\DepositDesk;
use App\Domain\Billing\Exceptions\DepositException;
use App\Models\Deposit;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $userId, $depositId, $methodId, $amount] = $argv;

$actor = User::query()->findOrFail((int) $userId);
Auth::login($actor);
$deposit = Deposit::query()->withoutGlobalScopes()->findOrFail((int) $depositId);

try {
    $movement = $app->make(DepositDesk::class)->refund($deposit, $amount, ['payment_method_id' => (int) $methodId], $actor);

    echo json_encode(['ok' => true, 'receipt' => $movement->receipt_number], JSON_THROW_ON_ERROR), PHP_EOL;
} catch (DepositException $exception) {
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR), PHP_EOL;
}
