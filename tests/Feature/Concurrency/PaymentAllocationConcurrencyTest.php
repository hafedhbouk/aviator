<?php

use App\Domain\Billing\Actions\IssueInvoice;
use App\Domain\Billing\Actions\ValidateInvoice;
use App\Domain\Billing\InvoiceBalance;
use App\Enums\InvoiceLineNature;
use App\Enums\InvoiceType;
use App\Enums\PaymentStatus;
use App\Models\Agency;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\TaxCode;
use App\Support\Authorization\Role;
use App\Support\Files\PrivateFiles;
use Database\Seeders\FiscalReferenceSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/*
 * Imputations sous concurrence (phase 6, lot L7, D57) : deux encaissements de 100,000
 * imputés au même moment sur la même facture de 137,850, par deux processus PHP
 * distincts. Ensemble ils dépasseraient le reste dû : un seul passe (verrou de la
 * facture), et la facture n'est jamais réglée au-delà de son montant.
 */

uses(DatabaseTruncation::class);

afterEach(function () {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    foreach (['payment_allocations', 'payments', 'payment_methods', 'invoice_taxes', 'invoice_lines', 'invoices', 'sequences', 'sequence_series_changes',
        'sequence_series', 'stamp_duty_rules', 'tax_rules', 'tax_codes', 'fiscal_references', 'company', 'customers', 'activity_log', 'model_has_roles',
        'users', 'agencies'] as $table) {
        DB::table($table)->delete();
    }

    Storage::disk(PrivateFiles::DISK)->deleteDirectory('invoices/'.test()->invoice->id);
});

beforeEach(function () {
    $this->seed(FiscalReferenceSeeder::class);
    $this->seed(PaymentMethodSeeder::class);
    PaymentMethod::query()->update(['is_active' => true]);
    Company::query()->forceCreate(['id' => Company::SINGLETON_ID, 'legal_name' => 'Aviator SARL', 'invoice_legal_mentions' => 'Mentions de test']);
    $this->agency = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->agent = userWithProfile(Role::AgencyUser, ['agency_id' => $this->agency->id]);
    $this->customer = Customer::factory()->create(['origin_agency_id' => $this->agency->id]);

    $draft = Invoice::query()->create(['agency_id' => $this->agency->id, 'type' => InvoiceType::Invoice, 'customer_id' => $this->customer->id]);
    InvoiceLine::query()->create([
        'invoice_id' => $draft->id, 'position' => 1, 'nature' => InvoiceLineNature::Free, 'tax_code_id' => TaxCode::query()->where('code', 'RENTAL')->value('id'),
        'label' => 'Location', 'quantity' => '1.000', 'unit_amount_excl_tax' => '115.000', 'total_excl_tax_amount' => '115.000',
    ]);
    $this->invoice = app(IssueInvoice::class)->handle(app(ValidateInvoice::class)->handle($draft, $this->agent), $this->agent);
});

function startPaymentWorker(string $amount): Process
{
    $process = new Process(
        [PHP_BINARY, base_path('tests/Support/payment_worker.php'), (string) test()->agent->id, (string) test()->agency->id, (string) test()->customer->id,
            (string) PaymentMethod::query()->where('code', 'cash')->value('id'), (string) test()->invoice->id, $amount],
        base_path(), ['APP_ENV' => 'testing'], null, 120,
    );
    $process->start();

    return $process;
}

/**
 * @return array{ok: bool, id?: int, error?: string}
 */
function paymentOutcome(Process $process): array
{
    $process->wait();

    expect($process->isSuccessful())->toBeTrue('Processus en échec : '.$process->getErrorOutput().$process->getOutput());

    $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));
    $decoded = json_decode((string) end($lines), true);

    expect($decoded)->toBeArray('Sortie illisible : '.bin2hex(substr($process->getOutput(), -300)).' | '.$process->getErrorOutput());

    /** @var array{ok: bool, id?: int, error?: string} */
    return $decoded;
}

it('ne laisse passer qu\'un de deux encaissements simultanés qui dépasseraient ensemble le reste dû (D57)', function () {
    $results = array_map(paymentOutcome(...), [startPaymentWorker('100,000'), startPaymentWorker('100,000')]);

    $won = array_values(array_filter($results, fn (array $r): bool => $r['ok']));
    $lost = array_values(array_filter($results, fn (array $r): bool => ! $r['ok']));
    $invoice = Invoice::query()->withoutGlobalScopes()->findOrFail($this->invoice->id);

    expect($won)->toHaveCount(1)
        ->and($lost[0]['error'] ?? '')->toContain('il reste 37,850 DT dû')
        // L'encaissement refusé est annulé avec sa transaction : rien n'est enregistré.
        ->and(Payment::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(InvoiceBalance::remaining($invoice)->toDecimalString())->toBe('37.850')
        ->and($invoice->payment_status)->toBe(PaymentStatus::PartiallyPaid);
});
