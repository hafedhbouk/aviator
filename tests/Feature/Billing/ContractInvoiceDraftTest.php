<?php

use App\Domain\Billing\Actions\DraftContractInvoice;
use App\Domain\Billing\Actions\EditInvoiceDraft;
use App\Domain\Billing\Exceptions\InvoiceDraftException;
use App\Domain\Billing\InvoiceSnapshots;
use App\Domain\Setup\Actions\UpdateSettings;
use App\Enums\ChargeNature;
use App\Enums\ContractStatus;
use App\Enums\InvoiceLineNature;
use App\Enums\InvoiceStatus;
use App\Models\Agency;
use App\Models\Contract;
use App\Models\ContractCharge;
use App\Models\ContractExtension;
use App\Models\ContractOption;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Option;
use App\Models\TaxCode;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Settings\BillingSettings;
use App\Support\Authorization\Role;
use App\Support\Money\Money;
use App\Support\Sequences\Series;
use Carbon\CarbonImmutable;
use Database\Seeders\FiscalReferenceSeeder;
use Database\Seeders\OptionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

/*
 * Brouillon de facture depuis le contrat (phase 6, lot L3, E-01, E-02, RG-05, Q50, D52,
 * D54) : lignes reprises du contrat (location, options, prolongations, frais du retour),
 * codes fiscaux proposés sauf pour les frais, lignes libres, période facturée une seule
 * fois, location longue facturée par période de 30 jours. La concurrence est dans
 * tests/Feature/Concurrency/InvoiceDraftConcurrencyTest.php.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow('2027-03-20 10:00');
    $this->seed(FiscalReferenceSeeder::class);
    $this->seed(OptionSeeder::class);

    $this->superAdmin = withTwoFactor(userWithProfile(Role::SuperAdmin));
    $this->sfax = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->sousse = Agency::factory()->create(['code' => 'SOU', 'name' => 'Sousse']);
    $this->agent = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sfax->id]);
    $this->agentSousse = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sousse->id]);
    $this->category = VehicleCategory::factory()->create(['code' => 'CIT', 'name' => 'Citadine']);
    $this->vehicle = Vehicle::factory()->create(['agency_id' => $this->sfax->id, 'vehicle_category_id' => $this->category->id]);
    $this->customer = Customer::factory()->create(['origin_agency_id' => $this->sfax->id]);
    $this->gps = Option::query()->where('code', 'GPS')->sole();
    $this->seat = Option::query()->where('code', 'CHILD_SEAT')->sole();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * Contrat figé, écrit directement (les actions du contrat sont testées en phase 5).
 *
 * @param  list<string>  $days  montant de chaque jour du devis
 */
function billedContract(string $startsAt, string $endsAt, array $days, array $attributes = []): Contract
{
    $rental = array_reduce($days, fn (Money $sum, string $day): Money => $sum->plus(Money::of($day)), Money::zero());

    return Contract::query()->create([
        'number' => 'CTR-2027-'.str_pad((string) (Contract::query()->withoutGlobalScopes()->count() + 1), 5, '0', STR_PAD_LEFT),
        'agency_id' => test()->sfax->id, 'return_agency_id' => test()->sfax->id, 'vehicle_id' => test()->vehicle->id,
        'vehicle_category_id' => test()->category->id, 'customer_id' => test()->customer->id,
        'starts_at' => $startsAt, 'ends_at' => $endsAt, 'departed_at' => $startsAt, 'status' => ContractStatus::Returned,
        'returned_at' => $endsAt, 'mileage_policy' => Contract::MILEAGE_UNLIMITED, 'fuel_policy' => Contract::FUEL_SAME_LEVEL,
        'daily_rate_amount' => end($days), 'rental_amount' => $rental, 'options_amount' => '0.000', 'discount_amount' => '0.000',
        'total_amount' => $rental,
        'quote' => ['days' => array_map(fn (string $day): array => ['date' => null, 'season' => null, 'daily_amount' => $day], $days)],
        ...$attributes,
    ]);
}

