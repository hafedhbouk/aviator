<?php

use App\Domain\Billing\Actions\CancelInvoice;
use App\Domain\Billing\Actions\DraftContractInvoice;
use App\Domain\Billing\Actions\DraftSupplementaryInvoice;
use App\Domain\Billing\Actions\EditInvoiceDraft;
use App\Domain\Billing\Actions\IssueInvoice;
use App\Domain\Billing\Actions\ManageLineJustifications;
use App\Domain\Billing\Actions\ValidateInvoice;
use App\Domain\Billing\Exceptions\InvoiceDraftException;
use App\Domain\Billing\Exceptions\InvoiceValidationException;
use App\Enums\ChargeNature;
use App\Enums\ContractStatus;
use App\Enums\InspectionType;
use App\Enums\InvoiceLineNature;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Agency;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractCharge;
use App\Models\Customer;
use App\Models\DamageFile;
use App\Models\Inspection;
use App\Models\InspectionDamage;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Sequence;
use App\Models\TaxCode;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Support\Authorization\Role;
use App\Support\Files\PrivateFiles;
use Carbon\CarbonImmutable;
use Database\Seeders\FiscalReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

/*
 * Facture complémentaire (phase 6, lot L5, E-05, Q87 c) et annulation sans avoir d'une
 * facture validée jamais émise (E11, Q79, D56). Contrat restitué de 3 jours à 100 DT.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow('2027-03-20 10:00');
    Storage::fake(PrivateFiles::DISK);
    $this->seed(FiscalReferenceSeeder::class);
    Company::query()->forceCreate(['id' => Company::SINGLETON_ID, 'legal_name' => 'Aviator SARL', 'invoice_legal_mentions' => 'Mentions de test']);

    $this->sfax = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->sousse = Agency::factory()->create(['code' => 'SOU', 'name' => 'Sousse']);
    $this->agent = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sfax->id]);
    $this->agentSousse = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sousse->id]);
    $this->customer = Customer::factory()->create(['origin_agency_id' => $this->sfax->id]);
    $vehicle = Vehicle::factory()->create(['agency_id' => $this->sfax->id, 'vehicle_category_id' => VehicleCategory::factory()->create()->id]);

    $this->contract = Contract::query()->create([
        'number' => 'CTR-2027-00001', 'agency_id' => $this->sfax->id, 'return_agency_id' => $this->sfax->id, 'vehicle_id' => $vehicle->id,
        'vehicle_category_id' => $vehicle->vehicle_category_id, 'customer_id' => $this->customer->id, 'starts_at' => '2027-03-10 09:00',
        'ends_at' => '2027-03-13 09:00', 'departed_at' => '2027-03-10 09:00', 'returned_at' => '2027-03-13 09:00',
        'status' => ContractStatus::Returned, 'mileage_policy' => Contract::MILEAGE_UNLIMITED, 'fuel_policy' => Contract::FUEL_SAME_LEVEL,
        'daily_rate_amount' => '100.000', 'rental_amount' => '300.000', 'options_amount' => '0.000', 'total_amount' => '300.000',
        'quote' => ['days' => array_fill(0, 3, ['date' => null, 'season' => null, 'daily_amount' => '100.000'])],
    ]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function codeId(string $code): int
{
    return (int) TaxCode::query()->where('code', $code)->value('id');
}

/** Code choisi sur chaque ligne sans code (ici, les frais : D54), puis validation. */
function chooseCodesAndValidate(Invoice $invoice, string $code = 'EXTRA_RENTAL_PERIOD'): Invoice
{
    foreach ($invoice->lines()->whereNull('tax_code_id')->get() as $line) {
        app(EditInvoiceDraft::class)->updateLine($line, ['tax_code_id' => codeId($code)], test()->agent);
    }

    return app(ValidateInvoice::class)->handle($invoice, test()->agent);
}

function rentalInvoice(): Invoice
{
    return chooseCodesAndValidate(app(DraftContractInvoice::class)->handle(test()->contract, test()->agent));
}

function lateCharge(string $amount = '100.000'): ContractCharge
{
    return ContractCharge::query()->create([
        'contract_id' => test()->contract->id, 'nature' => ChargeNature::LateReturn, 'label' => 'Retard de restitution',
        'quantity' => '1.000', 'unit_amount' => $amount, 'amount' => $amount, 'calculation' => [],
    ]);
}

function supplementary(): Invoice
{
    return app(DraftSupplementaryInvoice::class)->handle(test()->contract, test()->agent);
}

it('établit une facture complémentaire après la location, dans la série AVI-, timbre compris (E-05, Q87 c)', function () {
    $rental = rentalInvoice();
    $draft = supplementary();

    expect($draft->type)->toBe(InvoiceType::Supplementary)
        ->and($draft->status)->toBe(InvoiceStatus::Draft)
        ->and($draft->contract_id)->toBe($this->contract->id)
        ->and($draft->period_start)->toBeNull()
        ->and($draft->lines()->count())->toBe(0);

    // Dommage constaté après coup : ligne libre, code choisi, dossier de dommage complet (N2).
    $line = app(EditInvoiceDraft::class)->addFreeLine($draft, [
        'label' => 'Rayure constatée après la restitution', 'quantity' => '1', 'unit_amount' => '250,000', 'tax_code_id' => codeId('VEHICLE_DAMAGE_COMPENSATION'),
    ], $this->agent);
    $inspection = Inspection::query()->create(['contract_id' => $this->contract->id, 'type' => InspectionType::Return, 'mileage' => 42000, 'fuel_eighths' => 8]);
    $damage = InspectionDamage::query()->create(['inspection_id' => $inspection->id, 'zone' => 'rear_bumper', 'x' => '50.00', 'y' => '95.00', 'description' => 'Rayure', 'severity' => 'minor', 'is_new' => true]);
    $file = DamageFile::query()->create(['contract_id' => $this->contract->id, 'inspection_damage_id' => $damage->id, 'status' => DamageFile::COMPLETE, 'completed_at' => now()]);
    app(ManageLineJustifications::class)->attachDamageFile($line, $file, $this->agent);

    $invoice = app(IssueInvoice::class)->handle(app(ValidateInvoice::class)->handle($draft, $this->agent), $this->agent);

    expect($rental->number)->toBe('AVI-000001')
        ->and($invoice->number)->toBe('AVI-000002')
        ->and($invoice->status)->toBe(InvoiceStatus::Issued)
        ->and($invoice->total_tax_amount->isZero())->toBeTrue()
        ->and($invoice->stamp_duty_amount->toDecimalString())->toBe('1.000')
        ->and($invoice->total_due_amount->toDecimalString())->toBe('251.000');
});

