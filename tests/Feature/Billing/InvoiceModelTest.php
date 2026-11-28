<?php

use App\Domain\Billing\Exceptions\InvoiceLockedException;
use App\Domain\Billing\InvoiceSnapshots;
use App\Enums\FiscalValidationStatus;
use App\Enums\InvoiceLineNature;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\PaymentStatus;
use App\Enums\TaxNature;
use App\Models\Agency;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceTax;
use App\Models\TaxCode;
use App\Models\TaxRule;
use App\Support\Authorization\Role;
use App\Support\Documents\NumberedDocumentException;
use App\Support\Money\Money;
use App\Support\Money\UnsupportedCurrencyException;
use App\Support\Sequences\Series;
use Database\Seeders\FiscalReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Modèle des factures (phase 6, lot L2) : deux statuts (D56), dinar seul (D26),
 * client et émetteur figés (D59), facture validée verrouillée par le modèle et par
 * le constructeur de requêtes, jamais supprimée (scénario 8, première partie, D53).
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(FiscalReferenceSeeder::class);
    $this->agency = Agency::factory()->create();
    $this->customer = Customer::factory()->create(['origin_agency_id' => $this->agency->id]);
});

function draftInvoice(array $attributes = []): Invoice
{
    return Invoice::query()->create([
        'agency_id' => test()->agency->id,
        'type' => InvoiceType::Invoice,
        'customer_id' => test()->customer->id,
        ...$attributes,
    ]);
}

function addLine(Invoice $invoice, string $amount = '100.000'): InvoiceLine
{
    return InvoiceLine::query()->create([
        'invoice_id' => $invoice->id,
        'position' => 1,
        'nature' => InvoiceLineNature::Rental,
        'tax_code_id' => TaxCode::query()->where('code', 'RENTAL')->value('id'),
        'label' => 'Location',
        'quantity' => '1.000',
        'unit_amount_excl_tax' => $amount,
        'total_excl_tax_amount' => $amount,
    ]);
}

/** Validation minimale, comme l'action de validation du lot L4 la fera. */
function validateInvoice(Invoice $invoice, string $number = 'AVI-000001'): Invoice
{
    $invoice->fill([
        'status' => InvoiceStatus::Validated,
        'number' => $number,
        'series' => Series::Invoice,
        'sequence_key' => 'series:invoice',
        'fiscal_year' => 2026,
        'validated_at' => now(),
        'customer_snapshot' => InvoiceSnapshots::customer($invoice->customer),
        'issuer_snapshot' => ['legal_name' => 'Aviator'],
        'total_excl_tax_amount' => '100.000',
        'total_tax_amount' => '19.000',
        'total_incl_tax_amount' => '119.000',
        'stamp_duty_amount' => '1.000',
        'total_due_amount' => '120.000',
    ])->save();

    return $invoice->refresh();
}

it('crée un brouillon sans numéro, en dinars, non payé, modifiable et supprimable avec ses lignes', function () {
    $invoice = draftInvoice();
    addLine($invoice);

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Draft)
        ->and($invoice->payment_status)->toBe(PaymentStatus::Unpaid)
        ->and($invoice->currency)->toBe('TND')
        ->and($invoice->number)->toBeNull();

    $invoice->update(['total_excl_tax_amount' => '50.000']);
    $invoice->lines()->first()?->update(['label' => 'Location corrigée']);
    $invoice->delete();

    expect(Invoice::query()->count())->toBe(0)
        ->and(InvoiceLine::query()->count())->toBe(0);
});

it('refuse de créer une facture numérotée ou déjà validée : le numéro vient à la validation (RG-07)', function (array $attributes) {
    expect(fn () => draftInvoice($attributes))->toThrow(InvoiceLockedException::class, 'brouillon, sans numéro');
    expect(Invoice::query()->count())->toBe(0);
})->with([
    'avec un numéro' => [['number' => 'AVI-000001']],
    'au statut validée' => [['status' => InvoiceStatus::Validated]],
]);

it('refuse un avoir sans facture d\'origine', function () {
    expect(fn () => draftInvoice(['type' => InvoiceType::CreditNote]))->toThrow(InvoiceLockedException::class, 'facture d\'origine');
});

it('refuse toute devise autre que le dinar, sans rien enregistrer (D26)', function () {
    expect(fn () => draftInvoice(['currency' => 'EUR']))->toThrow(UnsupportedCurrencyException::class, 'Devise refusée : « EUR »')
        ->and(Invoice::query()->count())->toBe(0);

    $invoice = draftInvoice();

    expect(fn () => $invoice->update(['currency' => 'USD']))->toThrow(UnsupportedCurrencyException::class)
        ->and($invoice->refresh()->currency)->toBe('TND');
});