function contractOption(Contract $contract, Option $option, int $quantity, string $unit, string $total): void
{
    ContractOption::query()->create(['contract_id' => $contract->id, 'option_id' => $option->id, 'quantity' => $quantity, 'unit_amount' => $unit, 'total_amount' => $total]);
}

function contractCharge(Contract $contract, ChargeNature $nature, string $label, string $quantity, string $unit, string $amount): ContractCharge
{
    return ContractCharge::query()->create([
        'contract_id' => $contract->id, 'nature' => $nature, 'label' => $label, 'quantity' => $quantity,
        'unit_amount' => $unit, 'amount' => $amount, 'calculation' => [],
    ]);
}

function draftFor(Contract $contract, ?User $actor = null): Invoice
{
    return app(DraftContractInvoice::class)->handle($contract, $actor ?? test()->agent);
}

function taxCodeId(string $code): int
{
    return (int) TaxCode::query()->where('code', $code)->value('id');
}

/**
 * @return list<array{nature: string, source: string|null, label: string, code: string|null, quantity: string, unit: string, discount: string, total: string}>
 */
function linesOf(Invoice $invoice): array
{
    return $invoice->lines()->with('taxCode')->get()->map(fn (InvoiceLine $line): array => [
        'nature' => $line->nature->value,
        'source' => $line->source_key === null ? $line->source_type : $line->source_type.'/'.$line->source_key,
        'label' => $line->label,
        'code' => $line->taxCode?->code,
        'quantity' => $line->quantity,
        'unit' => $line->unit_amount_excl_tax->toDecimalString(),
        'discount' => $line->discount_amount->toDecimalString(),
        'total' => $line->total_excl_tax_amount->toDecimalString(),
    ])->all();
}

/** Contrat restitué de 3 jours à 100 DT, prolongé de 2 jours, deux options, remise de 10 %, deux frais. */
function returnedContractWithEverything(): Contract
{
    $contract = billedContract('2027-03-10 09:00', '2027-03-15 09:00', ['100.000', '100.000', '100.000'], [
        'rental_amount' => '500.000', 'options_amount' => '75.000', 'discount_amount' => '34.500', 'total_amount' => '540.500',
    ]);
    contractOption($contract, test()->gps, 1, '10.000', '30.000');
    contractOption($contract, test()->seat, 1, '5.000', '15.000');
    ContractExtension::query()->create([
        'contract_id' => $contract->id, 'previous_ends_at' => '2027-03-13 09:00', 'new_ends_at' => '2027-03-15 09:00', 'added_days' => 2,
        'daily_rate_amount' => '100.000', 'added_rental_amount' => '200.000', 'added_options_amount' => '30.000', 'discount_amount' => '0.000',
        'total_amount' => '230.000', 'options' => [
            ['option_id' => test()->gps->id, 'label' => 'GPS', 'quantity' => 1, 'unit_amount' => '10.000', 'amount' => '20.000'],
            ['option_id' => test()->seat->id, 'label' => 'Siège enfant', 'quantity' => 1, 'unit_amount' => '5.000', 'amount' => '10.000'],
        ],
    ]);
    contractCharge($contract, ChargeNature::LateReturn, 'Retard de restitution : 1 jour', '1.000', '100.000', '100.000');
    contractCharge($contract, ChargeNature::Damage, 'Dommage : rayure portière avant', '1.000', '250.000', '250.000');

    return $contract;
}

