<?php

use App\Domain\Billing\Actions\CreateCreditNote;
use App\Domain\Billing\Actions\IssueInvoice;
use App\Domain\Billing\Actions\SettleCreditNote;
use App\Domain\Billing\Actions\ValidateInvoice;
use App\Domain\Billing\Documents\InvoiceDocuments;
use App\Domain\Billing\Exceptions\CreditNoteException;
use App\Enums\InvoiceLineNature;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Agency;
use App\Models\Company;
use App\Models\CreditNoteSettlement;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceTax;
use App\Models\PaymentMethod;
use App\Models\Sequence;
use App\Models\TaxCode;
use App\Support\Authorization\Role;
use App\Support\Files\PrivateFiles;
use Carbon\CarbonImmutable;
use Database\Seeders\FiscalReferenceSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

/*
 * Avoirs (phase 6, lot L6, E-04, E19, D27-4, N14, référentiel §6) et **scénario 7** :
 * avoir partiel, numérotation sans trou dans la série AV-. Facture de référence (§5) :
 * location 100,000 + GPS 10,000 + siège enfant 5,000 = 137,850 à payer. Deux avoirs
 * simultanés : tests/Feature/Concurrency/CreditNoteConcurrencyTest.php.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow('2027-03-20 10:00');
    Storage::fake(PrivateFiles::DISK);
    $this->seed(FiscalReferenceSeeder::class);
    $this->seed(PaymentMethodSeeder::class);
    PaymentMethod::query()->update(['is_active' => true]);
    Company::query()->forceCreate(['id' => Company::SINGLETON_ID, 'legal_name' => 'Aviator SARL', 'invoice_legal_mentions' => 'Mentions de test']);

    $this->sfax = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->sousse = Agency::factory()->create(['code' => 'SOU', 'name' => 'Sousse']);
    $this->agent = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sfax->id]);
    $this->agentSousse = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sousse->id]);
    $this->customer = Customer::factory()->create(['origin_agency_id' => $this->sfax->id]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * Facture émise de la §5 : 115,000 HT, 21,850 TVA, 1,000 timbre, 137,850 à payer.
 */
function issuedReferenceInvoice(?Customer $customer = null): Invoice
{
    $invoice = Invoice::query()->create(['agency_id' => test()->sfax->id, 'type' => InvoiceType::Invoice, 'customer_id' => ($customer ?? test()->customer)->id]);

    foreach ([['RENTAL', '100.000', 'Location'], ['GPS', '10.000', 'GPS'], ['CHILD_SEAT', '5.000', 'Siège enfant']] as $index => [$code, $amount, $label]) {
        InvoiceLine::query()->create([
            'invoice_id' => $invoice->id, 'position' => $index + 1, 'nature' => InvoiceLineNature::Free,
            'tax_code_id' => TaxCode::query()->where('code', $code)->value('id'), 'label' => $label, 'quantity' => '1.000',
            'unit_amount_excl_tax' => $amount, 'total_excl_tax_amount' => $amount,
        ]);
    }

    return app(IssueInvoice::class)->handle(app(ValidateInvoice::class)->handle($invoice, test()->agent), test()->agent);
}

function lineOf(Invoice $invoice, string $label): int
{
    return (int) $invoice->lines()->where('label', $label)->value('id');
}

function creditNote(Invoice $invoice, ?array $amounts, string $reason = 'Geste commercial accordé au client'): Invoice
{
    return app(CreateCreditNote::class)->handle($invoice, $amounts, $reason, test()->agent);
}

