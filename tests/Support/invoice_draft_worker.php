<?php

/*
 * Processus PHP distinct, lancé par InvoiceDraftConcurrencyTest : sa propre application
 * Laravel, sa propre connexion MariaDB à aviator_test. Prépare le brouillon de facture
 * d'un contrat et affiche le résultat en JSON sur sa dernière ligne.
 *
 * Usage : php invoice_draft_worker.php <utilisateur> <contrat>
 */

use App\Domain\Billing\Actions\DraftContractInvoice;
use App\Domain\Billing\Exceptions\InvoiceDraftException;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $userId, $contractId] = $argv;

$actor = User::query()->findOrFail((int) $userId);
Auth::login($actor);
$contract = Contract::query()->withoutGlobalScopes()->findOrFail((int) $contractId);

try {
    $invoice = $app->make(DraftContractInvoice::class)->handle($contract, $actor);

    echo json_encode(['ok' => true, 'id' => $invoice->id], JSON_THROW_ON_ERROR), PHP_EOL;
} catch (InvoiceDraftException $exception) {
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR), PHP_EOL;
}
