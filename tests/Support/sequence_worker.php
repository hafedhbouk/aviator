<?php

/*
 * Processus PHP distinct, lancé par SequenceConcurrencyTest : il démarre sa propre
 * application Laravel, donc sa propre connexion MariaDB à aviator_test, et attribue
 * des numéros. Il affiche le résultat en JSON sur la sortie standard.
 *
 * Usage : php sequence_worker.php <préfixe> <nombre> <date ISO>
 */

use App\Support\Sequences\SequenceAllocator;
use App\Support\Sequences\SequenceDefinition;
use App\Support\Sequences\SequencePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $prefix, $count, $date] = $argv;

$definition = new SequenceDefinition($prefix, SequencePeriod::Year, 5);
$at = CarbonImmutable::parse($date);
$allocator = $app->make(SequenceAllocator::class);
$numbers = [];

for ($i = 0; $i < (int) $count; $i++) {
    // Une transaction par numéro, comme un document enregistré avec son numéro.
    $numbers[] = DB::transaction(fn (): string => $allocator->next($definition, $at));
}

echo json_encode([
    'pid' => getmypid(),
    'connection_id' => (int) DB::scalar('SELECT CONNECTION_ID()'),
    'numbers' => $numbers,
    'finished_at' => microtime(true),
], JSON_THROW_ON_ERROR);
