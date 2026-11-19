<?php

/*
 * Processus PHP distinct, lancé par TaxRuleConcurrencyTest : il démarre sa propre
 * application Laravel (sa propre connexion MariaDB à aviator_test) et tente d'ajouter
 * une version de la règle RENTAL. Il affiche le résultat en JSON.
 *
 * Usage : php tax_rule_worker.php <id du super admin> <date AAAA-MM-JJ> <taux>
 */

use App\Domain\Tax\Actions\AddTaxRuleVersion;
use App\Domain\Tax\Exceptions\FiscalSetupException;
use App\Models\FiscalReference;
use App\Models\TaxCode;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $userId, $date, $rate] = $argv;

$actor = User::query()->findOrFail((int) $userId);
$code = TaxCode::query()->where('code', 'RENTAL')->firstOrFail();

try {
    $rule = $app->make(AddTaxRuleVersion::class)->handle($code, [
        'rate' => $rate,
        'valid_from' => $date,
        'validation_status' => 'CONFIRMED',
        'fiscal_reference_id' => FiscalReference::query()->value('id'),
    ], $actor);

    echo json_encode(['ok' => true, 'id' => $rule->id], JSON_THROW_ON_ERROR);
} catch (FiscalSetupException $exception) {
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR);
}
