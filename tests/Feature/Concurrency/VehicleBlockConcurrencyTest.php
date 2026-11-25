<?php

use App\Domain\Availability\BlockRegistry;
use App\Domain\Availability\Period;
use App\Models\Agency;
use App\Models\Vehicle;
use App\Models\VehicleBlock;
use App\Models\VehicleCategory;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Fixtures\Models\AgencyDocument;

/*
 * RG-02 (plan de la phase 4, §5.1, lot L9) : occupations d'un véhicule saisies en
 * même temps par des processus PHP distincts, chacun avec sa connexion MariaDB.
 * Une seule aboutit ; la seconde attend le verrou, puis voit la première.
 * Le scénario 1 est rejoué au niveau de la réservation au lot L11.
 */

uses(DatabaseTruncation::class);

afterEach(function () {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    foreach (['vehicle_blocks', 'test_agency_documents', 'vehicle_status_logs', 'vehicles', 'vehicle_categories', 'agencies'] as $table) {
        DB::table($table)->delete();
    }
});

beforeEach(function () {
    $this->agency = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->vehicle = Vehicle::factory()->create([
        'agency_id' => $this->agency->id,
        'vehicle_category_id' => VehicleCategory::factory()->create()->id,
    ]);
});

function startBlockWorker(string $from, string $to, string $mode = 'new', int $waitMs = 0): Process
{
    $process = new Process(
        [PHP_BINARY, base_path('tests/Support/block_worker.php'), (string) test()->vehicle->id, (string) test()->agency->id, $from, $to, '60', $mode, (string) $waitMs],
        base_path(),
        ['APP_ENV' => 'testing'],
        null,
        120,
    );
    $process->start();

    return $process;
}

/**
 * @return array{ok: bool, block?: int, error?: string, finished_at: float}
 */
function blockWorkerResult(Process $process): array
{
    $process->wait();

    expect($process->isSuccessful())->toBeTrue('Processus en échec : '.$process->getErrorOutput().$process->getOutput());

    $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));

    /** @var array{ok: bool, block?: int, error?: string, finished_at: float} */
    return json_decode((string) end($lines), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @param  list<array{ok: bool}>  $results
 */
function succeeded(array $results): int
{
    return count(array_filter($results, fn (array $result): bool => $result['ok']));
}

it('n\'accepte qu\'une occupation quand deux processus saisissent le même véhicule en même temps', function () {
    $workers = [
        startBlockWorker('2026-12-03 09:00', '2026-12-05 10:00'),
        startBlockWorker('2026-12-04 09:00', '2026-12-06 10:00'),
    ];

    $results = array_map(blockWorkerResult(...), $workers);

    expect(succeeded($results))->toBe(1)
        ->and(VehicleBlock::query()->where('active', true)->count())->toBe(1)
        ->and(collect($results)->firstWhere('ok', false)['error'] ?? '')->toContain('vient d\'être réservé');
});

it('fait attendre la seconde saisie tant que la première tient le verrou, puis la refuse', function () {
    // Le processus du test joue la première saisie, transaction ouverte.
    DB::beginTransaction();
    $source = AgencyDocument::query()->create(['agency_id' => $this->agency->id, 'title' => 'Première']);
    app(BlockRegistry::class)->occupy($this->vehicle->id, Period::between('2026-12-03 09:00', '2026-12-05 10:00'), $source, $this->agency->id, 60);

    $worker = startBlockWorker('2026-12-04 09:00', '2026-12-06 10:00');
    usleep(2_000_000);
    $stillWaiting = $worker->isRunning();

    DB::commit();
    $committedAt = microtime(true);

    $result = blockWorkerResult($worker);

    expect($stillWaiting)->toBeTrue('La seconde saisie n\'a pas attendu le verrou de la première.')
        ->and($result['finished_at'])->toBeGreaterThanOrEqual($committedAt)
        ->and($result['ok'])->toBeFalse()
        ->and(VehicleBlock::query()->where('active', true)->count())->toBe(1);
});

it('ne laisse passer qu\'une occupation sur une rafale de dix processus', function () {
    $workers = [];
    foreach (range(0, 9) as $i) {
        $workers[] = startBlockWorker('2026-12-03 09:00', '2026-12-05 10:00');
    }

    $results = array_map(blockWorkerResult(...), $workers);

    expect(succeeded($results))->toBe(1)
        ->and(VehicleBlock::query()->where('active', true)->count())->toBe(1);
});

it('départage un déplacement et une nouvelle saisie sur la même période', function () {
    $existing = AgencyDocument::query()->create(['agency_id' => $this->agency->id, 'title' => 'Existante']);
    app(BlockRegistry::class)->occupy($this->vehicle->id, Period::between('2026-12-01 09:00', '2026-12-02 10:00'), $existing, $this->agency->id, 60);

    $workers = [
        startBlockWorker('2026-12-10 09:00', '2026-12-12 10:00', 'move:'.$existing->id),
        startBlockWorker('2026-12-11 09:00', '2026-12-13 10:00'),
    ];

    $results = array_map(blockWorkerResult(...), $workers);

    $overlapping = BlockRegistry::overlapping(
        $this->vehicle->id,
        Period::between('2026-12-10 09:00', '2026-12-13 10:00')->startsAt,
        Period::between('2026-12-10 09:00', '2026-12-13 10:00')->endsWithBuffer(60),
    )->count();

    expect(succeeded($results))->toBe(1)
        ->and($overlapping)->toBe(1);
});

it('voit l\'occupation validée entre-temps, même si sa transaction a lu avant de prendre le verrou', function () {
    $worker = startBlockWorker('2026-12-04 09:00', '2026-12-06 10:00', 'stale', 2500);

    // Attend que le processus ait fixé son instantané (première lecture).
    $deadline = microtime(true) + 30;
    while (! str_contains($worker->getOutput(), 'READY') && microtime(true) < $deadline) {
        usleep(50_000);
    }
    expect($worker->getOutput())->toContain('READY');

    // Pendant son attente, une autre saisie prend la période et la valide.
    $source = AgencyDocument::query()->create(['agency_id' => $this->agency->id, 'title' => 'Entre-temps']);
    app(BlockRegistry::class)->occupy($this->vehicle->id, Period::between('2026-12-03 09:00', '2026-12-05 10:00'), $source, $this->agency->id, 60);

    $result = blockWorkerResult($worker);

    expect($result['ok'])->toBeFalse()
        ->and(VehicleBlock::query()->where('active', true)->count())->toBe(1);
});
