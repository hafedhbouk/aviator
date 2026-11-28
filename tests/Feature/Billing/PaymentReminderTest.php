<?php

use App\Domain\Billing\Actions\IssueInvoice;
use App\Domain\Billing\Actions\ValidateInvoice;
use App\Domain\Billing\Exceptions\PaymentException;
use App\Domain\Billing\Payments\RecordPayment;
use App\Domain\Billing\Payments\RecordReminder;
use App\Enums\InvoiceLineNature;
use App\Enums\InvoiceType;
use App\Enums\ReminderChannel;
use App\Models\Agency;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\PaymentMethod;
use App\Models\PaymentReminder;
use App\Models\TaxCode;
use App\Support\Authorization\Role;
use App\Support\Files\PrivateFiles;
use Carbon\CarbonImmutable;
use Database\Seeders\FiscalReferenceSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
 * Relances d'impayés (phase 6, lot L9, E-08, Q53) : relance manuelle tracée (appel,
 * courrier, visite), historique par facture, jamais modifiée.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow('2027-04-20 10:00');
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

    $draft = Invoice::query()->create(['agency_id' => $this->sfax->id, 'type' => InvoiceType::Invoice, 'customer_id' => $this->customer->id]);
    InvoiceLine::query()->create([
        'invoice_id' => $draft->id, 'position' => 1, 'nature' => InvoiceLineNature::Free, 'tax_code_id' => TaxCode::query()->where('code', 'RENTAL')->value('id'),
        'label' => 'Location', 'quantity' => '1.000', 'unit_amount_excl_tax' => '100.000', 'total_excl_tax_amount' => '100.000',
    ]);
    $this->invoice = app(IssueInvoice::class)->handle(app(ValidateInvoice::class)->handle($draft, $this->agent), $this->agent);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('trace les relances manuelles d\'une facture impayée, la plus récente d\'abord (E-08, Q53)', function () {
    $reminders = app(RecordReminder::class);

    $reminders->handle($this->invoice, ['channel' => 'phone', 'reminded_at' => '2027-04-10 09:30', 'outcome' => 'Promet de payer vendredi'], $this->agent);
    $reminders->handle($this->invoice, ['channel' => 'letter', 'outcome' => 'Courrier recommandé'], $this->agent);
    $reminders->handle($this->invoice, ['channel' => 'visit', 'reminded_at' => '2027-04-15 16:00'], $this->agent);

    $history = $reminders->history($this->invoice);

    expect($history->map(fn (PaymentReminder $reminder): array => [$reminder->channel, $reminder->reminded_at->format('Y-m-d H:i'), $reminder->outcome])->all())
        ->toBe([
            [ReminderChannel::Letter, '2027-04-20 10:00', 'Courrier recommandé'],
            [ReminderChannel::Visit, '2027-04-15 16:00', null],
            [ReminderChannel::Phone, '2027-04-10 09:30', 'Promet de payer vendredi'],
        ])
        ->and($history->first()?->author?->id)->toBe($this->agent->id)
        ->and(fn () => $history->first()?->update(['outcome' => 'autre']))->toThrow(LogicException::class)
        ->and(fn () => $history->first()?->delete())->toThrow(LogicException::class);
});

it('refuse un canal hors liste (ni SMS ni e-mail), une date future ou une autre agence', function () {
    $reminders = app(RecordReminder::class);

    expect(fn () => $reminders->handle($this->invoice, ['channel' => 'sms'], $this->agent))->toThrow(ValidationException::class)
        ->and(fn () => $reminders->handle($this->invoice, ['channel' => 'email'], $this->agent))->toThrow(ValidationException::class)
        ->and(fn () => $reminders->handle($this->invoice, ['channel' => 'phone', 'reminded_at' => '2027-04-21 09:00'], $this->agent))->toThrow(ValidationException::class)
        ->and(fn () => $reminders->handle($this->invoice, ['channel' => 'phone'], $this->agentSousse))->toThrow(PaymentException::class, 'non autorisée')
        ->and(PaymentReminder::query()->count())->toBe(0);
});

it('ne relance pas une facture réglée', function () {
    app(RecordPayment::class)->handle([
        'agency_id' => $this->sfax->id, 'customer_id' => $this->customer->id, 'payment_method_id' => (int) PaymentMethod::query()->where('code', 'transfer')->value('id'),
        'amount' => '120,000', 'reference' => 'VIR-1', 'bank_name' => 'STB', 'allocations' => [$this->invoice->id => '120,000'],
    ], $this->agent);

    expect(fn () => app(RecordReminder::class)->handle($this->invoice->refresh(), ['channel' => 'phone'], $this->agent))->toThrow(PaymentException::class, 'rien à relancer');
});
