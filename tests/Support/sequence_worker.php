<?php

/*
 * Processus PHP distinct, lancé par SequenceConcurrencyTest : il démarre sa propre
 * application Laravel, donc sa propre connexion MariaDB à aviator_test, et attribue
 * des numéros. Il affiche le résultat en JSON sur la sortie standard.
 *
 * Usage : php sequence_worker.php <préfixe> <nombre> <date ISO>
 * Pour une série à format réglable (D27) : préfixe « series:<code> » (exemple : series:invoice).
 */

use App\Support\Sequences\SequenceAllocator;
use App\Support\Sequences\SequenceDefinition;
use App\Support\Sequences\SequencePeriod;
use App\Support\Sequences\Series;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $prefix, $count, $date] = $argv;

$series = str_starts_with($prefix, 'series:') ? Series::from(substr($prefix, 7)) : null;
$definition = $series === null ? new SequenceDefinition($prefix, SequencePeriod::Year, 5) : null;
$at = CarbonImmutable::parse($date);
$allocator = $app->make(SequenceAllocator::class);
$numbers = [];

for ($i = 0; $i < (int) $count; $i++) {
    // Une transaction par numéro, comme un document enregistré avec son numéro.
    $numbers[] = DB::transaction(fn (): string => $series === null
        ? $allocator->next($definition, $at)
        : $allocator->nextInSeries($series, $at));
}

echo json_encode([
    'pid' => getmypid(),
    'connection_id' => (int) DB::scalar('SELECT CONNECTION_ID()'),
    'numbers' => $numbers,
    'finished_at' => microtime(true),
], JSON_THROW_ON_ERROR);
