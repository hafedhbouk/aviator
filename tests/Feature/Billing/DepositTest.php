<?php

use App\Domain\Approvals\Actions\DecideApproval;
use App\Domain\Billing\Actions\IssueInvoice;
use App\Domain\Billing\Actions\ManageLineJustifications;
use App\Domain\Billing\Actions\ValidateInvoice;
use App\Domain\Billing\Cash\CashRegister;
use App\Domain\Billing\Deposits\DepositDesk;
use App\Domain\Billing\Deposits\OpenDeposits;
use App\Domain\Billing\Exceptions\DepositException;
use App\Domain\Billing\Exceptions\PaymentException;
use App\Domain\Billing\InvoiceBalance;
use App\Domain\Billing\Payments\CancelPayment;
use App\Domain\Billing\Payments\RecordPayment;
use App\Enums\ApprovalStatus;
use App\Enums\ContractStatus;
use App\Enums\DepositStatus;
use App\Enums\InvoiceLineNature;
use App\Enums\InvoiceType;
use App\Enums\JustificationKind;
use App\Enums\PaymentStatus;
use App\Models\Agency;
use App\Models\ApprovalRequest;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositMovement;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\TaxCode;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Support\Authorization\Role;
use App\Support\Files\PrivateFiles;
use Carbon\CarbonImmutable;
use Database\Seeders\FiscalReferenceSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

/*
 * Cautions (phase 6, lot L10, module F, RG-08, Q51, Q52, Q54, Q56, D46, D58, E23) et
 * **scénario 6** : caution partiellement restituée après une retenue approuvée par le
 * super admin. Restitutions simultanées : tests/Feature/Concurrency/DepositConcurrencyTest.php.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow('2027-03-10 09:00');
    Storage::fake(PrivateFiles::DISK);
    $this->seed(FiscalReferenceSeeder::class);
    $this->seed(PaymentMethodSeeder::class);
    PaymentMethod::query()->update(['is_active' => true]);
    Company::query()->forceCreate(['id' => Company::SINGLETON_ID, 'legal_name' => 'Aviator SARL', 'invoice_legal_mentions' => 'Mentions de test']);

    $this->sfax = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->sousse = Agency::factory()->create(['code' => 'SOU', 'name' => 'Sousse']);
    $this->agent = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sfax->id]);
    $this->agentSousse = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sousse->id]);
    $this->superAdmin = withTwoFactor(userWithProfile(Role::SuperAdmin));
    $this->customer = Customer::factory()->create(['origin_agency_id' => $this->sfax->id]);
    $vehicle = Vehicle::factory()->create(['agency_id' => $this->sfax->id, 'vehicle_category_id' => VehicleCategory::factory()->create()->id]);

    $this->contract = Contract::query()->create([
        'number' => 'CTR-2027-00001', 'agency_id' => $this->sfax->id, 'return_agency_id' => $this->sfax->id, 'vehicle_id' => $vehicle->id,
        'vehicle_category_id' => $vehicle->vehicle_category_id, 'customer_id' => $this->customer->id, 'starts_at' => '2027-03-10 09:00',
        'ends_at' => '2027-03-13 09:00', 'departed_at' => '2027-03-10 09:00', 'status' => ContractStatus::Active,
        'mileage_policy' => Contract::MILEAGE_UNLIMITED, 'fuel_policy' => Contract::FUEL_SAME_LEVEL, 'daily_rate_amount' => '100.000',
        'rental_amount' => '300.000', 'options_amount' => '0.000', 'total_amount' => '300.000', 'quote' => [], 'deposit_expected_amount' => '500.000',
    ]);

    $this->desk = app(DepositDesk::class);
    app(CashRegister::class)->open($this->sfax, '0', $this->agent);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function methodId(string $code): int
{
    return (int) PaymentMethod::query()->where('code', $code)->value('id');
}

function receiveDeposit(string $amount = '500,000', string $method = 'cash', array $extra = []): Deposit
{
    return test()->desk->receive(test()->contract, ['amount' => $amount, 'payment_method_id' => methodId($method), ...$extra], test()->agent);
}

function returnContract(): void
{
    CarbonImmutable::setTestNow('2027-03-13 09:30');
    test()->contract->forceFill(['status' => ContractStatus::Returned, 'returned_at' => now()])->save();
    $register = app(CashRegister::class);
    $open = CashSession::query()->withoutGlobalScopes()->whereNull('closed_at')->where('agency_id', test()->sfax->id)->first();

    if ($open !== null) {
        $register->close($open, CashRegister::expected($open)->toDecimalString(), null, test()->agent);
    }

    $register->open(test()->sfax, '0', test()->agent);
}

/** Facture de frais émise du contrat : 100,000 HT à 19 %, 120,000 à payer. */
function feeInvoice(): Invoice
{
    $invoice = Invoice::query()->create(['agency_id' => test()->sfax->id, 'type' => InvoiceType::Supplementary, 'customer_id' => test()->customer->id, 'contract_id' => test()->contract->id]);
    InvoiceLine::query()->create([
        'invoice_id' => $invoice->id, 'position' => 1, 'nature' => InvoiceLineNature::Free, 'tax_code_id' => TaxCode::query()->where('code', 'REPAIR_SERVICE')->value('id'),
        'label' => 'Réparation de la portière', 'quantity' => '1.000', 'unit_amount_excl_tax' => '100.000', 'total_excl_tax_amount' => '100.000',
    ]);
    $line = $invoice->lines()->sole();
    app(ManageLineJustifications::class)->attachDocument($line, JustificationKind::RepairQuote, depositUpload('devis.pdf'), 'Devis du carrossier', test()->agent);

    return app(IssueInvoice::class)->handle(app(ValidateInvoice::class)->handle($invoice, test()->agent), test()->agent);
}

