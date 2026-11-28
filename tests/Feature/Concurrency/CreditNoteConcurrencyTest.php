<?php

use App\Domain\Billing\Actions\IssueInvoice;
use App\Domain\Billing\Actions\ValidateInvoice;
use App\Enums\InvoiceLineNature;
use App\Enums\InvoiceType;
use App\Models\Agency;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\TaxCode;
use App\Support\Authorization\Role;
use App\Support\Files\PrivateFiles;
use Database\Seeders\FiscalReferenceSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/*
 * Avoirs plafonnés sous concurrence (phase 6, lot L6, D27-4, annexe B) : deux avoirs
 * partiels de 70,000 HT sur une location de 100,000, saisis au même moment par deux
 * processus PHP distincts. Ensemble ils dépasseraient la facture : un seul passe
 * (verrou de la facture d'origine), et la série AV- reste sans trou.
 */

uses(DatabaseTruncation::class);

afterEach(function () {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    DB::table('invoice_taxes')->delete();
    DB::table('invoice_lines')->delete();
    DB::table('invoices')->whereNotNull('credited_invoice_id')->delete();

    foreach (['invoices', 'sequences', 'sequence_series_changes', 'sequence_series', 'stamp_duty_rules', 'tax_rules',
        'tax_codes', 'fiscal_references', 'company', 'customers', 'activity_log', 'model_has_roles', 'users', 'agencies'] as $table) {
        DB::table($table)->delete();
    }

    Storage::disk(PrivateFiles::DISK)->deleteDirectory('invoices/'.test()->invoice->id);
});

beforeEach(function () {
    $this->seed(FiscalReferenceSeeder::class);
    Company::query()->forceCreate(['id' => Company::SINGLETON_ID, 'legal_name' => 'Aviator SARL', 'invoice_legal_mentions' => 'Mentions de test']);
    $agency = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->agent = userWithProfile(Role::AgencyUser, ['agency_id' => $agency->id]);
    $customer = Customer::factory()->create(['origin_agency_id' => $agency->id]);

    $draft = Invoice::query()->create(['agency_id' => $agency->id, 'type' => InvoiceType::Invoice, 'customer_id' => $customer->id]);
    $this->line = InvoiceLine::query()->create([
        'invoice_id' => $draft->id, 'position' => 1, 'nature' => InvoiceLineNature::Free, 'tax_code_id' => TaxCode::query()->where('code', 'RENTAL')->value('id'),
        'label' => 'Location', 'quantity' => '1.000', 'unit_amount_excl_tax' => '100.000', 'total_excl_tax_amount' => '100.000',
    ]);
    $this->invoice = app(IssueInvoice::class)->handle(app(ValidateInvoice::class)->handle($draft, $this->agent), $this->agent);
});

function startCreditNoteWorker(string $amount): Process
{
    $process = new Process(
        [PHP_BINARY, base_path('tests/Support/credit_note_worker.php'), (string) test()->agent->id, (string) test()->invoice->id, (string) test()->line->id, $amount],
        base_path(), ['APP_ENV' => 'testing'], null, 120,
    );
    $process->start();

    return $process;
}

/**
 * @return array{ok: bool, number?: string, error?: string}
 */
function creditNoteOutcome(Process $process): array
{
    $process->wait();

    expect($process->isSuccessful())->toBeTrue('Processus en échec : '.$process->getErrorOutput().$process->getOutput());

    $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));
    $decoded = json_decode((string) end($lines), true);

    expect($decoded)->toBeArray('Sortie illisible : '.bin2hex(substr($process->getOutput(), -300)).' | '.$process->getErrorOutput());

    /** @var array{ok: bool, number?: string, error?: string} */
    return $decoded;
}

it('ne laisse passer qu\'un de deux avoirs simultanés qui dépasseraient ensemble la facture (D27-4)', function () {
    $results = array_map(creditNoteOutcome(...), [startCreditNoteWorker('70,000'), startCreditNoteWorker('70,000')]);

    $won = array_values(array_filter($results, fn (array $r): bool => $r['ok']));
    $lost = array_values(array_filter($results, fn (array $r): bool => ! $r['ok']));

    expect($won)->toHaveCount(1)
        ->and($won[0]['number'] ?? null)->toBe('AV-000001')
        ->and($lost[0]['error'] ?? '')->toContain('il reste 30,000')
        ->and(Invoice::query()->withoutGlobalScopes()->where('type', InvoiceType::CreditNote)->count())->toBe(1);
});