it('refuse une validation incomplète (numéro, client ou émetteur figés manquants)', function () {
    $invoice = draftInvoice();

    expect(fn () => $invoice->update(['status' => InvoiceStatus::Validated, 'number' => 'AVI-000001']))
        ->toThrow(InvoiceLockedException::class, 'Validation incomplète')
        ->and($invoice->refresh()->status)->toBe(InvoiceStatus::Draft);
});

it('refuse toute modification d\'une facture validée par le modèle (scénario 8)', function (array $changes) {
    $invoice = validateInvoice(draftInvoice());

    expect(fn () => $invoice->update($changes))->toThrow(InvoiceLockedException::class);

    $fresh = $invoice->refresh();
    expect($fresh->total_due_amount->equals(Money::of('120.000')))->toBeTrue()
        ->and($fresh->number)->toBe('AVI-000001')
        ->and($fresh->customer_id)->toBe($this->customer->id);
})->with([
    'montant' => [['total_due_amount' => '1.000']],
    'numéro' => [['number' => 'AVI-000999']],
    'client' => [['customer_id' => fn () => Customer::factory()->create()->id]],
    'client figé' => [['customer_snapshot' => ['name' => 'Autre']]],
    'retour au brouillon' => [['status' => InvoiceStatus::Draft]],
]);

it('refuse la modification en masse d\'une facture validée, et la laisse pour les brouillons (scénario 8)', function () {
    $validated = validateInvoice(draftInvoice());
    $draft = draftInvoice();

    expect(fn () => Invoice::query()->whereKey($validated->id)->update(['total_due_amount' => '1.000']))->toThrow(InvoiceLockedException::class, 'en masse')
        ->and(fn () => Invoice::query()->update(['due_on' => '2026-12-31']))->toThrow(InvoiceLockedException::class)
        ->and(fn () => Invoice::query()->whereKey($validated->id)->increment('fiscal_year'))->toThrow(InvoiceLockedException::class)
        ->and($validated->refresh()->total_due_amount->equals(Money::of('120.000')))->toBeTrue()
        ->and($validated->fiscal_year)->toBe(2026)
        ->and(Invoice::query()->whereKey($draft->id)->update(['due_on' => '2026-12-31']))->toBe(1);
});

it('ne supprime jamais une facture validée : modèle, requête en masse et policy (super admin compris)', function () {
    $invoice = validateInvoice(draftInvoice());
    $superAdmin = userWithProfile(Role::SuperAdmin);

    expect(fn () => $invoice->delete())->toThrow(NumberedDocumentException::class)
        ->and(fn () => Invoice::query()->whereKey($invoice->id)->delete())->toThrow(NumberedDocumentException::class)
        ->and($superAdmin->can('delete', $invoice))->toBeFalse()
        ->and($superAdmin->can('update', $invoice))->toBeFalse()
        ->and(Invoice::query()->whereKey($invoice->id)->exists())->toBeTrue();
});

it('verrouille les lignes et les taxes d\'une facture validée (modèle et requêtes en masse)', function () {
    $invoice = draftInvoice();
    $line = addLine($invoice);
    $rule = TaxRule::query()->whereHas('taxCode', fn ($q) => $q->where('code', 'RENTAL'))->firstOrFail();
    $tax = InvoiceTax::query()->create([
        'invoice_id' => $invoice->id, 'tax_rule_id' => $rule->id, 'tax_code_id' => $rule->tax_code_id, 'code' => 'RENTAL',
        'nature' => TaxNature::Taxable, 'rate' => '19.0000', 'base_amount' => '100.000', 'tax_amount' => '19.000',
        'validation_status' => FiscalValidationStatus::Confirmed, 'rule_snapshot' => ['rate' => '19.0000'],
    ]);
    validateInvoice($invoice);

    expect(fn () => $line->update(['unit_amount_excl_tax' => '1.000']))->toThrow(InvoiceLockedException::class)
        ->and(fn () => $line->delete())->toThrow(InvoiceLockedException::class)
        ->and(fn () => addLine($invoice, '5.000'))->toThrow(InvoiceLockedException::class)
        ->and(fn () => InvoiceLine::query()->where('invoice_id', $invoice->id)->update(['label' => 'X']))->toThrow(InvoiceLockedException::class)
        ->and(fn () => InvoiceLine::query()->where('invoice_id', $invoice->id)->delete())->toThrow(InvoiceLockedException::class)
        ->and(fn () => $tax->update(['tax_amount' => '0.000']))->toThrow(InvoiceLockedException::class)
        ->and(fn () => InvoiceTax::query()->where('invoice_id', $invoice->id)->delete())->toThrow(InvoiceLockedException::class)
        ->and(InvoiceLine::query()->sole()->label)->toBe('Location')
        ->and(InvoiceTax::query()->sole()->tax_amount->equals(Money::of('19.000')))->toBeTrue();
});

