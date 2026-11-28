<?php

use App\Domain\Billing\Actions\CreateCreditNote;
use App\Domain\Billing\Actions\IssueInvoice;
use App\Domain\Billing\Actions\SettleCreditNote;
use App\Domain\Billing\Actions\ValidateInvoice;
use App\Domain\Billing\Cash\CashRegister;
use App\Domain\Billing\Exceptions\CashException;
use App\Domain\Billing\Payments\CancelPayment;
use App\Domain\Billing\Payments\RecordPayment;
use App\Enums\InvoiceLineNature;
use App\Enums\InvoiceType;
use App\Models\Agency;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\TaxCode;
use App\Support\Authorization\Role;
use App\Support\Files\PrivateFiles;
use Carbon\CarbonImmutable;
use Database\Seeders\FiscalReferenceSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

/*
 * Journal de caisse (phase 6, lot L8, E-09, Q55, D57) : une caisse par agence et par
 * jour, fond de caisse, espèces refusées sans caisse ouverte, clôture avec montant
 * compté et écart motivé.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow('2027-03-20 08:00');
    Storage::fake(PrivateFiles::DISK);
    $this->seed(FiscalReferenceSeeder::class);
    $this->seed(PaymentMethodSeeder::class);
    PaymentMethod::query()->update(['is_active' => true]);
    Company::query()->forceCreate(['id' => Company::SINGLETON_ID, 'legal_name' => 'Aviator SARL', 'invoice_legal_mentions' => 'Mentions de test']);

    $this->sfax = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->sousse = Agency::factory()->create(['code' => 'SOU', 'name' => 'Sousse']);
    $this->agent = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sfax->id]);
    $this->agentSousse = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sousse->id]);
    $this->admin = withTwoFactor(userWithProfile(Role::Admin));
    $this->customer = Customer::factory()->create(['origin_agency_id' => $this->sfax->id]);
    $this->register = app(CashRegister::class);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function cashPayment(string $amount, string $method = 'cash', array $extra = []): Payment
{
    return app(RecordPayment::class)->handle([
        'agency_id' => test()->sfax->id, 'customer_id' => test()->customer->id,
        'payment_method_id' => (int) PaymentMethod::query()->where('code', $method)->value('id'), 'amount' => $amount, ...$extra,
    ], test()->agent);
}

it('refuse les espèces sans caisse ouverte, pas le chèque (Q55)', function () {
    expect(fn () => cashPayment('50'))->toThrow(CashException::class, 'Aucune caisse ouverte')
        ->and(Payment::query()->count())->toBe(0)
        ->and(cashPayment('50', 'cheque', ['reference' => 'CHQ 1', 'bank_name' => 'BIAT'])->cash_session_id)->toBeNull();
});

it('ouvre une seule caisse par agence et par jour, après la clôture de la précédente', function () {
    $session = $this->register->open($this->sfax, '200,000', $this->agent);

    expect($session->business_date->format('Y-m-d'))->toBe('2027-03-20')
        ->and($session->opening_amount->toDecimalString())->toBe('200.000')
        ->and($session->opened_by)->toBe($this->agent->id)
        ->and(fn () => $this->register->open($this->sfax, '0', $this->agent))->toThrow(CashException::class, 'n\'est pas clôturée');

    $this->register->close($session, '200,000', null, $this->agent);

    expect(fn () => $this->register->open($this->sfax, '0', $this->agent))->toThrow(CashException::class, 'une seule caisse par agence et par jour');

    // Le lendemain : nouvelle caisse ; l'autre agence a la sienne.
    CarbonImmutable::setTestNow('2027-03-21 08:00');

    expect($this->register->open($this->sfax, '0', $this->agent)->business_date->format('Y-m-d'))->toBe('2027-03-21')
        ->and($this->register->open($this->sousse, '0', $this->agentSousse)->agency_id)->toBe($this->sousse->id)
        ->and(CashSession::query()->withoutGlobalScopes()->count())->toBe(3);
});

it('clôture au montant attendu : fond, espèces reçues, remboursements, encaissements annulés exclus (E-09)', function () {
    $session = $this->register->open($this->sfax, '100,000', $this->agent);
    $first = cashPayment('80,000');
    cashPayment('45,500');
    $cheque = cashPayment('300', 'cheque', ['reference' => 'CHQ 2', 'bank_name' => 'STB']);
    app(CancelPayment::class)->handle($first, 'Saisi en double par erreur', $this->agent);

    // Remboursement d'avoir en espèces : sort de la caisse.
    $draft = Invoice::query()->create(['agency_id' => $this->sfax->id, 'type' => InvoiceType::Invoice, 'customer_id' => $this->customer->id]);
    InvoiceLine::query()->create([
        'invoice_id' => $draft->id, 'position' => 1, 'nature' => InvoiceLineNature::Free, 'tax_code_id' => TaxCode::query()->where('code', 'RENTAL')->value('id'),
        'label' => 'Location', 'quantity' => '1.000', 'unit_amount_excl_tax' => '100.000', 'total_excl_tax_amount' => '100.000',
    ]);
    $invoice = app(IssueInvoice::class)->handle(app(ValidateInvoice::class)->handle($draft, $this->agent), $this->agent);
    $note = app(CreateCreditNote::class)->handle($invoice, [$invoice->lines()->value('id') => '10'], 'Geste commercial accordé au client', $this->agent);
    $refund = app(SettleCreditNote::class)->refund($note, '11,900', PaymentMethod::query()->where('code', 'cash')->sole(), null, $this->agent);

    expect($refund->cash_session_id)->toBe($session->id)
        ->and($cheque->cash_session_id)->toBeNull()
        ->and(CashRegister::expected($session)->toDecimalString())->toBe('133.600');

    $closed = $this->register->close($session, '133,600', null, $this->agent);

    expect($closed->closed_by)->toBe($this->agent->id)
        ->and($closed->expected_amount?->toDecimalString())->toBe('133.600')
        ->and($closed->difference_amount?->isZero())->toBeTrue()
        ->and($closed->difference_reason)->toBeNull()
        // Caisse clôturée : plus d'espèces, plus de modification.
        ->and(fn () => cashPayment('10'))->toThrow(CashException::class, 'Aucune caisse ouverte')
        ->and(fn () => $this->register->close($closed, '133,600', null, $this->agent))->toThrow(CashException::class, 'non autorisée')
        ->and(fn () => $closed->update(['counted_amount' => '0.000']))->toThrow(LogicException::class)
        ->and(fn () => $closed->delete())->toThrow(LogicException::class);
});

it('exige un motif pour tout écart entre le compté et l\'attendu (Q55)', function () {
    $session = $this->register->open($this->sfax, '50,000', $this->agent);
    cashPayment('20,000');

    expect(fn () => $this->register->close($session, '69,500', null, $this->agent))->toThrow(CashException::class, 'Écart de -0,500')
        ->and(fn () => $this->register->close($session, '69,500', 'court', $this->agent))->toThrow(CashException::class, 'motif')
        ->and($session->refresh()->isOpen())->toBeTrue();

    $closed = $this->register->close($session, '69,500', 'Erreur de rendu de monnaie', $this->agent);

    expect($closed->difference_amount?->toDecimalString())->toBe('-0.500')
        ->and($closed->difference_reason)->toBe('Erreur de rendu de monnaie')
        // Écart visible de l'admin (portée de toutes les agences).
        ->and($this->admin->can('view', $closed))->toBeTrue()
        ->and($this->agentSousse->can('view', $closed))->toBeFalse();
});

it('n\'annule plus un encaissement en espèces d\'une caisse clôturée', function () {
    $session = $this->register->open($this->sfax, '0', $this->agent);
    $payment = cashPayment('40');
    $this->register->close($session, '40', null, $this->agent);

    expect(fn () => app(CancelPayment::class)->handle($payment, 'Encaissement saisi par erreur', $this->agent))->toThrow(CashException::class, 'clôturée')
        ->and($payment->refresh()->status)->toBe(Payment::RECORDED);
});

it('réserve l\'ouverture et la clôture aux profils de l\'agence (RG-13)', function () {
    expect(fn () => $this->register->open($this->sfax, '0', $this->agentSousse))->toThrow(CashException::class, 'non autorisée');

    $session = $this->register->open($this->sfax, '0', $this->agent);

    expect(fn () => $this->register->close($session, '0', null, $this->agentSousse))->toThrow(CashException::class, 'non autorisée')
        ->and(fn () => $this->register->open($this->sfax, '-1', $this->agent))->toThrow(CashException::class)
        ->and($this->register->close($session, '0', null, $this->agent)->isOpen())->toBeFalse();
});
