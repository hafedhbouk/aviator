<?php

use App\Domain\Fleet\Exceptions\FleetException;
use App\Domain\Fleet\Financing\Actions\MarkInstallmentPaid;
use App\Domain\Fleet\Financing\Actions\SaveFinancing;
use App\Domain\Fleet\Financing\Actions\SettleFinancing;
use App\Domain\Fleet\Financing\FinancingCharges;
use App\Enums\FinancingMode;
use App\Enums\FinancingOutcome;
use App\Enums\RetirementReason;
use App\Enums\VehicleStatus;
use App\Filament\Resources\VehicleFinancings\Pages\CreateVehicleFinancing;
use App\Filament\Resources\VehicleFinancings\VehicleFinancingResource;
use App\Models\Agency;
use App\Models\FinancingInstallment;
use App\Models\Supplier;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\VehicleFinancing;
use App\Support\Authorization\Role;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * Leasing et crédit (B-11 à B-15, lot L6) : échéancier, pointage, fin de contrat,
 * charges par mois comptées une seule fois (scénario 15, RG-17, Q57 à Q59).
 */

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-25 10:00');

    $this->admin = withTwoFactor(userWithProfile(Role::Admin));
    $this->sfax = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->agent = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sfax->id]);
    $this->lessor = Supplier::query()->create(['type' => 'leasing', 'name' => 'Société de leasing fictive', 'is_active' => true]);
    $this->vehicle = Vehicle::factory()->create([
        'agency_id' => $this->sfax->id,
        'vehicle_category_id' => VehicleCategory::factory()->create()->id,
        'financing_mode' => FinancingMode::Leasing,
    ])->refresh();
    $this->save = app(SaveFinancing::class);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function leasingData(array $overrides = []): array
{
    return array_merge([
        'type' => 'leasing',
        'supplier_id' => test()->lessor->id,
        'reference' => 'LS-2026-001',
        'starts_on' => '2026-01-15',
        'first_due_on' => '2026-02-15',
        'ends_on' => '2029-01-15',
        'initial_payment_amount' => '12 000,000',
        'monthly_amount_excl_tax' => '1 000,000',
        'monthly_amount_incl_tax' => '1 190,000',
        'residual_value_amount' => '5 000,000',
        'purchase_option_on' => '2029-01-15',
    ], $overrides);
}

// --- Échéancier (B-12) ----------------------------------------------------------------

it('génère un loyer par mois de la première échéance à la fin du contrat', function () {
    $financing = $this->save->handle($this->vehicle, null, leasingData(), $this->admin);
    $installments = $financing->installments()->get();

    expect($financing->installments_count)->toBe(36)
        ->and($installments)->toHaveCount(36)
        ->and($installments->first()->due_date->toDateString())->toBe('2026-02-15')
        ->and($installments->last()->due_date->toDateString())->toBe('2029-01-15')
        ->and((string) $installments->first()->amount_excl_tax)->toBe('1000.000')
        ->and((string) $installments->first()->amount_incl_tax)->toBe('1190.000')
        ->and($installments->first()->charge_month->toDateString())->toBe('2026-02-01');
});

