<?php

use App\Domain\Availability\Actions\SaveReservation;
use App\Domain\Setup\Actions\SaveRatePlan;
use App\Domain\Setup\Actions\SaveSeason;
use App\Enums\ReservationStatus;
use App\Models\Agency;
use App\Models\Contract;
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
 * Conversion concurrente (lot L2, RG-02) : deux processus PHP distincts transforment la
 * même réservation en contrat au même moment. Un seul contrat, une seule occupation
 * active, aucun numéro de contrat consommé par le refus.
 */

uses(DatabaseTruncation::class);

afterEach(function () {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    foreach (['notifications', 'vehicle_blocks', 'contract_options', 'contract_drivers', 'contracts', 'reservation_options', 'reservations',
        'approval_requests', 'sequences', 'vehicle_status_logs', 'vehicle_documents', 'vehicles', 'rate_plan_lines', 'rate_plans', 'seasons',
        'named_locks', 'customers', 'activity_log', 'model_has_roles', 'users', 'vehicle_categories', 'agencies'] as $table) {
        DB::table($table)->delete();
    }
});

beforeEach(function () {
    $admin = userWithProfile(Role::Admin);
    $agency = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->agent = userWithProfile(Role::AgencyUser, ['agency_id' => $agency->id]);
    $category = VehicleCategory::factory()->create(['code' => 'CIT', 'name' => 'Citadine']);
    $season = app(SaveSeason::class)->handle(null, ['name' => 'Année 2030', 'starts_on' => '2030-01-01', 'ends_on' => '2030-12-31'], $admin);
    app(SaveRatePlan::class)->handle(null, [
        'vehicle_category_id' => $category->id, 'season_id' => $season->id, 'agency_id' => null,
        'valid_from' => '2030-01-01', 'valid_to' => null, 'is_active' => true,
        'lines' => [['min_days' => 1, 'max_days' => null, 'daily_amount' => '100,000']],
    ], $admin);
    $vehicle = Vehicle::factory()->create(['agency_id' => $agency->id, 'vehicle_category_id' => $category->id]);
    foreach (['insurance', 'technical_inspection'] as $type) {
        VehicleDocument::query()->create(['vehicle_id' => $vehicle->id, 'type' => $type, 'valid_from' => '2030-01-01', 'valid_until' => '2030-12-31']);
    }

    $this->reservation = app(SaveReservation::class)->handle(null, [
        'customer_id' => Customer::factory()->create(['origin_agency_id' => $agency->id])->id, 'agency_id' => $agency->id,
        'vehicle_category_id' => $category->id, 'vehicle_id' => $vehicle->id,
        'starts_at' => '2030-06-10 09:00', 'ends_at' => '2030-06-13 09:00',
    ], $this->agent);
});

function startContractWorker(): Process
{
    $process = new Process(
        [PHP_BINARY, base_path('tests/Support/contract_worker.php'), (string) test()->agent->id, (string) test()->reservation->id],
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
function contractWorkerResult(Process $process): array
{
    $process->wait();

    expect($process->isSuccessful())->toBeTrue('Processus en échec : '.$process->getErrorOutput().$process->getOutput());

    $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));

    /** @var array{ok: bool, number?: string, error?: string} */
    return json_decode((string) end($lines), true, flags: JSON_THROW_ON_ERROR);
}

it('ne crée qu\'un contrat quand la même réservation est convertie deux fois en même temps', function () {
    $results = array_map(contractWorkerResult(...), [startContractWorker(), startContractWorker()]);
    $ok = array_values(array_filter($results, fn (array $r): bool => $r['ok']));

    expect($ok)->toHaveCount(1)
        ->and($ok[0]['number'])->toBe('CTR-'.now()->year.'-00001')
        ->and(collect($results)->firstWhere('ok', false)['error'] ?? '')->toContain('confirmée')
        ->and(Contract::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(Reservation::query()->withoutGlobalScopes()->sole()->status)->toBe(ReservationStatus::Converted)
        ->and(VehicleBlock::query()->where('active', true)->count())->toBe(1);
});
