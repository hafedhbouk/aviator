<?php

/*
 * Processus PHP distinct, lancé par CreditNoteConcurrencyTest : sa propre application
 * Laravel, sa propre connexion MariaDB à aviator_test. Crée un avoir partiel et affiche
 * le résultat en JSON sur sa dernière ligne.
 *
 * Usage : php credit_note_worker.php <utilisateur> <facture> <ligne> <montant HT>
 */

use App\Domain\Billing\Actions\CreateCreditNote;
use App\Domain\Billing\Exceptions\CreditNoteException;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $userId, $invoiceId, $lineId, $amount] = $argv;

$actor = User::query()->findOrFail((int) $userId);
Auth::login($actor);
$invoice = Invoice::query()->withoutGlobalScopes()->findOrFail((int) $invoiceId);

try {
    $note = $app->make(CreateCreditNote::class)->handle($invoice, [(int) $lineId => $amount], 'Avoir partiel simultané', $actor);

    echo json_encode(['ok' => true, 'number' => $note->number], JSON_THROW_ON_ERROR), PHP_EOL;
} catch (CreditNoteException $exception) {
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR), PHP_EOL;
}
