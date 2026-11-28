<?php

/*
 * Processus PHP distinct, lancé par PaymentAllocationConcurrencyTest : sa propre
 * application Laravel, sa propre connexion MariaDB à aviator_test. Enregistre un
 * encaissement imputé sur une facture et affiche le résultat en JSON sur sa dernière
 * ligne.
 *
 * Usage : php payment_worker.php <utilisateur> <agence> <client> <mode> <facture> <montant>
 */

use App\Domain\Billing\Exceptions\PaymentException;
use App\Domain\Billing\Payments\RecordPayment;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $userId, $agencyId, $customerId, $methodId, $invoiceId, $amount] = $argv;

$actor = User::query()->findOrFail((int) $userId);
Auth::login($actor);

try {
    $payment = $app->make(RecordPayment::class)->handle([
        'agency_id' => (int) $agencyId, 'customer_id' => (int) $customerId, 'payment_method_id' => (int) $methodId,
        'amount' => $amount, 'allocations' => [(int) $invoiceId => $amount],
    ], $actor);

    echo json_encode(['ok' => true, 'id' => $payment->id], JSON_THROW_ON_ERROR), PHP_EOL;
} catch (PaymentException $exception) {
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR), PHP_EOL;
}