it('plafonne les avoirs au montant de la facture : 50,000 puis 87,850 acceptés, 0,001 refusé (annexe B, scénario 7)', function () {
    $invoice = issuedReferenceInvoice();

    // Avoir partiel : 42,017 HT sur la location, TVA 7,983 au taux figé, total 50,000, sans timbre.
    $first = creditNote($invoice, [lineOf($invoice, 'Location') => '42,017']);

    expect($first->type)->toBe(InvoiceType::CreditNote)
        ->and($first->number)->toBe('AV-000001')
        ->and($first->status)->toBe(InvoiceStatus::Validated)
        ->and($first->credited_invoice_id)->toBe($invoice->id)
        ->and($first->credit_reason)->toBe('Geste commercial accordé au client')
        ->and($first->total_excl_tax_amount->toDecimalString())->toBe('42.017')
        ->and($first->total_tax_amount->toDecimalString())->toBe('7.983')
        ->and($first->stamp_duty_amount->toDecimalString())->toBe('0.000')
        ->and($first->total_due_amount->toDecimalString())->toBe('50.000')
        ->and($first->customer_snapshot)->toBe($invoice->customer_snapshot)
        ->and($invoice->refresh()->status)->toBe(InvoiceStatus::Issued);

    // Avoir du reste : il solde la facture, reprend le reste exact de la TVA et le timbre (Q97).
    $second = creditNote($invoice, null, 'Annulation du reste de la facture');

    expect($second->number)->toBe('AV-000002')
        ->and($second->total_excl_tax_amount->toDecimalString())->toBe('72.983')
        ->and($second->total_tax_amount->toDecimalString())->toBe('13.867')
        ->and($second->stamp_duty_amount->toDecimalString())->toBe('1.000')
        ->and($second->total_due_amount->toDecimalString())->toBe('87.850')
        ->and($first->total_due_amount->plus($second->total_due_amount)->toDecimalString())->toBe('137.850')
        ->and($invoice->refresh()->status)->toBe(InvoiceStatus::Credited);

    expect(fn () => creditNote($invoice, [lineOf($invoice, 'GPS') => '0,001']))->toThrow(CreditNoteException::class, 'entièrement créditée')
        // Série AV- distincte : le compteur des factures n'a pas bougé, aucun numéro perdu.
        ->and(Sequence::query()->where('key', 'series:credit_note')->value('last_value'))->toBe(2)
        ->and(Sequence::query()->where('key', 'series:invoice')->value('last_value'))->toBe(1);
});

it('crédite toute la facture d\'un coup : mêmes montants HT, TVA et timbre que la facture', function () {
    $invoice = issuedReferenceInvoice();
    $note = creditNote($invoice, null, 'Facture établie au mauvais client');

    expect($note->total_excl_tax_amount->toDecimalString())->toBe('115.000')
        ->and($note->total_tax_amount->toDecimalString())->toBe('21.850')
        ->and($note->stamp_duty_amount->toDecimalString())->toBe('1.000')
        ->and($note->total_due_amount->toDecimalString())->toBe('137.850')
        ->and($note->lines()->count())->toBe(3)
        ->and($note->lines()->pluck('source_type')->unique()->all())->toBe([InvoiceLine::SOURCE_INVOICE_LINE])
        // Taxes aux taux figés de la facture : même règle, même copie.
        ->and(InvoiceTax::query()->where('invoice_id', $note->id)->orderBy('code')->pluck('rule_snapshot')->all())
        ->toBe(InvoiceTax::query()->where('invoice_id', $invoice->id)->orderBy('code')->pluck('rule_snapshot')->all())
        ->and($invoice->refresh()->status)->toBe(InvoiceStatus::Credited);
});

it('refuse un avoir au-delà du reste d\'une ligne, sur une ligne étrangère ou sans montant', function () {
    $invoice = issuedReferenceInvoice();
    $other = issuedReferenceInvoice();

    expect(fn () => creditNote($invoice, [lineOf($invoice, 'GPS') => '10,001']))->toThrow(CreditNoteException::class, 'il reste 10,000')
        ->and(fn () => creditNote($invoice, [lineOf($other, 'GPS') => '1']))->toThrow(CreditNoteException::class, 'Ligne inconnue')
        ->and(fn () => creditNote($invoice, [lineOf($invoice, 'GPS') => '0']))->toThrow(CreditNoteException::class, 'au moins un montant')
        ->and(fn () => creditNote($invoice, [lineOf($invoice, 'GPS') => '-1']))->toThrow(CreditNoteException::class)
        ->and(fn () => creditNote($invoice, null, 'court'))->toThrow(CreditNoteException::class, 'motif')
        ->and(Invoice::query()->withoutGlobalScopes()->where('type', InvoiceType::CreditNote)->count())->toBe(0)
        ->and((int) Sequence::query()->where('key', 'series:credit_note')->value('last_value'))->toBe(0);

    creditNote($invoice, [lineOf($invoice, 'GPS') => '6']);

    expect(fn () => creditNote($invoice, [lineOf($invoice, 'GPS') => '4,001']))->toThrow(CreditNoteException::class, 'il reste 4,000');
});

