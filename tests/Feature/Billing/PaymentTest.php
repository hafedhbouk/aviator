<?php

use App\Domain\Billing\Actions\CancelInvoice;
use App\Domain\Billing\Actions\CreateCreditNote;
use App\Domain\Billing\Actions\IssueInvoice;
use App\Domain\Billing\Actions\SettleCreditNote;
use App\Domain\Billing\Actions\ValidateInvoice;
use App\Domain\Billing\Exceptions\CreditNoteException;
use App\Domain\Billing\Exceptions\InvoiceValidationException;
use App\Domain\Billing\Exceptions\PaymentException;
use App\Domain\Billing\InvoiceBalance;
use App\Domain\Billing\Payments\CancelPayment;
use App\Domain\Billing\Payments\PaymentAllocator;
use App\Domain\Billing\Payments\RecordPayment;
use App\Domain\Billing\Payments\UnpaidInvoices;
use App\Enums\ContractStatus;
use App\Enums\InvoiceLineNature;
use App\Enums\InvoiceType;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Models\Agency;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentMethod;
use App\Models\Reservation;
use App\Models\TaxCode;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Support\Authorization\Role;
use App\Support\Files\PrivateFiles;
use App\Support\Money\Money;
use App\Support\Money\UnsupportedCurrencyException;
use Carbon\CarbonImmutable;
use Database\Seeders\FiscalReferenceSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
 * Encaissements (phase 6, lot L7, E-06, E-07, E17, Q81, D37, D57, RG-11) et
 * **scénario 5** : facture réglée en deux paiements partiels. Imputations simultanées :
 * tests/Feature/Concurrency/PaymentAllocationConcurrencyTest.php.
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
    $this->superAdmin = withTwoFactor(userWithProfile(Role::SuperAdmin));
});

afterEach(fn () => CarbonImmutable::setTestNow());

function method(string $code): int
{
    return (int) PaymentMethod::query()->where('code', $code)->value('id');
}

/** Facture émise de la §5 (137,850 à payer), ou d'un montant HT donné à 19 %. */
function payableInvoice(string $amount = '115.000', array $attributes = []): Invoice
{
    $invoice = Invoice::query()->create(['agency_id' => test()->sfax->id, 'type' => InvoiceType::Invoice, 'customer_id' => test()->customer->id, ...$attributes]);
    InvoiceLine::query()->create([
        'invoice_id' => $invoice->id, 'position' => 1, 'nature' => InvoiceLineNature::Free, 'tax_code_id' => TaxCode::query()->where('code', 'RENTAL')->value('id'),
        'label' => 'Location', 'quantity' => '1.000', 'unit_amount_excl_tax' => $amount, 'total_excl_tax_amount' => $amount,
    ]);

    return app(IssueInvoice::class)->handle(app(ValidateInvoice::class)->handle($invoice, test()->superAdmin), test()->superAdmin);
}

function pay(string $amount, array $allocations = [], string $method = 'cash', array $extra = []): Payment
{
    return app(RecordPayment::class)->handle([
        'agency_id' => test()->sfax->id, 'customer_id' => test()->customer->id, 'payment_method_id' => method($method),
        'amount' => $amount, 'allocations' => $allocations, ...$extra,
    ], test()->agent);
}