it('suit le cycle brouillon → validée → émise → avoir total, informations d\'émission écrites une fois', function () {
    $invoice = validateInvoice(draftInvoice());

    $invoice->update(['payment_status' => PaymentStatus::PartiallyPaid]);
    $invoice->update(['status' => InvoiceStatus::Issued, 'issued_at' => now(), 'issued_pdf_path' => 'invoices/1.pdf', 'issued_pdf_sha256' => str_repeat('a', 64)]);

    expect(fn () => $invoice->update(['issued_pdf_sha256' => str_repeat('b', 64)]))->toThrow(InvoiceLockedException::class)
        ->and(fn () => $invoice->update(['status' => InvoiceStatus::Cancelled, 'cancellation_reason' => 'Erreur']))->toThrow(InvoiceLockedException::class, 'ne peut pas devenir « Annulée »');

    $invoice->refresh()->update(['status' => InvoiceStatus::Credited]);

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Credited)
        ->and($invoice->payment_status)->toBe(PaymentStatus::PartiallyPaid)
        ->and($invoice->issued_pdf_sha256)->toBe(str_repeat('a', 64))
        ->and(fn () => $invoice->update(['status' => InvoiceStatus::Issued]))->toThrow(InvoiceLockedException::class);
});

it('annule une facture validée jamais émise, motif obligatoire, numéro conservé (Q79)', function () {
    $invoice = validateInvoice(draftInvoice());

    expect(fn () => $invoice->update(['status' => InvoiceStatus::Cancelled, 'cancelled_at' => now()]))->toThrow(InvoiceLockedException::class, 'motif');

    $invoice->refresh()->update(['status' => InvoiceStatus::Cancelled, 'cancellation_reason' => 'Client erroné', 'cancelled_at' => now()]);

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Cancelled)
        ->and($invoice->number)->toBe('AVI-000001')
        ->and(fn () => $invoice->update(['cancellation_reason' => 'Autre motif']))->toThrow(InvoiceLockedException::class);
});

it('fige le client entreprise avec son RNE, et l\'émetteur avec son agence (D59, E8)', function () {
    $company = Customer::factory()->company()->create(['origin_agency_id' => $this->agency->id, 'trade_register' => 'B0123452026']);
    $invoice = validateInvoice(draftInvoice(['customer_id' => $company->id]));

    $company->update(['company_name' => 'Nouvelle raison sociale', 'trade_register' => 'B9999999999']);

    $issuer = InvoiceSnapshots::issuer(new Company(['legal_name' => 'Aviator SARL', 'tax_id' => '1234567/A/M/000', 'trade_register' => 'B000', 'invoice_legal_mentions' => 'Mentions']), $this->agency);

    expect($invoice->refresh()->customer_snapshot['trade_register'])->toBe('B0123452026')
        ->and($invoice->customer_snapshot['tax_id'])->toBe($company->tax_id)
        ->and($invoice->customer_snapshot['name'])->not->toBe('Nouvelle raison sociale')
        ->and($invoice->customer_snapshot['country'])->toBe('TN')
        ->and(InvoiceSnapshots::customer(Customer::factory()->create(['trade_register' => 'B111']))['trade_register'])->toBeNull()
        ->and($issuer['agency']['code'])->toBe($this->agency->code)
        ->and($issuer['legal_mentions'])->toBe('Mentions')
        ->and($issuer['trade_register'])->toBe('B000');
});

it('ouvre la modification et la suppression d\'un brouillon aux seuls profils de son agence', function () {
    $other = Agency::factory()->create();
    $draft = draftInvoice();
    $mine = userWithProfile(Role::AgencyUser, ['agency_id' => $this->agency->id]);
    $stranger = userWithProfile(Role::AgencyUser, ['agency_id' => $other->id]);

    expect($mine->can('update', $draft))->toBeTrue()
        ->and($mine->can('delete', $draft))->toBeTrue()
        ->and($stranger->can('view', $draft))->toBeFalse()
        ->and($stranger->can('update', $draft))->toBeFalse()
        ->and($stranger->can('delete', $draft))->toBeFalse()
        ->and($mine->can('deleteAny', Invoice::class))->toBeFalse();
});