it('reprend les frais non facturés, jamais deux fois, même si la facture de location est refaite', function () {
    $rental = app(DraftContractInvoice::class)->handle($this->contract, $this->agent);
    $rental = chooseCodesAndValidate($rental);

    // Frais enregistré après la facture de location : il va sur la facture complémentaire.
    lateCharge('100.000');
    $draft = supplementary();
    $line = $draft->lines()->sole();

    expect($line->nature)->toBe(InvoiceLineNature::Charge)
        ->and($line->tax_code_id)->toBeNull()
        ->and($line->total_excl_tax_amount->toDecimalString())->toBe('100.000');

    chooseCodesAndValidate($draft);

    // Facture de location annulée (Q79), puis refaite : le frais n'y revient pas.
    app(CancelInvoice::class)->handle($rental, 'Erreur sur le client facturé', $this->agent);
    $again = app(DraftContractInvoice::class)->handle($this->contract, $this->agent);

    expect($again->lines()->where('nature', InvoiceLineNature::Charge)->count())->toBe(0)
        ->and($again->total_excl_tax_amount->toDecimalString())->toBe('300.000')
        ->and(supplementary()->lines()->count())->toBe(0);
});

it('refuse la facture complémentaire avant le retour ou avant la facture de location', function () {
    expect(fn () => supplementary())->toThrow(InvoiceDraftException::class, 'Établissez d\'abord la facture de location');

    $this->contract->forceFill(['status' => ContractStatus::Active, 'returned_at' => null])->save();

    expect(fn () => supplementary())->toThrow(InvoiceDraftException::class, 'suit le retour')
        ->and(Invoice::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('accepte une facture complémentaire sur un contrat clôturé, et la réserve à son agence (RG-13)', function () {
    rentalInvoice();
    $this->contract->forceFill(['status' => ContractStatus::Closed, 'closed_at' => now()])->save();

    expect(fn () => app(DraftSupplementaryInvoice::class)->handle($this->contract, $this->agentSousse))->toThrow(InvoiceDraftException::class, 'non autorisée')
        ->and(supplementary()->type)->toBe(InvoiceType::Supplementary);
});

it('annule une facture validée jamais émise : motif, numéro conservé, période libérée (Q79)', function () {
    $invoice = rentalInvoice();

    expect(fn () => app(CancelInvoice::class)->handle($invoice, 'court', $this->agent))->toThrow(InvoiceValidationException::class, 'motif')
        ->and(fn () => app(CancelInvoice::class)->handle($invoice, 'Erreur sur le client facturé', $this->agentSousse))->toThrow(InvoiceValidationException::class, 'non autorisée');

    $cancelled = app(CancelInvoice::class)->handle($invoice, 'Erreur sur le client facturé', $this->agent);

    expect($cancelled->status)->toBe(InvoiceStatus::Cancelled)
        ->and($cancelled->number)->toBe('AVI-000001')
        ->and($cancelled->cancellation_reason)->toBe('Erreur sur le client facturé')
        ->and($cancelled->cancelled_by)->toBe($this->agent->id)
        ->and($cancelled->cancelled_at)->not->toBeNull();

    // Le numéro n'est jamais réattribué ; la période se facture de nouveau.
    $again = rentalInvoice();

    expect($again->number)->toBe('AVI-000002')
        ->and(Sequence::query()->where('key', 'series:invoice')->value('last_value'))->toBe(2)
        ->and(fn () => app(CancelInvoice::class)->handle($cancelled, 'Deuxième annulation demandée', $this->agent))
        ->toThrow(InvoiceValidationException::class, 'Annulée');
});

it('refuse d\'annuler une facture émise : elle se corrige par un avoir (Q79)', function () {
    $issued = app(IssueInvoice::class)->handle(rentalInvoice(), $this->agent);

    expect(fn () => app(CancelInvoice::class)->handle($issued, 'Erreur sur le client facturé', $this->agent))->toThrow(InvoiceValidationException::class, 'se corrige par un avoir')
        ->and($issued->refresh()->status)->toBe(InvoiceStatus::Issued)
        ->and($issued->cancellation_reason)->toBeNull();
});

it('n\'annule pas un brouillon : il s\'abandonne', function () {
    $draft = app(DraftContractInvoice::class)->handle($this->contract, $this->agent);

    expect(fn () => app(CancelInvoice::class)->handle($draft, 'Erreur sur le client facturé', $this->agent))->toThrow(InvoiceValidationException::class, 'non autorisée')
        ->and($draft->refresh()->status)->toBe(InvoiceStatus::Draft)
        ->and(InvoiceLine::query()->count())->toBe(1);
});
