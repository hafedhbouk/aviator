<?php

/*
 * Processus PHP distinct, lancé par ApprovalConcurrencyTest : sa propre application
 * Laravel, sa propre connexion MariaDB à aviator_test. Décide d'une demande
 * d'approbation et affiche le résultat en JSON sur sa dernière ligne.
 *
 * Usage : php approval_worker.php <utilisateur> <demande> <approve|reject>
 */

use App\Domain\Approvals\Actions\DecideApproval;
use App\Domain\Approvals\Exceptions\ApprovalException;
use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $userId, $requestId, $decision] = $argv;

$actor = User::query()->findOrFail((int) $userId);
$request = ApprovalRequest::query()->withoutGlobalScopes()->findOrFail((int) $requestId);
$decide = $app->make(DecideApproval::class);

try {
    $decided = $decision === 'approve'
        ? $decide->approve($request, $actor)
        : $decide->reject($request, $actor, 'Refus en concurrence (test).');

    echo json_encode(['ok' => true, 'status' => $decided->status->value], JSON_THROW_ON_ERROR), PHP_EOL;
} catch (ApprovalException $exception) {
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR), PHP_EOL;
}
