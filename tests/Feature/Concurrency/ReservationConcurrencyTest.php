<?php

use App\Domain\Availability\Actions\SaveReservation;
use App\Domain\Setup\Actions\SaveRatePlan;
use App\Domain\Setup\Actions\SaveSeason;
use App\Models\Agency;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Models\VehicleBlock;
use App\Models\VehicleCategory;
use App\Models\VehicleDocument;
use App\Support\Authorization\Role;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/*
 * Scénario 1 (RG-02, plan de la phase 4 §5.1, lot L11) : réservations saisies en même
 * temps par des processus PHP distincts. Une seule aboutit ; aucun numéro en double ni
 * perdu ; la capacité d'une catégorie (Q36) tient sous concurrence.
 */

uses(DatabaseTruncation::class);

afterEach(function () {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    foreach (['vehicle_blocks', 'reservation_options', 'reservations', 'sequences', 'vehicle_status_logs', 'vehicle_documents',
        'vehicles', 'rate_plan_lines', 'rate_plans', 'seasons', 'named_locks', 'customers', 'activity_log', 'model_has_roles',
        'users', 'vehicle_categories', 'agencies'] as $table) {
        DB::table($table)->delete();
    }
});

beforeEach(function () {
    $admin = userWithProfile(Role::Admin);
    $this->agency = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->agent = userWithProfile(Role::AgencyUser, ['agency_id' => $this->agency->id]);
    $this->category = VehicleCategory::factory()->create(['code' => 'CIT', 'name' => 'Citadine', 'preparation_buffer_minutes' => 60]);
    $season = app(SaveSeason::class)->handle(null, ['name' => 'Année 2030', 'starts_on' => '2030-01-01', 'ends_on' => '2030-12-31'], $admin);
    app(SaveRatePlan::class)->handle(null, [
        'vehicle_category_id' => $this->category->id, 'season_id' => $season->id, 'agency_id' => null,
        'valid_from' => '2030-01-01', 'valid_to' => null, 'is_active' => true,
        'lines' => [['min_days' => 1, 'max_days' => null, 'daily_amount' => '100,000']],
    ], $admin);
    $this->vehicle = concurrencyVehicle();
    $this->customer = Customer::factory()->create(['origin_agency_id' => $this->agency->id]);
});

function concurrencyVehicle(): Vehicle
{
    $vehicle = Vehicle::factory()->create(['agency_id' => test()->agency->id, 'vehicle_category_id' => test()->category->id]);
    foreach (['insurance', 'technical_inspection'] as $type) {
        VehicleDocument::query()->create(['vehicle_id' => $vehicle->id, 'type' => $type, 'valid_from' => '2030-01-01', 'valid_until' => '2030-12-31']);
    }

    return $vehicle;
}

function startReservationWorker(?int $vehicleId, string $from = '2030-06-10 09:00', string $to = '2030-06-13 09:00', string $mode = 'new', int $waitMs = 0): Process
{
    $process = new Process(
        [PHP_BINARY, base_path('tests/Support/reservation_worker.php'), (string) test()->agent->id, (string) test()->customer->id,
            (string) test()->agency->id, (string) test()->category->id, $vehicleId === null ? 'none' : (string) $vehicleId, $from, $to, $mode, (string) $waitMs],
        base_path(),
        ['APP_ENV' => 'testing'],
        null,
        120,
    );
    $process->start();

    return $process;
}

/**
 * @return array{ok: bool, number?: string, error?: string}
 */
function reservationWorkerResult(Process $process): array
{
    $process->wait();

    expect($process->isSuccessful())->toBeTrue('Processus en échec : '.$process->getErrorOutput().$process->getOutput());

    $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));

    /** @var array{ok: bool, number?: string, error?: string} */
    return json_decode((string) end($lines), true, flags: JSON_THROW_ON_ERROR);
}

it('n\'accepte qu\'une des deux réservations simultanées du même véhicule, sans numéro perdu (scénario 1)', function () {
    $results = array_map(reservationWorkerResult(...), [
        startReservationWorker($this->vehicle->id),
        startReservationWorker($this->vehicle->id, '2030-06-11 09:00', '2030-06-14 09:00'),
    ]);

    $ok = array_values(array_filter($results, fn (array $r): bool => $r['ok']));

    expect($ok)->toHaveCount(1)
        ->and(Reservation::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(VehicleBlock::query()->where('active', true)->count())->toBe(1)
        ->and(collect($results)->firstWhere('ok', false)['error'] ?? '')->toContain('pas disponible');

    // Le refus n'a consommé aucun numéro : la réservation suivante prend le numéro suivant.
    $next = app(SaveReservation::class)->handle(null, [
        'customer_id' => $this->customer->id, 'agency_id' => $this->agency->id, 'vehicle_category_id' => $this->category->id,
        'vehicle_id' => concurrencyVehicle()->id, 'starts_at' => '2030-06-10 09:00', 'ends_at' => '2030-06-13 09:00',
    ], $this->agent);

    expect($ok[0]['number'])->toBe('RES-'.now()->year.'-00001')
        ->and($next->number)->toBe('RES-'.now()->year.'-00002');
});

it('ne laisse passer qu\'une réservation sans véhicule quand la catégorie n\'a plus qu\'un véhicule (Q36)', function () {
    $results = array_map(reservationWorkerResult(...), [
        startReservationWorker(null),
        startReservationWorker(null),
        startReservationWorker(null),
    ]);

    expect(count(array_filter($results, fn (array $r): bool => $r['ok'])))->toBe(1)
        ->and(Reservation::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('départage une réservation sans véhicule et une réservation du dernier véhicule', function () {
    $results = array_map(reservationWorkerResult(...), [
        startReservationWorker(null),
        startReservationWorker($this->vehicle->id),
    ]);

    expect(count(array_filter($results, fn (array $r): bool => $r['ok'])))->toBe(1)
        ->and(Reservation::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('voit la réservation validée entre-temps, même dans une transaction qui a déjà lu', function () {
    $worker = startReservationWorker($this->vehicle->id, mode: 'stale', waitMs: 2500);

    $deadline = microtime(true) + 30;
    while (! str_contains($worker->getOutput(), 'READY') && microtime(true) < $deadline) {
        usleep(50_000);
    }
    expect($worker->getOutput())->toContain('READY');

    app(SaveReservation::class)->handle(null, [
        'customer_id' => $this->customer->id, 'agency_id' => $this->agency->id, 'vehicle_category_id' => $this->category->id,
        'vehicle_id' => null, 'starts_at' => '2030-06-10 09:00', 'ends_at' => '2030-06-13 09:00',
    ], $this->agent);

    $result = reservationWorkerResult($worker);

    // La seule voiture est retenue par la réservation sans véhicule validée entre-temps.
    expect($result['ok'])->toBeFalse()
        ->and(Reservation::query()->withoutGlobalScopes()->count())->toBe(1);
});