it('reprend du contrat la location, les options, la prolongation et les frais, sans numéro (E-01, E-02, RG-07)', function () {
    $contract = returnedContractWithEverything();
    $invoice = draftFor($contract);

    expect($invoice->status)->toBe(InvoiceStatus::Draft)
        ->and($invoice->number)->toBeNull()
        ->and($invoice->agency_id)->toBe($this->sfax->id)
        ->and($invoice->customer_id)->toBe($this->customer->id)
        ->and($invoice->contract_id)->toBe($contract->id)
        ->and($invoice->created_by)->toBe($this->agent->id)
        ->and($invoice->period_start?->equalTo($contract->starts_at))->toBeTrue()
        ->and($invoice->period_end?->equalTo($contract->ends_at))->toBeTrue()
        ->and(linesOf($invoice))->toBe([
            ['nature' => 'rental', 'source' => 'contract/rental', 'label' => 'Location Citadine, contrat '.$contract->number, 'code' => 'RENTAL', 'quantity' => '3.000', 'unit' => '100.000', 'discount' => '30.000', 'total' => '270.000'],
            ['nature' => 'option', 'source' => 'contract_option', 'label' => 'GPS', 'code' => 'GPS', 'quantity' => '3.000', 'unit' => '10.000', 'discount' => '3.000', 'total' => '27.000'],
            ['nature' => 'option', 'source' => 'contract_option', 'label' => 'Siège enfant', 'code' => 'CHILD_SEAT', 'quantity' => '3.000', 'unit' => '5.000', 'discount' => '1.500', 'total' => '13.500'],
            ['nature' => 'extension', 'source' => 'contract_extension/rental', 'label' => 'Prolongation du 13/03/2027 09:00 au 15/03/2027 09:00', 'code' => 'EXTRA_RENTAL_PERIOD', 'quantity' => '2.000', 'unit' => '100.000', 'discount' => '0.000', 'total' => '200.000'],
            ['nature' => 'extension', 'source' => 'contract_extension/option:'.$this->gps->id, 'label' => 'GPS (prolongation)', 'code' => 'GPS', 'quantity' => '2.000', 'unit' => '10.000', 'discount' => '0.000', 'total' => '20.000'],
            ['nature' => 'extension', 'source' => 'contract_extension/option:'.$this->seat->id, 'label' => 'Siège enfant (prolongation)', 'code' => 'CHILD_SEAT', 'quantity' => '2.000', 'unit' => '5.000', 'discount' => '0.000', 'total' => '10.000'],
            ['nature' => 'charge', 'source' => 'contract_charge', 'label' => 'Retard de restitution : 1 jour', 'code' => null, 'quantity' => '1.000', 'unit' => '100.000', 'discount' => '0.000', 'total' => '100.000'],
            ['nature' => 'charge', 'source' => 'contract_charge', 'label' => 'Dommage : rayure portière avant', 'code' => null, 'quantity' => '1.000', 'unit' => '250.000', 'discount' => '0.000', 'total' => '250.000'],
        ])
        // Total HT provisoire = contrat (540,500) + frais (350,000) ; taxes à la validation (L4).
        ->and($invoice->total_excl_tax_amount->toDecimalString())->toBe('890.500')
        ->and($invoice->total_tax_amount->isZero())->toBeTrue();
});

it('ne propose aucun code fiscal pour les frais du retour : l\'agent le choisit (D54, N2)', function () {
    $contract = billedContract('2027-03-10 09:00', '2027-03-13 09:00', ['100.000', '100.000', '100.000']);
    foreach (ChargeNature::cases() as $nature) {
        contractCharge($contract, $nature, $nature->label(), '1.000', '40.000', '40.000');
    }

    $charges = draftFor($contract)->lines()->where('nature', InvoiceLineNature::Charge)->get();

    expect($charges)->toHaveCount(4)
        ->and($charges->pluck('tax_code_id')->filter()->all())->toBe([]);
});

