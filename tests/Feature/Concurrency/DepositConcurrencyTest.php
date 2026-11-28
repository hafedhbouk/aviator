<?php

use App\Domain\Billing\Cash\CashRegister;
use App\Domain\Billing\Deposits\DepositDesk;
use App\Enums\ContractStatus;
use App\Models\Agency;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositMovement;
use App\Models\PaymentMethod;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Support\Authorization\Role;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/*
 * RG-08 sous concurrence (phase 6, lot L10) : deux restitutions de 300,000 sur une
 * caution de 500,000, saisies au même moment par deux processus PHP distincts. Ensemble
 * elles dépasseraient le reçu : une seule passe (verrou de la caution), et les reçus
 * `CAU-` restent sans trou.
 */

uses(DatabaseTruncation::class);

afterEach(function () {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    foreach (['deposit_movements', 'deposits', 'cash_sessions', 'payment_methods', 'contracts', 'sequences', 'sequence_series_changes', 'sequence_series',
        'vehicle_status_logs', 'vehicles', 'vehicle_categories', 'customers', 'activity_log', 'model_has_roles', 'users', 'agencies'] as $table) {
        DB::table($table)->delete();
    }
});

beforeEach(function () {
    $this->seed(PaymentMethodSeeder::class);
    PaymentMethod::query()->update(['is_active' => true]);
    $agency = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->agent = userWithProfile(Role::AgencyUser, ['agency_id' => $agency->id]);
    $customer = Customer::factory()->create(['origin_agency_id' => $agency->id]);
    $vehicle = Vehicle::factory()->create(['agency_id' => $agency->id, 'vehicle_category_id' => VehicleCategory::factory()->create()->id]);
    $contract = Contract::query()->create([
        'number' => 'CTR-2030-00001', 'agency_id' => $agency->id, 'return_agency_id' => $agency->id, 'vehicle_id' => $vehicle->id,
        'vehicle_category_id' => $vehicle->vehicle_category_id, 'customer_id' => $customer->id, 'starts_at' => '2030-06-10 09:00',
        'ends_at' => '2030-06-13 09:00', 'departed_at' => '2030-06-10 09:00', 'status' => ContractStatus::Active,
        'mileage_policy' => Contract::MILEAGE_UNLIMITED, 'fuel_policy' => Contract::FUEL_SAME_LEVEL, 'daily_rate_amount' => '100.000',
        'rental_amount' => '300.000', 'options_amount' => '0.000', 'total_amount' => '300.000', 'quote' => [], 'deposit_expected_amount' => '500.000',
    ]);

    app(CashRegister::class)->open($agency, '0', $this->agent);
    $this->deposit = app(DepositDesk::class)->receive($contract, ['amount' => '500', 'payment_method_id' => (int) PaymentMethod::query()->where('code', 'cash')->value('id')], $this->agent);
    $contract->forceFill(['status' => ContractStatus::Returned, 'returned_at' => '2030-06-13 09:00'])->save();
});

function startDepositRefundWorker(string $amount): Process
{
    $process = new Process(
        [PHP_BINARY, base_path('tests/Support/deposit_refund_worker.php'), (string) test()->agent->id, (string) test()->deposit->id, (string) PaymentMethod::query()->where('code', 'cash')->value('id'), $amount],
        base_path(), ['APP_ENV' => 'testing'], null, 120,
    );
    $process->start();

    return $process;
}

/**
 * @return array{ok: bool, receipt?: string, error?: string}
 */
function depositRefundOutcome(Process $process): array
{
    $process->wait();

    expect($process->isSuccessful())->toBeTrue('Processus en échec : '.$process->getErrorOutput().$process->getOutput());

    $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));
    $decoded = json_decode((string) end($lines), true);

    expect($decoded)->toBeArray('Sortie illisible : '.bin2hex(substr($process->getOutput(), -300)).' | '.$process->getErrorOutput());

    /** @var array{ok: bool, receipt?: string, error?: string} */
    return $decoded;
}

it('ne laisse passer qu\'une de deux restitutions simultanées qui dépasseraient ensemble la caution (RG-08)', function () {
    $results = array_map(depositRefundOutcome(...), [startDepositRefundWorker('300,000'), startDepositRefundWorker('300,000')]);

    $won = array_values(array_filter($results, fn (array $r): bool => $r['ok']));
    $lost = array_values(array_filter($results, fn (array $r): bool => ! $r['ok']));
    $deposit = Deposit::query()->withoutGlobalScopes()->findOrFail($this->deposit->id);

    expect($won)->toHaveCount(1)
        // Reçu de la caution : n° 1 ; reçu de la restitution qui passe : n° 2, sans trou.
        ->and($won[0]['receipt'] ?? '')->toMatch('/^CAU-\d{4}-00002$/')
        ->and($lost[0]['error'] ?? '')->toContain('Il reste 200,000')
        ->and(DepositMovement::query()->count())->toBe(1)
        ->and($deposit->refunded_amount->toDecimalString())->toBe('300.000')
        ->and($deposit->remaining()->toDecimalString())->toBe('200.000');
});
