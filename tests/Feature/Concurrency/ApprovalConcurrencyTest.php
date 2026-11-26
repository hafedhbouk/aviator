<?php

use App\Domain\Availability\Actions\SaveReservation;
use App\Domain\Setup\Actions\SaveRatePlan;
use App\Domain\Setup\Actions\SaveSeason;
use App\Enums\ApprovalStatus;
use App\Models\Agency;
use App\Models\ApprovalRequest;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\VehicleDocument;
use App\Support\Authorization\Role;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/*
 * Double décision (D46, lot L1) : deux processus PHP distincts tranchent la même
 * demande au même moment, l'un approuve, l'autre refuse. Une seule décision passe.
 */

uses(DatabaseTruncation::class);

afterEach(function () {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    foreach (['notifications', 'vehicle_blocks', 'reservation_options', 'reservations', 'approval_requests', 'sequences', 'vehicle_status_logs',
        'vehicle_documents', 'vehicles', 'rate_plan_lines', 'rate_plans', 'seasons', 'named_locks', 'customers', 'activity_log',
        'model_has_roles', 'users', 'vehicle_categories', 'agencies'] as $table) {
        DB::table($table)->delete();
    }
});

beforeEach(function () {
    $this->superAdmin = withTwoFactor(userWithProfile(Role::SuperAdmin));
    $admin = userWithProfile(Role::Admin);
    $agency = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $agent = userWithProfile(Role::AgencyUser, ['agency_id' => $agency->id]);
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

    app(SaveReservation::class)->handle(null, [
        'customer_id' => Customer::factory()->create(['origin_agency_id' => $agency->id])->id, 'agency_id' => $agency->id,
        'vehicle_category_id' => $category->id, 'vehicle_id' => $vehicle->id,
        'starts_at' => '2030-06-10 09:00', 'ends_at' => '2030-06-13 09:00',
        'discount_amount' => '30,000', 'discount_reason' => 'Geste commercial',
    ], $agent);

    $this->request = ApprovalRequest::query()->withoutGlobalScopes()->sole();
});

function startApprovalWorker(string $decision): Process
{
    $process = new Process(
        [PHP_BINARY, base_path('tests/Support/approval_worker.php'), (string) test()->superAdmin->id, (string) test()->request->id, $decision],
        base_path(),
        ['APP_ENV' => 'testing'],
        null,
        120,
    );
    $process->start();

    return $process;
}

/**
 * @return array{ok: bool, status?: string, error?: string}
 */
function approvalWorkerResult(Process $process): array
{
    $process->wait();

    expect($process->isSuccessful())->toBeTrue('Processus en échec : '.$process->getErrorOutput().$process->getOutput());

    $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));

    /** @var array{ok: bool, status?: string, error?: string} */
    return json_decode((string) end($lines), true, flags: JSON_THROW_ON_ERROR);
}

it('ne retient qu\'une décision quand deux arrivent en même temps', function () {
    $results = array_map(approvalWorkerResult(...), [startApprovalWorker('approve'), startApprovalWorker('reject')]);
    $ok = array_values(array_filter($results, fn (array $r): bool => $r['ok']));
    $refused = array_values(array_filter($results, fn (array $r): bool => ! $r['ok']));

    $final = ApprovalRequest::query()->withoutGlobalScopes()->sole();

    expect($ok)->toHaveCount(1)
        ->and($refused[0]['error'] ?? '')->toContain('déjà traitée')
        ->and($final->status->value)->toBe($ok[0]['status'])
        ->and($final->status)->not->toBe(ApprovalStatus::Pending);
});