it('ne déduit jamais le code d\'un libellé : une ligne libre « Assurance tous risques » reste sans code (N2)', function () {
    $invoice = draftFor(billedContract('2027-03-10 09:00', '2027-03-13 09:00', ['100.000', '100.000', '100.000']));
    $edit = app(EditInvoiceDraft::class);

    $line = $edit->addFreeLine($invoice, ['label' => 'Assurance tous risques', 'quantity' => '3', 'unit_amount' => '12,500'], $this->agent);

    expect($line->tax_code_id)->toBeNull()
        ->and($line->nature)->toBe(InvoiceLineNature::Free)
        ->and($line->source_type)->toBeNull()
        ->and($line->position)->toBe(2)
        ->and($line->total_excl_tax_amount->toDecimalString())->toBe('37.500')
        ->and($invoice->refresh()->total_excl_tax_amount->toDecimalString())->toBe('337.500');

    $line = $edit->updateLine($line, ['tax_code_id' => taxCodeId('ADDITIONAL_PROTECTION_SERVICE'), 'quantity' => '2'], $this->agent);

    expect($line->taxCode?->code)->toBe('ADDITIONAL_PROTECTION_SERVICE')
        ->and($line->total_excl_tax_amount->toDecimalString())->toBe('25.000')
        ->and($invoice->refresh()->total_excl_tax_amount->toDecimalString())->toBe('325.000');

    $edit->removeFreeLine($line, $this->agent);

    expect($invoice->lines()->count())->toBe(1)
        ->and($invoice->refresh()->total_excl_tax_amount->toDecimalString())->toBe('300.000');
});

it('fait choisir le code d\'un frais, garde le montant d\'une ligne du contrat et refuse de la retirer', function () {
    $contract = billedContract('2027-03-10 09:00', '2027-03-13 09:00', ['100.000', '100.000', '100.000']);
    contractCharge($contract, ChargeNature::LateReturn, 'Retard', '1.000', '100.000', '100.000');
    $invoice = draftFor($contract);
    $edit = app(EditInvoiceDraft::class);
    $charge = $invoice->lines()->where('nature', InvoiceLineNature::Charge)->sole();
    $rental = $invoice->lines()->where('nature', InvoiceLineNature::Rental)->sole();

    $charge = $edit->updateLine($charge, ['tax_code_id' => taxCodeId('EXTRA_RENTAL_PERIOD'), 'label' => 'Journée supplémentaire (retard)'], $this->agent);

    expect($charge->taxCode?->code)->toBe('EXTRA_RENTAL_PERIOD')
        ->and($charge->label)->toBe('Journée supplémentaire (retard)')
        ->and($charge->total_excl_tax_amount->toDecimalString())->toBe('100.000')
        ->and(fn () => $edit->updateLine($rental, ['unit_amount' => '50,000'], $this->agent))->toThrow(InvoiceDraftException::class, 'garde son montant')
        ->and(fn () => $edit->updateLine($rental, ['quantity' => '1'], $this->agent))->toThrow(InvoiceDraftException::class, 'garde son montant')
        ->and(fn () => $edit->removeFreeLine($rental, $this->agent))->toThrow(InvoiceDraftException::class, 'ne se supprime pas')
        ->and(fn () => $edit->updateLine($rental, ['tax_code_id' => 999999], $this->agent))->toThrow(InvoiceDraftException::class, 'Code fiscal inconnu')
        ->and(fn () => $edit->addFreeLine($invoice, ['label' => 'Geste', 'quantity' => '0', 'unit_amount' => '10'], $this->agent))->toThrow(ValidationException::class)
        ->and(fn () => $edit->addFreeLine($invoice, ['label' => 'Geste', 'quantity' => '1', 'unit_amount' => '-10'], $this->agent))->toThrow(ValidationException::class)
        ->and($rental->refresh()->total_excl_tax_amount->toDecimalString())->toBe('300.000')
        ->and($invoice->refresh()->total_excl_tax_amount->toDecimalString())->toBe('400.000');
});

