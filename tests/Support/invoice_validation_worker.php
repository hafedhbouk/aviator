<?php

/*
 * Processus PHP distinct, lancé par InvoiceValidationConcurrencyTest : sa propre
 * application Laravel, sa propre connexion MariaDB à aviator_test. Valide un brouillon
 * de facture et affiche le résultat en JSON sur sa dernière ligne.
 *
 * Usage : php invoice_validation_worker.php <utilisateur> <facture>
 */

use App\Domain\Billing\Actions\ValidateInvoice;
use App\Domain\Billing\Exceptions\InvoiceValidationException;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $userId, $invoiceId] = $argv;

$actor = User::query()->findOrFail((int) $userId);
Auth::login($actor);
$invoice = Invoice::query()->withoutGlobalScopes()->findOrFail((int) $invoiceId);

try {
    $validated = $app->make(ValidateInvoice::class)->handle($invoice, $actor);

    echo json_encode(['ok' => true, 'number' => $validated->number], JSON_THROW_ON_ERROR), PHP_EOL;
} catch (InvoiceValidationException $exception) {
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR), PHP_EOL;
}
