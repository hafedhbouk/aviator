<?php

use App\Domain\Rental\Actions\OpenContract;
use App\Domain\Setup\Actions\SaveRatePlan;
use App\Domain\Setup\Actions\SaveSeason;
use App\Enums\ContractStatus;
use App\Models\Agency;
use App\Models\Contract;
use App\Models\ContractExtension;
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
 * Scénario 3 sous concurrence (lot L7, RG-02) : une prolongation et une réservation du
 * même véhicule, sur la même période ajoutée, saisies au même moment par deux processus
 * PHP distincts. Une seule aboutit ; jamais deux occupations qui se chevauchent.
 */

uses(DatabaseTruncation::class);

afterEach(function () {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    foreach (['notifications', 'vehicle_blocks', 'contract_extensions', 'contract_options', 'contract_drivers', 'contracts', 'reservation_options',
        'reservations', 'approval_requests', 'sequences', 'vehicle_status_logs', 'vehicle_documents', 'vehicles', 'rate_plan_lines', 'rate_plans',
        'seasons', 'named_locks', 'customers', 'activity_log', 'model_has_roles', 'users', 'vehicle_categories', 'agencies'] as $table) {
        DB::table($table)->delete();
    }
});

beforeEach(function () {
    $admin = userWithProfile(Role::Admin);
    $this->agency = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->agent = userWithProfile(Role::AgencyUser, ['agency_id' => $this->agency->id]);
    $this->category = VehicleCategory::factory()->create(['code' => 'CIT', 'name' => 'Citadine']);
    $season = app(SaveSeason::class)->handle(null, ['name' => 'Année 2030', 'starts_on' => '2030-01-01', 'ends_on' => '2030-12-31'], $admin);
    app(SaveRatePlan::class)->handle(null, [
        'vehicle_category_id' => $this->category->id, 'season_id' => $season->id, 'agency_id' => null,
        'valid_from' => '2030-01-01', 'valid_to' => null, 'is_active' => true,
        'lines' => [['min_days' => 1, 'max_days' => null, 'daily_amount' => '100,000']],
    ], $admin);
    $this->vehicle = Vehicle::factory()->create(['agency_id' => $this->agency->id, 'vehicle_category_id' => $this->category->id]);
    foreach (['insurance', 'technical_inspection'] as $type) {
        VehicleDocument::query()->create(['vehicle_id' => $this->vehicle->id, 'type' => $type, 'valid_from' => '2030-01-01', 'valid_until' => '2030-12-31']);
    }
    $this->customer = Customer::factory()->create(['origin_agency_id' => $this->agency->id]);

    // Contrat en cours (le départ lui-même est testé ailleurs) : 10/06 → 13/06/2030.
    $this->contract = app(OpenContract::class)->atCounter([
        'customer_id' => $this->customer->id, 'agency_id' => $this->agency->id, 'vehicle_id' => $this->vehicle->id,
        'starts_at' => '2030-06-10 09:00', 'ends_at' => '2030-06-13 09:00',
    ], $this->agent);
    $this->contract->forceFill(['status' => ContractStatus::Active, 'departed_at' => '2030-06-10 09:00'])->save();
});

function startExtensionWorker(string $endsAt): Process
{
    $process = new Process(
        [PHP_BINARY, base_path('tests/Support/extension_worker.php'), (string) test()->agent->id, (string) test()->contract->id, $endsAt],
        base_path(), ['APP_ENV' => 'testing'], null, 120,
    );
    $process->start();

    return $process;
}

function startCompetingReservation(string $from, string $to): Process
{
    $process = new Process(
        [PHP_BINARY, base_path('tests/Support/reservation_worker.php'), (string) test()->agent->id, (string) test()->customer->id,
            (string) test()->agency->id, (string) test()->category->id, (string) test()->vehicle->id, $from, $to, 'new', '0'],
        base_path(), ['APP_ENV' => 'testing'], null, 120,
    );
    $process->start();

    return $process;
}

/**
 * @return array{ok: bool, error?: string}
 */
function workerOutcome(Process $process): array
{
    $process->wait();

    expect($process->isSuccessful())->toBeTrue('Processus en échec : '.$process->getErrorOutput().$process->getOutput());

    $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));

    /** @var array{ok: bool, error?: string} */
    return json_decode((string) end($lines), true, flags: JSON_THROW_ON_ERROR);
}

it('ne laisse aboutir qu\'une prolongation ou une réservation saisies en même temps sur la même période', function () {
    $results = array_map(workerOutcome(...), [
        startExtensionWorker('2030-06-15 09:00'),
        startCompetingReservation('2030-06-14 09:00', '2030-06-16 09:00'),
    ]);

    $won = count(array_filter($results, fn (array $r): bool => $r['ok']));
    $blocks = VehicleBlock::query()->where('vehicle_id', $this->vehicle->id)->where('active', true)->orderBy('starts_at')->get();

    expect($won)->toBe(1)
        ->and(ContractExtension::query()->count() + Reservation::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and($blocks)->toHaveCount(ContractExtension::query()->exists() ? 1 : 2);

    // Jamais deux occupations actives qui se chevauchent.
    for ($i = 1; $i < $blocks->count(); $i++) {
        expect($blocks[$i]->starts_at->greaterThanOrEqualTo($blocks[$i - 1]->ends_at))->toBeTrue();
    }

    expect(Contract::query()->withoutGlobalScopes()->sole()->ends_at->format('Y-m-d H:i'))
        ->toBe(ContractExtension::query()->exists() ? '2030-06-15 09:00' : '2030-06-13 09:00');
});
