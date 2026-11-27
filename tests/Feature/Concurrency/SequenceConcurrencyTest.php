<?php

use App\Models\Sequence;
use App\Support\Sequences\SequenceAllocator;
use App\Support\Sequences\SequenceDefinition;
use App\Support\Sequences\SequencePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/*
 * Premier test de concurrence (RG-02, préparation de RG-01 et RG-07) : deux
 * processus PHP distincts, chacun avec sa propre connexion MariaDB, attribuent des
 * numéros dans la même séquence. Aucun doublon, aucun trou.
 *
 * Les données doivent être validées (commit) pour être vues des autres processus :
 * ce fichier utilise DatabaseTruncation, pas RefreshDatabase.
 */

uses(DatabaseTruncation::class);

const CONCURRENCY_DATE = '2026-11-18T10:00:00+01:00';

function startWorker(string $prefix, int $count): Process
{
    $process = new Process(
        [PHP_BINARY, base_path('tests/Support/sequence_worker.php'), $prefix, (string) $count, CONCURRENCY_DATE],
        base_path(),
        ['APP_ENV' => 'testing'],
        null,
        120,
    );
    $process->start();

    return $process;
}

/**
 * @return array{pid: int, connection_id: int, numbers: list<string>, finished_at: float}
 */
function workerResult(Process $process): array
{
    $process->wait();

    expect($process->isSuccessful())->toBeTrue('Processus en échec : '.$process->getErrorOutput().$process->getOutput());

    $output = $process->getOutput();

    try {
        /** @var array{pid: int, connection_id: int, numbers: list<string>, finished_at: float} */
        return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new RuntimeException('Sortie illisible du processus : '.bin2hex(substr($output, 0, 120)).' | '.substr($output, 0, 300));
    }
}

afterEach(function () {
    // Compteurs validés (commit) : à retirer, sinon les tests suivants les verraient.
    Sequence::query()->where('key', 'like', 'CONC%')->orWhere('key', 'like', 'series:%')->delete();
});

it('attribue 60 numéros depuis deux processus simultanés, sans doublon ni trou', function () {
    $first = startWorker('CONCA', 30);
    $second = startWorker('CONCA', 30);

    $a = workerResult($first);
    $b = workerResult($second);

    $all = array_merge($a['numbers'], $b['numbers']);
    sort($all);
    $expected = array_map(fn (int $n): string => sprintf('CONCA-2026-%05d', $n), range(1, 60));

    expect($a['pid'])->not->toBe($b['pid'])
        ->and($a['connection_id'])->not->toBe($b['connection_id'])
        ->and(array_unique($all))->toHaveCount(60)
        ->and($all)->toBe($expected)
        ->and(Sequence::query()->where('key', 'CONCA:2026')->value('last_value'))->toBe(60);
});

it('fait attendre un second processus tant que le premier tient le verrou, puis lui donne le numéro suivant', function () {
    $definition = new SequenceDefinition('CONCB', SequencePeriod::Year, 5);
    $at = CarbonImmutable::parse(CONCURRENCY_DATE);

    // Le processus de test (première connexion) attribue un numéro et garde la transaction ouverte.
    DB::beginTransaction();
    $mine = app(SequenceAllocator::class)->next($definition, $at);

    // Le second processus (seconde connexion) demande un numéro : il doit attendre le verrou.
    $other = startWorker('CONCB', 1);
    usleep(1_500_000);

    expect($other->isRunning())->toBeTrue('Le second processus n\'a pas attendu le verrou.');

    $releasedAt = microtime(true);
    DB::commit();

    $result = workerResult($other);

    expect($mine)->toBe('CONCB-2026-00001')
        ->and($result['numbers'])->toBe(['CONCB-2026-00002'])
        ->and($result['finished_at'])->toBeGreaterThanOrEqual($releasedAt);
});

it('ne laisse aucun trou si la transaction qui tient le verrou est annulée', function () {
    $definition = new SequenceDefinition('CONCC', SequencePeriod::Year, 5);
    $at = CarbonImmutable::parse(CONCURRENCY_DATE);
    app(SequenceAllocator::class)->next($definition, $at);

    DB::beginTransaction();
    app(SequenceAllocator::class)->next($definition, $at);
    $other = startWorker('CONCC', 1);
    usleep(1_000_000);
    DB::rollBack();

    expect(workerResult($other)['numbers'])->toBe(['CONCC-2026-00002']);
});

it('numérote factures et avoirs depuis trois processus simultanés : séries indépendantes, sans doublon ni trou (D27)', function () {
    $invoicesA = startWorker('series:invoice', 25);
    $creditNotes = startWorker('series:credit_note', 20);
    $invoicesB = startWorker('series:invoice', 25);

    $a = workerResult($invoicesA);
    $c = workerResult($creditNotes);
    $b = workerResult($invoicesB);

    $invoices = array_merge($a['numbers'], $b['numbers']);
    sort($invoices);

    expect($invoices)->toBe(array_map(fn (int $n): string => sprintf('AVI-%06d', $n), range(1, 50)))
        ->and($c['numbers'])->toBe(array_map(fn (int $n): string => sprintf('AV-%06d', $n), range(1, 20)))
        ->and(Sequence::query()->where('key', 'series:invoice')->value('last_value'))->toBe(50)
        ->and(Sequence::query()->where('key', 'series:credit_note')->value('last_value'))->toBe(20);
});