it('garde l\'échéance au dernier jour du mois quand le jour n\'existe pas', function () {
    $financing = $this->save->handle($this->vehicle, null, leasingData(['starts_on' => '2026-01-31', 'first_due_on' => '2026-01-31', 'ends_on' => '2026-04-30']), $this->admin);

    expect($financing->installments()->pluck('due_date')->map->toDateString()->all())
        ->toBe(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30']);
});

// --- Scénario 15 -----------------------------------------------------------------------

it('compte chaque loyer une seule fois, au mois de son échéance (scénario 15)', function () {
    $financing = $this->save->handle($this->vehicle, null, leasingData(), $this->admin);
    $charges = app(FinancingCharges::class);

    expect((string) $charges->forVehicleAndMonth($this->vehicle->id, CarbonImmutable::parse('2026-03-01')))->toBe('1000.000');

    // Modifier le contrat (aucune échéance pointée) régénère l'échéancier sans doublon.
    $this->save->handle($this->vehicle, $financing, leasingData(['monthly_amount_excl_tax' => '1 100,000', 'monthly_amount_incl_tax' => '1 309,000']), $this->admin);
    expect(FinancingInstallment::query()->count())->toBe(36)
        ->and((string) $charges->forVehicleAndMonth($this->vehicle->id, CarbonImmutable::parse('2026-03-01')))->toBe('1100.000');

    // Payer en retard ne déplace pas la charge ; un mois sans échéance n'a pas de charge.
    $march = FinancingInstallment::query()->where('sequence', 2)->sole();
    app(MarkInstallmentPaid::class)->handle($march, CarbonImmutable::parse('2026-05-10'), $this->admin);

    expect((string) $charges->forVehicleAndMonth($this->vehicle->id, CarbonImmutable::parse('2026-03-01')))->toBe('1100.000')
        ->and((string) $charges->forVehicleAndMonth($this->vehicle->id, CarbonImmutable::parse('2026-05-01')))->toBe('1100.000')
        ->and((string) $charges->forVehicleAndMonth($this->vehicle->id, CarbonImmutable::parse('2026-01-01')))->toBe('0.000');

    $total = Money::zero();
    for ($month = CarbonImmutable::parse('2026-01-01'); $month->lessThanOrEqualTo(CarbonImmutable::parse('2029-02-01')); $month = $month->addMonth()) {
        $total = $total->plus($charges->forVehicleAndMonth($this->vehicle->id, $month));
    }
    expect((string) $total)->toBe('39600.000');
});

// --- Crédit (B-15, Q59) ------------------------------------------------------------------

it('amortit un crédit : mensualité constante, capital soldé au millime, intérêts seuls en charge', function () {
    $this->vehicle->forceFill(['financing_mode' => FinancingMode::Credit])->saveQuietly();

    $financing = $this->save->handle($this->vehicle->fresh(), null, [
        'type' => 'credit', 'supplier_id' => $this->lessor->id, 'reference' => 'CR-1',
        'starts_on' => '2026-01-01', 'first_due_on' => '2026-02-01',
        'principal_amount' => '30 000,000', 'interest_rate' => '9', 'installments_count' => '36',
    ], $this->admin);

    $installments = $financing->installments()->get();
    $capital = $installments->reduce(fn (Money $sum, FinancingInstallment $i) => $sum->plus($i->amount_excl_tax->minus($i->interest_amount)), Money::zero());
    $interest = $installments->reduce(fn (Money $sum, FinancingInstallment $i) => $sum->plus($i->interest_amount), Money::zero());

    expect($installments)->toHaveCount(36)
        ->and($financing->ends_on->toDateString())->toBe('2029-01-01')
        ->and((string) $capital)->toBe('30000.000')
        ->and((string) $installments->first()->interest_amount)->toBe('225.000')
        ->and($installments->first()->amount_excl_tax->equals($installments[10]->amount_excl_tax))->toBeTrue()
        ->and((string) app(FinancingCharges::class)->forVehicleAndMonth($this->vehicle->id, CarbonImmutable::parse('2026-02-01')))->toBe('225.000')
        ->and($interest->isPositive())->toBeTrue();
});

// --- Contrôles ------------------------------------------------------------------------------

it('refuse un loyer TTC inférieur au HT, un type contraire à la fiche, un second financement en cours', function () {
    expect(fn () => $this->save->handle($this->vehicle, null, leasingData(['monthly_amount_incl_tax' => '999,999']), $this->admin))
        ->toThrow(ValidationException::class);

    expect(fn () => $this->save->handle($this->vehicle, null, leasingData(['type' => 'credit', 'principal_amount' => '1000', 'interest_rate' => '5', 'installments_count' => '12']), $this->admin))
        ->toThrow(FleetException::class, 'Corrigez d\'abord la fiche');

    $this->save->handle($this->vehicle, null, leasingData(), $this->admin);
    expect(fn () => $this->save->handle($this->vehicle, null, leasingData(['reference' => 'LS-2']), $this->admin))
        ->toThrow(FleetException::class, 'déjà un financement en cours');
});

it('ne modifie plus un financement dont une échéance est pointée', function () {
    $financing = $this->save->handle($this->vehicle, null, leasingData(), $this->admin);
    app(MarkInstallmentPaid::class)->handle($financing->installments()->first(), CarbonImmutable::parse('2026-02-15'), $this->admin);

    expect(fn () => $this->save->handle($this->vehicle, $financing, leasingData(['reference' => 'LS-X']), $this->admin))
        ->toThrow(FleetException::class, 'déjà pointées');
});

it('refuse un pointage dans le futur et l\'annule par une date vide', function () {
    $financing = $this->save->handle($this->vehicle, null, leasingData(), $this->admin);
    $first = $financing->installments()->first();

    expect(fn () => app(MarkInstallmentPaid::class)->handle($first, CarbonImmutable::parse('2026-12-01'), $this->admin))->toThrow(FleetException::class);

    app(MarkInstallmentPaid::class)->handle($first, CarbonImmutable::parse('2026-02-15'), $this->admin);
    app(MarkInstallmentPaid::class)->handle($first->fresh(), null, $this->admin);

    expect($first->fresh()?->paid_on)->toBeNull();
});

// --- Fin de contrat (B-14) -------------------------------------------------------------------

it('garde le véhicule quand l\'option est levée, le sort du parc quand il est restitué', function (FinancingOutcome $outcome, VehicleStatus $status) {
    $financing = $this->save->handle($this->vehicle, null, leasingData(), $this->admin);

    app(SettleFinancing::class)->handle($financing, $outcome, CarbonImmutable::parse('2026-11-20'), $this->admin);

    expect($financing->fresh()?->outcome)->toBe($outcome)
        ->and($this->vehicle->fresh()?->status)->toBe($status);

    if ($outcome === FinancingOutcome::Returned) {
        expect($this->vehicle->fresh()?->retirement_reason)->toBe(RetirementReason::LeaseReturn->value);
    }

    expect(fn () => app(SettleFinancing::class)->handle($financing->fresh(), $outcome, CarbonImmutable::parse('2026-11-20'), $this->admin))
        ->toThrow(FleetException::class, 'clôturé');
})->with([
    'option levée' => [FinancingOutcome::OptionExercised, VehicleStatus::Available],
    'restitution' => [FinancingOutcome::Returned, VehicleStatus::Retired],
]);

it('refuse une issue de crédit pour un leasing', function () {
    $financing = $this->save->handle($this->vehicle, null, leasingData(), $this->admin);

    expect(fn () => app(SettleFinancing::class)->handle($financing, FinancingOutcome::Repaid, CarbonImmutable::parse('2026-11-20'), $this->admin))
        ->toThrow(FleetException::class, 'impossible');
});

// --- Droits et écrans --------------------------------------------------------------------------

it('réserve les financements au super admin et à l\'admin', function () {
    expect(fn () => $this->save->handle($this->vehicle, null, leasingData(), $this->agent))->toThrow(FleetException::class);

    $this->actingAs($this->agent);
    $this->get(VehicleFinancingResource::getUrl('index'))->assertForbidden();
});

it('enregistre un leasing depuis l\'écran de l\'admin', function () {
    $this->actingAs($this->admin);

    Livewire::test(CreateVehicleFinancing::class)
        ->fillForm(['vehicle_id' => $this->vehicle->id] + leasingData())
        ->call('create')
        ->assertHasNoFormErrors();

    expect(VehicleFinancing::query()->sole()->installments()->count())->toBe(36);
});
