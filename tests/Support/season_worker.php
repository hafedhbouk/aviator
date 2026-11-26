<?php

/*
 * Processus PHP distinct, lancé par SeasonConcurrencyTest : il démarre sa propre
 * application Laravel (sa propre connexion MariaDB à aviator_test) et tente
 * d'enregistrer une saison. Il affiche le résultat et sa durée en JSON.
 *
 * Usage : php season_worker.php <id de l'utilisateur> <nom> <début AAAA-MM-JJ> <fin AAAA-MM-JJ>
 */

use App\Domain\Setup\Actions\SaveSeason;
use App\Domain\Setup\Exceptions\SetupException;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $userId, $name, $startsOn, $endsOn] = $argv;

$actor = User::query()->findOrFail((int) $userId);
$started = hrtime(true);

try {
    $season = $app->make(SaveSeason::class)->handle(null, [
        'name' => $name,
        'starts_on' => $startsOn,
        'ends_on' => $endsOn,
    ], $actor);

    echo json_encode(['ok' => true, 'id' => $season->id, 'ms' => intdiv(hrtime(true) - $started, 1_000_000), 'finished_at' => microtime(true)], JSON_THROW_ON_ERROR);
} catch (SetupException $exception) {
    echo json_encode(['ok' => false, 'error' => $exception->getMessage(), 'ms' => intdiv(hrtime(true) - $started, 1_000_000), 'finished_at' => microtime(true)], JSON_THROW_ON_ERROR);
}
