<?php

use App\Enums\ContractStatus;
use App\Models\Agency;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Support\Authorization\Role;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/*
 * RG-05 sous concurrence (phase 6, lot L3) : deux agents préparent au même moment la
 * facture du même contrat restitué, par deux processus PHP distincts. Un seul brouillon
 * aboutit (verrou du contrat) ; la période n'est jamais facturée deux fois.
 */

uses(DatabaseTruncation::class);

afterEach(function () {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    foreach (['invoice_lines', 'invoices', 'contracts', 'vehicle_status_logs', 'vehicles', 'customers', 'activity_log',
        'model_has_roles', 'users', 'vehicle_categories', 'agencies'] as $table) {
        DB::table($table)->delete();
    }
});

beforeEach(function () {
    $this->agency = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->agents = [
        userWithProfile(Role::AgencyUser, ['agency_id' => $this->agency->id]),
        userWithProfile(Role::AgencyUser, ['agency_id' => $this->agency->id]),
    ];
    $category = VehicleCategory::factory()->create(['code' => 'CIT', 'name' => 'Citadine']);
    $vehicle = Vehicle::factory()->create(['agency_id' => $this->agency->id, 'vehicle_category_id' => $category->id]);
    $customer = Customer::factory()->create(['origin_agency_id' => $this->agency->id]);

    $this->contract = Contract::query()->create([
        'number' => 'CTR-2030-00001', 'agency_id' => $this->agency->id, 'return_agency_id' => $this->agency->id, 'vehicle_id' => $vehicle->id,
        'vehicle_category_id' => $category->id, 'customer_id' => $customer->id, 'starts_at' => '2030-06-10 09:00', 'ends_at' => '2030-06-13 09:00',
        'departed_at' => '2030-06-10 09:00', 'returned_at' => '2030-06-13 09:00', 'status' => ContractStatus::Returned,
        'mileage_policy' => Contract::MILEAGE_UNLIMITED, 'fuel_policy' => Contract::FUEL_SAME_LEVEL, 'daily_rate_amount' => '100.000',
        'rental_amount' => '300.000', 'options_amount' => '0.000', 'total_amount' => '300.000',
        'quote' => ['days' => array_fill(0, 3, ['date' => null, 'season' => null, 'daily_amount' => '100.000'])],
    ]);
});

function startInvoiceDraftWorker(int $userId): Process
{
    $process = new Process(
        [PHP_BINARY, base_path('tests/Support/invoice_draft_worker.php'), (string) $userId, (string) test()->contract->id],
        base_path(), ['APP_ENV' => 'testing'], null, 120,
    );
    $process->start();

    return $process;
}

/**
 * @return array{ok: bool, error?: string}
 */
function invoiceDraftOutcome(Process $process): array
{
    $process->wait();

    expect($process->isSuccessful())->toBeTrue('Processus en échec : '.$process->getErrorOutput().$process->getOutput());

    $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));
    $decoded = json_decode((string) end($lines), true);

    expect($decoded)->toBeArray('Sortie illisible : '.bin2hex(substr($process->getOutput(), -300)).' | '.$process->getErrorOutput());

    /** @var array{ok: bool, error?: string} */
    return $decoded;
}

it('ne laisse aboutir qu\'un brouillon quand deux agents facturent le même contrat au même moment (RG-05)', function () {
    $results = array_map(invoiceDraftOutcome(...), array_map(fn ($agent) => startInvoiceDraftWorker($agent->id), $this->agents));

    $won = array_values(array_filter($results, fn (array $r): bool => $r['ok']));
    $lost = array_values(array_filter($results, fn (array $r): bool => ! $r['ok']));

    expect($won)->toHaveCount(1)
        ->and($lost)->toHaveCount(1)
        ->and($lost[0]['error'] ?? '')->toContain('déjà facturée')
        ->and(Invoice::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(InvoiceLine::query()->count())->toBe(1)
        ->and(InvoiceLine::query()->sole()->total_excl_tax_amount->toDecimalString())->toBe('300.000');
});