it('ne facture jamais deux fois la même période ; un brouillon abandonné ou une facture annulée la libère (RG-05, Q79)', function () {
    $contract = billedContract('2027-03-10 09:00', '2027-03-13 09:00', ['100.000', '100.000', '100.000']);
    $first = draftFor($contract);

    expect(fn () => draftFor($contract))->toThrow(InvoiceDraftException::class, 'déjà facturée');

    app(EditInvoiceDraft::class)->discard($first, $this->agent);
    $second = draftFor($contract);

    // Validée (comme L4 le fera), puis annulée sans avoir, jamais émise (Q79) : la période redevient facturable.
    $second->fill([
        'status' => InvoiceStatus::Validated, 'number' => 'AVI-000001', 'series' => Series::Invoice, 'sequence_key' => 'series:invoice',
        'fiscal_year' => 2027, 'validated_at' => now(), 'customer_snapshot' => InvoiceSnapshots::customer($this->customer),
        'issuer_snapshot' => ['legal_name' => 'Aviator'],
    ])->save();

    expect(fn () => draftFor($contract))->toThrow(InvoiceDraftException::class, 'déjà facturée')
        ->and(fn () => app(EditInvoiceDraft::class)->discard($second, $this->agent))->toThrow(InvoiceDraftException::class, 'Seul un brouillon')
        ->and(fn () => app(EditInvoiceDraft::class)->addFreeLine($second, ['label' => 'Ajout', 'quantity' => '1', 'unit_amount' => '1'], $this->agent))
        ->toThrow(InvoiceDraftException::class, 'Seul un brouillon');

    $second->fill(['status' => InvoiceStatus::Cancelled, 'cancellation_reason' => 'Erreur de client', 'cancelled_at' => now()])->save();
    $third = draftFor($contract);

    expect($third->lines()->sole()->total_excl_tax_amount->toDecimalString())->toBe('300.000')
        ->and(Invoice::query()->withoutGlobalScopes()->where('status', '!=', InvoiceStatus::Cancelled)->count())->toBe(1);
});

it('facture une location de plus de 30 jours par période, la dernière au retour, sans perdre un millime (Q50, D52)', function () {
    // 45 jours : 20 jours à 100 DT, 25 à 120 DT (deux saisons), GPS 10 DT par jour, remise de 10 %.
    $days = [...array_fill(0, 20, '100.000'), ...array_fill(0, 25, '120.000')];
    $contract = billedContract('2027-01-01 09:00', '2027-02-15 09:00', $days, [
        'status' => ContractStatus::Active, 'returned_at' => null,
        'options_amount' => '450.000', 'discount_amount' => '545.000', 'total_amount' => '4905.000',
    ]);
    contractOption($contract, $this->gps, 1, '10.000', '450.000');

    $first = draftFor($contract);

    expect($first->period_start?->equalTo(CarbonImmutable::parse('2027-01-01 09:00')))->toBeTrue()
        ->and($first->period_end?->equalTo(CarbonImmutable::parse('2027-01-31 09:00')))->toBeTrue()
        ->and(linesOf($first))->toBe([
            // Jours exacts du devis : 20 × 100 + 10 × 120 ; prix moyen non exact, donc 1 × montant.
            ['nature' => 'rental', 'source' => 'contract/rental', 'label' => 'Location Citadine, contrat '.$contract->number, 'code' => 'RENTAL', 'quantity' => '1.000', 'unit' => '3200.000', 'discount' => '333.333', 'total' => '2866.667'],
            ['nature' => 'option', 'source' => 'contract_option', 'label' => 'GPS', 'code' => 'GPS', 'quantity' => '30.000', 'unit' => '10.000', 'discount' => '30.000', 'total' => '270.000'],
        ]);

    // Dernière période : seulement au retour, avec les frais figés.
    expect(fn () => draftFor($contract))->toThrow(InvoiceDraftException::class, 'au retour du véhicule');

    $contract->forceFill(['status' => ContractStatus::Returned, 'returned_at' => '2027-02-15 10:30'])->save();
    contractCharge($contract, ChargeNature::LateReturn, 'Retard', '1.000', '100.000', '100.000');
    $last = draftFor($contract);

    expect($last->period_start?->equalTo(CarbonImmutable::parse('2027-01-31 09:00')))->toBeTrue()
        ->and($last->period_end?->equalTo(CarbonImmutable::parse('2027-02-15 09:00')))->toBeTrue()
        ->and(linesOf($last))->toBe([
            ['nature' => 'rental', 'source' => 'contract/rental', 'label' => 'Location Citadine, contrat '.$contract->number, 'code' => 'RENTAL', 'quantity' => '15.000', 'unit' => '120.000', 'discount' => '166.667', 'total' => '1633.333'],
            ['nature' => 'option', 'source' => 'contract_option', 'label' => 'GPS', 'code' => 'GPS', 'quantity' => '15.000', 'unit' => '10.000', 'discount' => '15.000', 'total' => '135.000'],
            ['nature' => 'charge', 'source' => 'contract_charge', 'label' => 'Retard', 'code' => null, 'quantity' => '1.000', 'unit' => '100.000', 'discount' => '0.000', 'total' => '100.000'],
        ])
        // Somme des deux factures = contrat (4 905,000) + frais (100,000), au millime.
        ->and($first->total_excl_tax_amount->plus($last->total_excl_tax_amount)->toDecimalString())->toBe('5005.000')
        ->and(fn () => draftFor($contract))->toThrow(InvoiceDraftException::class, 'déjà facturée');
});

