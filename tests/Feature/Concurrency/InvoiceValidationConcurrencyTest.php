<?php

use App\Enums\InvoiceLineNature;
use App\Enums\InvoiceType;
use App\Models\Agency;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Sequence;
use App\Models\TaxCode;
use App\Support\Authorization\Role;
use Database\Seeders\FiscalReferenceSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/*
 * RG-07 sous concurrence (phase 6, lot L4) : trois factures validées au même moment
 * par trois processus PHP distincts. Le numéro est attribué dans la transaction de la
 * validation : numéros consécutifs de la série AVI-, sans doublon ni trou.
 */

uses(DatabaseTruncation::class);

afterEach(function () {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    foreach (['invoice_taxes', 'invoice_lines', 'invoices', 'sequences', 'sequence_series_changes', 'sequence_series', 'stamp_duty_rules', 'tax_rules',
        'tax_codes', 'fiscal_references', 'company', 'customers', 'activity_log', 'model_has_roles', 'users', 'agencies'] as $table) {
        DB::table($table)->delete();
    }
});

beforeEach(function () {
    $this->seed(FiscalReferenceSeeder::class);
    Company::query()->forceCreate(['id' => Company::SINGLETON_ID, 'legal_name' => 'Aviator SARL', 'invoice_legal_mentions' => 'Mentions de test']);
    $this->agency = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->agent = userWithProfile(Role::AgencyUser, ['agency_id' => $this->agency->id]);
    $customer = Customer::factory()->create(['origin_agency_id' => $this->agency->id]);
    $rental = TaxCode::query()->where('code', 'RENTAL')->value('id');

    $this->drafts = [];

    foreach (['100.000', '200.000', '300.000'] as $amount) {
        $invoice = Invoice::query()->create(['agency_id' => $this->agency->id, 'type' => InvoiceType::Invoice, 'customer_id' => $customer->id]);
        InvoiceLine::query()->create([
            'invoice_id' => $invoice->id, 'position' => 1, 'nature' => InvoiceLineNature::Free, 'tax_code_id' => $rental, 'label' => 'Location',
            'quantity' => '1.000', 'unit_amount_excl_tax' => $amount, 'total_excl_tax_amount' => $amount,
        ]);
        $this->drafts[] = $invoice;
    }
});

function startInvoiceValidationWorker(int $invoiceId): Process
{
    $process = new Process(
        [PHP_BINARY, base_path('tests/Support/invoice_validation_worker.php'), (string) test()->agent->id, (string) $invoiceId],
        base_path(), ['APP_ENV' => 'testing'], null, 120,
    );
    $process->start();

    return $process;
}

/**
 * @return array{ok: bool, number?: string, error?: string}
 */
function invoiceValidationOutcome(Process $process): array
{
    $process->wait();

    expect($process->isSuccessful())->toBeTrue('Processus en échec : '.$process->getErrorOutput().$process->getOutput());

    $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));
    $decoded = json_decode((string) end($lines), true);

    expect($decoded)->toBeArray('Sortie illisible : '.bin2hex(substr($process->getOutput(), -300)).' | '.$process->getErrorOutput());

    /** @var array{ok: bool, number?: string, error?: string} */
    return $decoded;
}

it('numérote sans doublon ni trou trois factures validées au même moment (RG-07)', function () {
    $processes = array_map(fn (Invoice $invoice): Process => startInvoiceValidationWorker($invoice->id), $this->drafts);
    $results = array_map(invoiceValidationOutcome(...), $processes);

    $numbers = array_map(fn (array $result): string => $result['number'] ?? 'refus : '.($result['error'] ?? ''), $results);
    sort($numbers);

    expect($numbers)->toBe(['AVI-000001', 'AVI-000002', 'AVI-000003'])
        ->and(Sequence::query()->where('key', 'series:invoice')->value('last_value'))->toBe(3)
        ->and(Invoice::query()->withoutGlobalScopes()->whereNotNull('number')->count())->toBe(3);
});
