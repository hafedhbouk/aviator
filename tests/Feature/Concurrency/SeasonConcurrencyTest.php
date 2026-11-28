<?php

use App\Domain\Setup\Actions\SaveSeason;
use App\Models\Season;
use App\Support\Authorization\Role;
use App\Support\Locking\DatabaseLock;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/*
 * Saisons sous saisie simultanée (revue de la phase 3, observation n° 1 ; plan de la
 * phase 4, §5.3, lot L1). Deux processus PHP distincts enregistrent des saisons qui
 * se chevauchent : une seule doit passer.
 */

uses(DatabaseTruncation::class);

afterEach(function () {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    foreach (['activity_log', 'seasons', 'named_locks', 'model_has_roles', 'users'] as $table) {
        DB::table($table)->delete();
    }
});

function startSeasonWorker(int $userId, string $name, string $startsOn, string $endsOn): Process
{
    $process = new Process(
        [PHP_BINARY, base_path('tests/Support/season_worker.php'), (string) $userId, $name, $startsOn, $endsOn],
        base_path(),
        ['APP_ENV' => 'testing'],
        null,
        120,
    );
    $process->start();

    return $process;
}

/**
 * @return array{ok: bool, id?: int, error?: string, ms: int, finished_at: float}
 */
function seasonWorkerResult(Process $process): array
{
    $process->wait();

    expect($process->isSuccessful())->toBeTrue('Processus en échec : '.$process->getErrorOutput().$process->getOutput());

    /** @var array{ok: bool, id?: int, error?: string, ms: int, finished_at: float} */
    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

it('n\'enregistre qu\'une des deux saisons qui se chevauchent, saisies en même temps', function () {
    $admin = userWithProfile(Role::Admin);

    $first = startSeasonWorker($admin->id, 'Été A', '2027-07-01', '2027-08-31');
    $second = startSeasonWorker($admin->id, 'Été B', '2027-08-01', '2027-09-30');

    $results = [seasonWorkerResult($first), seasonWorkerResult($second)];
    $refused = array_values(array_filter($results, fn (array $r): bool => ! $r['ok']));

    expect(Season::query()->count())->toBe(1)
        ->and($refused)->toHaveCount(1)
        ->and($refused[0]['error'] ?? '')->toContain('chevauche');
});

it('fait attendre une seconde saisie tant que la première tient le verrou, puis la refuse', function () {
    $admin = userWithProfile(Role::Admin);

    // Le processus du test joue la première saisie : il ouvre sa transaction et prend
    // le verrou des saisons (D38), comme SaveSeason.
    DB::beginTransaction();
    app(DatabaseLock::class)->acquire(SaveSeason::LOCK);

    $worker = startSeasonWorker($admin->id, 'Été B', '2027-08-01', '2027-09-30');
    usleep(2_000_000);

    $stillWaiting = $worker->isRunning();

    // Fin de la première saisie : saison enregistrée, transaction validée, verrou rendu.
    // L'heure est prise juste avant la validation : MariaDB rend le verrou pendant le
    // COMMIT, et le processus concurrent peut terminer avant que ce processus-ci ait
    // repris la main (relevé en CI : 3,8 ms d'avance sur une heure prise après).
    Season::query()->create(['name' => 'Été A', 'starts_on' => '2027-07-01', 'ends_on' => '2027-08-31']);
    $releasedFrom = microtime(true);
    DB::commit();

    $result = seasonWorkerResult($worker);

    // La seconde saisie attendait encore après 2 s, et ne s'est terminée qu'une fois le
    // verrou rendu : elle a attendu.
    expect($stillWaiting)->toBeTrue('La seconde saisie n\'a pas attendu le verrou de la première.')
        ->and($result['finished_at'])->toBeGreaterThanOrEqual($releasedFrom)
        ->and($result['ok'])->toBeFalse()
        ->and($result['error'] ?? '')->toContain('chevauche')
        ->and(Season::query()->count())->toBe(1);
});