it('facture en une seule fois un contrat de 30 jours pile, et suit le réglage de la période (Q50)', function () {
    $contract = billedContract('2027-01-01 09:00', '2027-01-31 09:00', array_fill(0, 30, '100.000'));

    expect(draftFor($contract)->total_excl_tax_amount->toDecimalString())->toBe('3000.000');

    app(UpdateSettings::class)->handle(app(BillingSettings::class), ['long_rental_period_days' => '10'], $this->superAdmin);
    $other = billedContract('2027-02-01 09:00', '2027-02-21 09:00', array_fill(0, 20, '100.000'), ['status' => ContractStatus::Active, 'returned_at' => null]);

    expect(app(BillingSettings::class)->long_rental_period_days)->toBe(10)
        ->and(draftFor($other)->period_end?->equalTo(CarbonImmutable::parse('2027-02-11 09:00')))->toBeTrue()
        ->and(fn () => draftFor($other))->toThrow(InvoiceDraftException::class, 'au retour du véhicule');
});

it('ne facture qu\'un contrat en cours ou restitué', function (ContractStatus $status) {
    $contract = billedContract('2027-03-10 09:00', '2027-03-13 09:00', ['100.000', '100.000', '100.000'], ['status' => $status]);

    expect(fn () => draftFor($contract))->toThrow(InvoiceDraftException::class, 'ne se facture pas')
        ->and(Invoice::query()->withoutGlobalScopes()->count())->toBe(0);
})->with([ContractStatus::Draft, ContractStatus::Closed, ContractStatus::Cancelled]);

it('refuse la dernière période d\'un contrat encore en cours', function () {
    $contract = billedContract('2027-03-10 09:00', '2027-03-13 09:00', ['100.000', '100.000', '100.000'], ['status' => ContractStatus::Active, 'returned_at' => null]);

    expect(fn () => draftFor($contract))->toThrow(InvoiceDraftException::class, 'au retour du véhicule')
        ->and(Invoice::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('réserve le brouillon et ses lignes aux profils de l\'agence du contrat (RG-13)', function () {
    $contract = billedContract('2027-03-10 09:00', '2027-03-13 09:00', ['100.000', '100.000', '100.000']);

    expect(fn () => draftFor($contract, $this->agentSousse))->toThrow(InvoiceDraftException::class, 'non autorisée');

    $invoice = draftFor($contract);
    $edit = app(EditInvoiceDraft::class);

    expect(fn () => $edit->addFreeLine($invoice, ['label' => 'Ajout', 'quantity' => '1', 'unit_amount' => '1'], $this->agentSousse))->toThrow(InvoiceDraftException::class, 'non autorisée')
        ->and(fn () => $edit->updateLine($invoice->lines()->sole(), ['label' => 'Autre'], $this->agentSousse))->toThrow(InvoiceDraftException::class, 'non autorisée')
        ->and(fn () => $edit->discard($invoice, $this->agentSousse))->toThrow(InvoiceDraftException::class, 'non autorisée')
        ->and(draftFor(billedContract('2027-03-10 09:00', '2027-03-13 09:00', ['100.000']), $this->superAdmin)->agency_id)->toBe($this->sfax->id);
});