it('règle une facture en deux paiements partiels : non payée, partiellement payée, payée (scénario 5)', function () {
    $invoice = payableInvoice();

    expect($invoice->payment_status)->toBe(PaymentStatus::Unpaid)
        ->and($invoice->total_due_amount->toDecimalString())->toBe('137.850');

    $first = pay('50,000', [$invoice->id => '50,000']);

    expect($first->status)->toBe(Payment::RECORDED)
        ->and($first->currency)->toBe('TND')
        ->and($invoice->refresh()->payment_status)->toBe(PaymentStatus::PartiallyPaid)
        ->and(InvoiceBalance::remaining($invoice)->toDecimalString())->toBe('87.850');

    pay('87,850', [$invoice->id => '87,850'], 'cheque', ['reference' => 'CHQ 1234567', 'bank_name' => 'BIAT']);

    expect($invoice->refresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and(InvoiceBalance::remaining($invoice)->isZero())->toBeTrue()
        ->and(fn () => pay('0,001', [$invoice->id => '0,001']))->toThrow(PaymentException::class, 'il reste 0,000 DT dû')
        ->and(Payment::query()->count())->toBe(2);
});

it('propose l\'imputation la plus ancienne d\'abord ; la part non imputée reste un acompte (Q81, D57)', function () {
    $older = payableInvoice('100.000');
    CarbonImmutable::setTestNow('2027-03-21 10:00');
    $newer = payableInvoice('100.000');
    $allocator = app(PaymentAllocator::class);

    $proposal = $allocator->propose($this->customer->id, Money::of('200.000'), $this->agent);

    expect(array_keys($proposal))->toBe([$older->id, $newer->id])
        ->and($proposal[$older->id]->toDecimalString())->toBe('120.000')
        ->and($proposal[$newer->id]->toDecimalString())->toBe('80.000');

    // L'agent confirme une imputation différente : 120 sur la plus ancienne, 30 laissés en acompte.
    $payment = pay('150,000', [$older->id => '120,000']);

    expect(PaymentAllocator::unallocated($payment->refresh())->toDecimalString())->toBe('30.000')
        ->and($older->refresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and($newer->refresh()->payment_status)->toBe(PaymentStatus::Unpaid);

    // L'acompte s'impute plus tard, jamais au-delà de ce qui reste.
    expect(fn () => $allocator->allocate($payment, [$newer->id => '30,001'], $this->agent))->toThrow(PaymentException::class, 'il reste 30,000 DT non imputé');

    $allocator->allocate($payment, [$newer->id => '30,000'], $this->agent);

    expect(PaymentAllocator::unallocated($payment->refresh())->isZero())->toBeTrue()
        ->and($newer->refresh()->payment_status)->toBe(PaymentStatus::PartiallyPaid);
});

it('exige la référence et la banque du chèque et du virement, et refuse une autre devise que le dinar (E17, D26)', function () {
    expect(fn () => pay('10', [], 'cheque', ['bank_name' => 'BIAT']))->toThrow(PaymentException::class, 'exige une référence')
        ->and(fn () => pay('10', [], 'transfer', ['reference' => 'VIR-1']))->toThrow(PaymentException::class, 'nom de la banque')
        ->and(fn () => pay('10', [], 'cash', ['currency' => 'EUR']))->toThrow(UnsupportedCurrencyException::class)
        ->and(fn () => pay('0'))->toThrow(ValidationException::class)
        ->and(fn () => pay('10', [], 'cash', ['received_at' => '2027-03-21 10:00']))->toThrow(ValidationException::class)
        ->and(Payment::query()->count())->toBe(0);

    $transfer = pay('10', [], 'transfer', ['reference' => 'VIR-2027-001', 'bank_name' => 'STB']);

    expect($transfer->bank_name)->toBe('STB')->and($transfer->reference)->toBe('VIR-2027-001');
});

it('rattache un acompte à la réservation puis l\'impute sur la facture du contrat (D37, Q40)', function () {
    $category = VehicleCategory::factory()->create();
    $reservation = Reservation::query()->create([
        'number' => 'RES-2027-00001', 'agency_id' => $this->sfax->id, 'return_agency_id' => $this->sfax->id, 'customer_id' => $this->customer->id,
        'vehicle_category_id' => $category->id, 'starts_at' => '2027-03-10 09:00', 'ends_at' => '2027-03-13 09:00',
        'status' => ReservationStatus::Confirmed, 'channel' => 'counter', 'quote' => [], 'estimated_total_amount' => '300.000', 'discount_amount' => '0.000',
    ]);
    $deposit = pay('100,000', [], 'cash', ['reservation_id' => $reservation->id]);

    expect($deposit->reservation_id)->toBe($reservation->id)
        ->and(PaymentAllocator::unallocated($deposit->refresh())->toDecimalString())->toBe('100.000');

    $vehicle = Vehicle::factory()->create(['agency_id' => $this->sfax->id, 'vehicle_category_id' => $category->id]);
    $contract = Contract::query()->create([
        'number' => 'CTR-2027-00001', 'agency_id' => $this->sfax->id, 'return_agency_id' => $this->sfax->id, 'reservation_id' => $reservation->id,
        'vehicle_id' => $vehicle->id, 'vehicle_category_id' => $category->id, 'customer_id' => $this->customer->id, 'starts_at' => '2027-03-10 09:00',
        'ends_at' => '2027-03-13 09:00', 'status' => ContractStatus::Returned, 'mileage_policy' => Contract::MILEAGE_UNLIMITED,
        'fuel_policy' => Contract::FUEL_SAME_LEVEL, 'daily_rate_amount' => '100.000', 'rental_amount' => '300.000', 'options_amount' => '0.000',
        'total_amount' => '300.000', 'quote' => [],
    ]);
    $invoice = payableInvoice('300.000', ['contract_id' => $contract->id]);
    $allocator = app(PaymentAllocator::class);

    expect(array_map(fn (Payment $payment): int => $payment->id, $allocator->depositsFor($invoice)))->toBe([$deposit->id]);

    $allocator->allocate($deposit, [$invoice->id => '100,000'], $this->agent);

    expect($invoice->refresh()->payment_status)->toBe(PaymentStatus::PartiallyPaid)
        ->and(InvoiceBalance::remaining($invoice)->toDecimalString())->toBe('258.000')
        ->and($allocator->depositsFor($invoice))->toBe([]);

    $other = Customer::factory()->create(['origin_agency_id' => $this->sfax->id]);

    expect(fn () => app(RecordPayment::class)->handle([
        'agency_id' => $this->sfax->id, 'customer_id' => $other->id, 'payment_method_id' => method('cash'), 'amount' => '10', 'reservation_id' => $reservation->id,
    ], $this->agent))->toThrow(PaymentException::class, 'pas celle de ce client');
});

it('annule un encaissement avec motif, jamais supprimé : ses imputations ne comptent plus (RG-11)', function () {
    $invoice = payableInvoice();
    $payment = pay('137,850', [$invoice->id => '137,850']);

    expect($invoice->refresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and(fn () => app(CancelPayment::class)->handle($payment, 'court', $this->agent))->toThrow(PaymentException::class, 'motif')
        ->and(fn () => app(CancelPayment::class)->handle($payment, 'Chèque sans provision', $this->agentSousse))->toThrow(PaymentException::class, 'non autorisée')
        ->and(fn () => $payment->delete())->toThrow(LogicException::class)
        ->and(fn () => $payment->update(['amount' => '1.000']))->toThrow(LogicException::class)
        ->and(fn () => PaymentAllocation::query()->sole()->delete())->toThrow(LogicException::class);

    $cancelled = app(CancelPayment::class)->handle($payment, 'Chèque sans provision', $this->agent);

    expect($cancelled->status)->toBe(Payment::CANCELLED)
        ->and($cancelled->cancellation_reason)->toBe('Chèque sans provision')
        ->and($invoice->refresh()->payment_status)->toBe(PaymentStatus::Unpaid)
        ->and(InvoiceBalance::remaining($invoice)->toDecimalString())->toBe('137.850')
        ->and(PaymentAllocation::query()->count())->toBe(1)
        ->and(fn () => app(PaymentAllocator::class)->allocate($cancelled, [$invoice->id => '1'], $this->agent))->toThrow(PaymentException::class, 'non autorisée')
        ->and(fn () => app(CancelPayment::class)->handle($cancelled, 'Deuxième annulation', $this->agent))->toThrow(PaymentException::class, 'non autorisée');
});

it('refuse d\'imputer sur la facture d\'un autre client, un brouillon, une facture annulée ou d\'une autre agence', function () {
    $foreign = payableInvoice('100.000', ['customer_id' => Customer::factory()->create(['origin_agency_id' => $this->sfax->id])->id]);
    $cancelled = app(ValidateInvoice::class)->handle(tap(Invoice::query()->create(['agency_id' => $this->sfax->id, 'type' => InvoiceType::Invoice, 'customer_id' => $this->customer->id]), function (Invoice $invoice): void {
        InvoiceLine::query()->create([
            'invoice_id' => $invoice->id, 'position' => 1, 'nature' => InvoiceLineNature::Free, 'tax_code_id' => TaxCode::query()->where('code', 'RENTAL')->value('id'),
            'label' => 'Location', 'quantity' => '1.000', 'unit_amount_excl_tax' => '50.000', 'total_excl_tax_amount' => '50.000',
        ]);
    }), $this->agent);
    app(CancelInvoice::class)->handle($cancelled, 'Erreur sur le client facturé', $this->agent);
    $draft = Invoice::query()->create(['agency_id' => $this->sfax->id, 'type' => InvoiceType::Invoice, 'customer_id' => $this->customer->id]);
    $sousse = payableInvoice('100.000', ['agency_id' => $this->sousse->id]);

    expect(fn () => pay('10', [$foreign->id => '10']))->toThrow(PaymentException::class, 'pas celle du client')
        ->and(fn () => pay('10', [$cancelled->id => '10']))->toThrow(PaymentException::class, 'validée')
        ->and(fn () => pay('10', [$draft->id => '10']))->toThrow(PaymentException::class, 'validée')
        ->and(fn () => pay('10', [$sousse->id => '10']))->toThrow(PaymentException::class, 'non autorisée')
        ->and(fn () => app(RecordPayment::class)->handle(['agency_id' => $this->sousse->id, 'customer_id' => $this->customer->id, 'payment_method_id' => method('cash'), 'amount' => '10'], $this->agent))
        ->toThrow(PaymentException::class, 'non autorisée')
        ->and(Payment::query()->count())->toBe(0);
});

it('n\'annule plus sans avoir une facture qui a reçu un encaissement (Q79)', function () {
    $invoice = app(ValidateInvoice::class)->handle(tap(Invoice::query()->create(['agency_id' => $this->sfax->id, 'type' => InvoiceType::Invoice, 'customer_id' => $this->customer->id]), function (Invoice $invoice): void {
        InvoiceLine::query()->create([
            'invoice_id' => $invoice->id, 'position' => 1, 'nature' => InvoiceLineNature::Free, 'tax_code_id' => TaxCode::query()->where('code', 'RENTAL')->value('id'),
            'label' => 'Location', 'quantity' => '1.000', 'unit_amount_excl_tax' => '100.000', 'total_excl_tax_amount' => '100.000',
        ]);
    }), $this->agent);
    $payment = pay('20', [$invoice->id => '20']);

    expect(fn () => app(CancelInvoice::class)->handle($invoice, 'Erreur sur le client facturé', $this->agent))->toThrow(InvoiceValidationException::class, 'annulez d\'abord l\'encaissement');

    app(CancelPayment::class)->handle($payment, 'Encaissement saisi par erreur', $this->agent);

    expect(app(CancelInvoice::class)->handle($invoice, 'Erreur sur le client facturé', $this->agent)->status->value)->toBe('cancelled');
});

it('compte les avoirs imputés dans le reste dû et le statut de paiement (E19)', function () {
    $invoice = payableInvoice();
    $note = app(CreateCreditNote::class)->handle($invoice, [$invoice->lines()->value('id') => '42,017'], 'Geste commercial accordé au client', $this->agent);
    pay('100', [$invoice->id => '100']);

    // Reste dû 37,850 : l'avoir de 50,000 ne s'y impute pas au-delà.
    expect(fn () => app(SettleCreditNote::class)->allocate($note, $invoice, '50,000', $this->agent))->toThrow(CreditNoteException::class, 'il reste 37,850');

    app(SettleCreditNote::class)->allocate($note, $invoice, '37,850', $this->agent);

    expect(InvoiceBalance::remaining($invoice->refresh())->isZero())->toBeTrue()
        ->and($invoice->payment_status)->toBe(PaymentStatus::Paid)
        // Cumul tenu sur la facture = somme des imputations enregistrées.
        ->and(InvoiceBalance::fromRecords($invoice)->equals($invoice->paid_amount))->toBeTrue()
        ->and(SettleCreditNote::remaining($note->refresh())->toDecimalString())->toBe('12.150');
});

it('liste les impayés avec leur reste dû et leur ancienneté, dans la portée de l\'agence (E-07)', function () {
    $old = payableInvoice('100.000');
    pay('19', [$old->id => '19']);
    payableInvoice('50.000', ['agency_id' => $this->sousse->id]);
    $paid = payableInvoice('10.000');
    pay('12,900', [$paid->id => '12,900']);

    CarbonImmutable::setTestNow('2027-05-04 10:00');
    $this->actingAs($this->agent);
    $rows = app(UnpaidInvoices::class)->list();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['invoice']->id)->toBe($old->id)
        ->and($rows[0]['remaining']->toDecimalString())->toBe('101.000')
        ->and($rows[0]['days_overdue'])->toBe(45)
        ->and($rows[0]['bucket'])->toBe('31-60 jours')
        ->and(UnpaidInvoices::bucket(0))->toBe('à échoir')
        ->and(UnpaidInvoices::bucket(91))->toBe('plus de 90 jours');
});