it('ne crédite qu\'une facture émise : une facture validée s\'annule, un avoir ne se crédite pas (Q79)', function () {
    $validated = Invoice::query()->create(['agency_id' => $this->sfax->id, 'type' => InvoiceType::Invoice, 'customer_id' => $this->customer->id]);
    InvoiceLine::query()->create([
        'invoice_id' => $validated->id, 'position' => 1, 'nature' => InvoiceLineNature::Free, 'tax_code_id' => TaxCode::query()->where('code', 'RENTAL')->value('id'),
        'label' => 'Location', 'quantity' => '1.000', 'unit_amount_excl_tax' => '100.000', 'total_excl_tax_amount' => '100.000',
    ]);
    $validated = app(ValidateInvoice::class)->handle($validated, $this->agent);
    $note = creditNote(issuedReferenceInvoice(), null);

    expect(fn () => creditNote($validated, null))->toThrow(CreditNoteException::class, 's\'annule sans avoir')
        ->and(fn () => creditNote($note, null))->toThrow(CreditNoteException::class, 'ne se crédite pas')
        ->and(fn () => app(CreateCreditNote::class)->handle(issuedReferenceInvoice(), null, 'Geste commercial accordé', $this->agentSousse))
        ->toThrow(CreditNoteException::class, 'non autorisée');
});

it('émet l\'avoir avec son PDF figé, qui cite la facture initiale et le motif (§6)', function () {
    $invoice = issuedReferenceInvoice();
    $note = app(IssueInvoice::class)->handle(creditNote($invoice, [lineOf($invoice, 'Location') => '42,017']), $this->agent);
    $html = view('pdf.invoice', app(InvoiceDocuments::class)->viewData($note))->render();

    expect($note->status)->toBe(InvoiceStatus::Issued)
        ->and($note->issued_pdf_path)->toBe("invoices/{$note->id}/AV-000001.pdf")
        ->and($html)->toContain('Avoir n° AV-000001')
        ->toContain('Facture initiale AVI-000001')
        ->toContain('Geste commercial accordé au client')
        ->toContain(e($note->total_due_amount->format()));
});

it('rembourse un avoir ou l\'impute sur une facture du même client, jamais au-delà (E19)', function () {
    $invoice = issuedReferenceInvoice();
    $note = creditNote($invoice, [lineOf($invoice, 'Location') => '42,017']);
    $settle = app(SettleCreditNote::class);
    $cash = PaymentMethod::query()->where('code', 'cash')->sole();
    $transfer = PaymentMethod::query()->where('code', 'transfer')->sole();

    $refund = $settle->refund($note, '20,000', $cash, null, $this->agent);

    expect($refund->kind)->toBe(CreditNoteSettlement::REFUND)
        ->and($refund->payment_method_id)->toBe($cash->id)
        ->and(SettleCreditNote::remaining($note->refresh())->toDecimalString())->toBe('30.000')
        ->and(fn () => $settle->refund($note, '10,000', $transfer, null, $this->agent))->toThrow(CreditNoteException::class, 'exige une référence')
        ->and(fn () => $settle->refund($note, '30,001', $cash, null, $this->agent))->toThrow(CreditNoteException::class, 'Il reste 30,000');

    // Imputation sur la facture d'origine, restée impayée : 30,000 de moins à payer.
    $allocation = $settle->allocate($note, $invoice, '30,000', $this->agent);

    expect($allocation->invoice_id)->toBe($invoice->id)
        ->and(SettleCreditNote::remaining($note->refresh())->isZero())->toBeTrue()
        ->and(SettleCreditNote::allocatedTo($invoice)->toDecimalString())->toBe('30.000')
        ->and(fn () => $settle->allocate($note, $invoice, '0,001', $this->agent))->toThrow(CreditNoteException::class, 'Il reste 0,000')
        ->and(fn () => $allocation->update(['amount' => '1.000']))->toThrow(LogicException::class)
        ->and(fn () => $allocation->delete())->toThrow(LogicException::class);
});

it('refuse d\'imputer un avoir sur la facture d\'un autre client, sur un avoir ou au-delà du reste dû', function () {
    $invoice = issuedReferenceInvoice();
    $note = creditNote($invoice, null, 'Facture établie au mauvais client');
    $stranger = issuedReferenceInvoice(Customer::factory()->create(['origin_agency_id' => $this->sfax->id]));
    $small = issuedReferenceInvoice();
    $settle = app(SettleCreditNote::class);

    expect(fn () => $settle->allocate($note, $stranger, '10', $this->agent))->toThrow(CreditNoteException::class, 'même client')
        ->and(fn () => $settle->allocate($note, $note, '10', $this->agent))->toThrow(CreditNoteException::class, 'pas sur un avoir')
        ->and(fn () => $settle->allocate($note, $invoice, '137,851', $this->agent))->toThrow(CreditNoteException::class)
        ->and(fn () => $settle->allocate($note, $small, '10', $this->agentSousse))->toThrow(CreditNoteException::class, 'non autorisée');

    $settle->allocate($note, $small, '100', $this->agent);

    expect(fn () => $settle->allocate($note, $small, '37,851', $this->agent))->toThrow(CreditNoteException::class, 'Il reste 37,850')
        ->and(CreditNoteSettlement::query()->count())->toBe(1);
});