function depositUpload(string $name): string
{
    $path = PrivateFiles::UPLOAD_DIRECTORY.'/'.$name;
    Storage::disk(PrivateFiles::DISK)->put($path, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");

    return $path;
}

function requestRetention(Deposit $deposit, Invoice $invoice, string $amount = '120,000'): ApprovalRequest
{
    return test()->desk->requestRetention($deposit, [
        'amount' => $amount, 'reason' => 'Portière enfoncée au retour, constat signé', 'invoice_id' => $invoice->id, 'file' => depositUpload('constat-'.uniqid().'.pdf'),
    ], test()->agent);
}

it('restitue partiellement une caution après une retenue approuvée par le super admin (scénario 6)', function () {
    $deposit = receiveDeposit();

    expect($deposit->receipt_number)->toBe('CAU-2027-00001')
        ->and($deposit->status())->toBe(DepositStatus::Received)
        ->and($deposit->amount_received->toDecimalString())->toBe('500.000');

    returnContract();
    $invoice = feeInvoice();
    $request = requestRetention($deposit, $invoice);

    expect($request->type->value)->toBe('retention')
        ->and($request->status)->toBe(ApprovalStatus::Pending)
        ->and($request->payload['retention_amount'])->toBe('120.000')
        ->and($deposit->refresh()->status())->toBe(DepositStatus::ToRefund)
        // La retenue en attente reste réservée : 380,000 seulement à restituer.
        ->and(fn () => $this->desk->refund($deposit, '400,000', ['payment_method_id' => methodId('cash')], $this->agent))->toThrow(DepositException::class, 'attend la décision')
        ->and(fn () => $this->desk->applyRetention($request, $this->agent))->toThrow(DepositException::class, 'pas approuvée');

    app(DecideApproval::class)->approve($request, $this->superAdmin, 'Constat et devis vérifiés');
    $movement = $this->desk->applyRetention($request->refresh(), $this->agent);

    // La retenue règle la facture de frais par un encaissement « retenue sur caution » (Q51).
    $payment = Payment::query()->withoutGlobalScopes()->findOrFail($movement->payment_id);

    expect($movement->type)->toBe(DepositMovement::RETENTION)
        ->and($movement->invoice_id)->toBe($invoice->id)
        ->and($movement->approval_request_id)->toBe($request->id)
        ->and($payment->paymentMethod->code)->toBe(PaymentMethod::DEPOSIT_RETENTION)
        ->and($payment->cash_session_id)->toBeNull()
        ->and($invoice->refresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and(InvoiceBalance::remaining($invoice)->isZero())->toBeTrue()
        ->and(fn () => $this->desk->applyRetention($request, $this->agent))->toThrow(DepositException::class, 'déjà appliquée');

    // Restitution du reste, avec son reçu numéroté.
    $refund = $this->desk->refund($deposit, null, ['payment_method_id' => methodId('cash')], $this->agent);

    expect($refund->amount->toDecimalString())->toBe('380.000')
        ->and($refund->receipt_number)->toBe('CAU-2027-00002')
        ->and($deposit->refresh()->remaining()->isZero())->toBeTrue()
        ->and($deposit->retained_amount->toDecimalString())->toBe('120.000')
        ->and($deposit->refunded_amount->toDecimalString())->toBe('380.000')
        ->and($deposit->status())->toBe(DepositStatus::PartiallyOffset)
        // Double restitution impossible (F-06).
        ->and(fn () => $this->desk->refund($deposit, null, ['payment_method_id' => methodId('cash')], $this->agent))->toThrow(DepositException::class, 'Il reste 0,000');
});

it('restitue une caution en totalité ou en plusieurs fois, jamais au-delà du reçu (RG-08, F-03)', function () {
    $deposit = receiveDeposit('300,000', 'cheque', ['reference' => 'CHQ 77', 'bank_name' => 'BIAT', 'difference_reason' => 'Caution réduite accordée par la direction']);

    expect(fn () => $this->desk->refund($deposit, '100', ['payment_method_id' => methodId('cash')], $this->agent))->toThrow(DepositException::class, 'au retour du véhicule');

    returnContract();
    $first = $this->desk->refund($deposit, '100,000', ['payment_method_id' => methodId('transfer'), 'reference' => 'VIR-9', 'bank_name' => 'STB'], $this->agent);

    expect($first->receipt_number)->toBe('CAU-2027-00002')
        ->and($first->cash_session_id)->toBeNull()
        ->and($deposit->refresh()->status())->toBe(DepositStatus::ToRefund)
        ->and(fn () => $this->desk->refund($deposit, '200,001', ['payment_method_id' => methodId('cash')], $this->agent))->toThrow(DepositException::class, 'Il reste 200,000');

    $this->desk->refund($deposit, '200,000', ['payment_method_id' => methodId('cash')], $this->agent);

    expect($deposit->refresh()->status())->toBe(DepositStatus::Refunded)
        ->and(DepositMovement::query()->count())->toBe(2);
});

it('n\'enregistre jamais de donnée de carte : référence d\'autorisation seulement (Q54)', function (string $reference) {
    expect(fn () => receiveDeposit('500', 'card', ['reference' => $reference]))->toThrow(DepositException::class, 'jamais le numéro de la carte')
        ->and(Deposit::query()->count())->toBe(0);
})->with(['4111 1111 1111 1111', '4111-1111-1111-1111', '5500000000000004']);

it('accepte une empreinte avec sa référence d\'autorisation, une seule caution par contrat, écart motivé (D58)', function () {
    expect(fn () => receiveDeposit('500', 'card'))->toThrow(DepositException::class, 'exige une référence')
        ->and(fn () => receiveDeposit('450'))->toThrow(DepositException::class, 'diffère de la caution prévue');

    $deposit = receiveDeposit('500', 'card', ['reference' => 'AUT 482913']);

    expect($deposit->reference)->toBe('AUT 482913')
        ->and($deposit->cash_session_id)->toBeNull()
        ->and($deposit->difference_reason)->toBeNull()
        ->and(fn () => receiveDeposit())->toThrow(DepositException::class, 'une seule caution par contrat')
        ->and(fn () => $deposit->update(['amount_received' => '1.000']))->toThrow(LogicException::class)
        ->and(fn () => $deposit->delete())->toThrow(LogicException::class);
});

it('reçoit la caution au départ seulement, par l\'agence du contrat', function () {
    expect(fn () => $this->desk->receive($this->contract, ['amount' => '500', 'payment_method_id' => methodId('cash')], $this->agentSousse))->toThrow(DepositException::class, 'non autorisée');

    $this->contract->forceFill(['status' => ContractStatus::Returned, 'returned_at' => now()])->save();

    expect(fn () => receiveDeposit())->toThrow(DepositException::class, 'au départ');
});

it('ne retient rien sans approbation : refus du super admin, motif, justificatif, facture du contrat (F-04, F-05)', function () {
    $deposit = receiveDeposit();
    returnContract();
    $invoice = feeInvoice();

    expect(fn () => $this->desk->requestRetention($deposit, ['amount' => '50', 'reason' => 'court', 'invoice_id' => $invoice->id, 'file' => depositUpload('a.pdf')], $this->agent))
        ->toThrow(DepositException::class, 'motif')
        ->and(fn () => $this->desk->requestRetention($deposit, ['amount' => '50', 'reason' => 'Rayure sur le pare-chocs', 'invoice_id' => $invoice->id], $this->agent))
        ->toThrow(DepositException::class, 'justificatif')
        ->and(fn () => requestRetention($deposit, $invoice, '500,001'))->toThrow(DepositException::class, 'Il reste 500,000')
        ->and(fn () => requestRetention($deposit, $invoice, '120,001'))->toThrow(DepositException::class, 'il reste 120,000 DT dû');

    $request = requestRetention($deposit, $invoice, '50,000');
    app(DecideApproval::class)->reject($request, $this->superAdmin, 'Constat insuffisant');

    expect(fn () => $this->desk->applyRetention($request->refresh(), $this->agent))->toThrow(DepositException::class, 'pas approuvée')
        ->and($deposit->refresh()->retained_amount->isZero())->toBeTrue()
        ->and($this->desk->available($deposit)->toDecimalString())->toBe('500.000');
});

it('n\'entre jamais en chiffre d\'affaires : aucune facture ni encaissement à la réception (F-07)', function () {
    receiveDeposit();

    expect(Invoice::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(Payment::query()->withoutGlobalScopes()->count())->toBe(0)
        // Le mode « retenue sur caution » n'est jamais proposé à la saisie d'un encaissement.
        ->and(PaymentMethod::depositRetention()->is_active)->toBeFalse()
        ->and(fn () => app(RecordPayment::class)->handle([
            'agency_id' => $this->sfax->id, 'customer_id' => $this->customer->id, 'payment_method_id' => PaymentMethod::depositRetention()->id, 'amount' => '10',
        ], $this->agent))->toThrow(PaymentException::class, 'inactif');
});

it('n\'annule pas l\'encaissement d\'une retenue appliquée', function () {
    $deposit = receiveDeposit();
    returnContract();
    $request = requestRetention($deposit, feeInvoice());
    app(DecideApproval::class)->approve($request, $this->superAdmin, null);
    $movement = $this->desk->applyRetention($request->refresh(), $this->agent);

    expect(fn () => app(CancelPayment::class)->handle(Payment::query()->withoutGlobalScopes()->findOrFail($movement->payment_id), 'Retenue contestée par le client', $this->agent))
        ->toThrow(PaymentException::class, 'retenue sur caution');
});

it('compte les cautions en espèces dans la caisse et liste les cautions en cours (L8, F-08)', function () {
    $session = CashSession::query()->withoutGlobalScopes()->sole();
    receiveDeposit('500', 'cash');

    expect(CashRegister::expected($session)->toDecimalString())->toBe('500.000');

    CarbonImmutable::setTestNow('2027-03-25 10:00');
    $this->actingAs($this->agent);
    $rows = app(OpenDeposits::class)->list();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['remaining']->toDecimalString())->toBe('500.000')
        ->and($rows[0]['days'])->toBe(15)
        ->and($rows[0]['status'])->toBe(DepositStatus::Received);
});
