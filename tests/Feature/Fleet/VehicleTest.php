<?php

use App\Domain\Fleet\Actions\SaveVehicle;
use App\Domain\Fleet\Actions\SaveVehicleDocument;
use App\Domain\Fleet\Exceptions\FleetException;
use App\Domain\Fleet\PlateNumber;
use App\Enums\FinancingMode;
use App\Enums\VehicleDocumentType;
use App\Enums\VehicleStatus;
use App\Filament\Resources\Vehicles\Pages\CreateVehicle;
use App\Filament\Resources\Vehicles\Pages\ListVehicles;
use App\Filament\Resources\Vehicles\VehicleResource;
use App\Models\Agency;
use App\Models\Supplier;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\VehicleDocument;
use App\Support\Authorization\Role;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/*
 * Parc, lot L2 (B-01 à B-05) : fiche véhicule, unicité, droits par profil, portée
 * par la localisation, documents datés.
 */

beforeEach(function () {
    $this->superAdmin = withTwoFactor(userWithProfile(Role::SuperAdmin));
    $this->admin = withTwoFactor(userWithProfile(Role::Admin));
    $this->djerba = Agency::factory()->headOffice()->create(['code' => 'DJE', 'name' => 'Djerba']);
    $this->sfax = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->sousse = Agency::factory()->create(['code' => 'SOU', 'name' => 'Sousse']);
    $this->agent = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sfax->id]);
    $this->category = VehicleCategory::factory()->create(['code' => 'CIT', 'name' => 'Citadine']);
});

function vehicleData(array $overrides = []): array
{
    return array_merge([
        'internal_code' => 'v-001',
        'plate_number' => '123 tu 4567',
        'vin' => 'vf1 abc 1234567890',
        'vehicle_category_id' => test()->category->id,
        'brand' => 'Renault',
        'model' => 'Clio',
        'year' => '2024',
        'fuel' => 'petrol',
        'transmission' => 'manual',
        'agency_id' => test()->sfax->id,
        'acquired_on' => '2024-03-15',
        'financing_mode' => 'leasing',
        'purchase_amount' => '45 000,500',
        'initial_contribution_amount' => '9000',
        'planned_holding_months' => '48',
    ], $overrides);
}

// --- Fiche et règles -----------------------------------------------------------

it('crée un véhicule dans son agence, à 0 km, indisponible tant qu\'il n\'a pas ses documents (D41)', function () {
    $vehicle = app(SaveVehicle::class)->handle(null, vehicleData(), $this->admin);

    expect($vehicle->internal_code)->toBe('V-001')
        ->and($vehicle->plate_number)->toBe('123 TU 4567')
        ->and($vehicle->vin)->toBe('VF1ABC1234567890')
        ->and($vehicle->status)->toBe(VehicleStatus::Unavailable)
        ->and($vehicle->statusLogs()->orderBy('id')->pluck('to_status')->all())
        ->toBe([VehicleStatus::Available, VehicleStatus::Unavailable])
        ->and($vehicle->mileage)->toBe(0)
        ->and($vehicle->current_agency_id)->toBe($this->sfax->id)
        ->and($vehicle->financing_mode)->toBe(FinancingMode::Leasing)
        ->and((string) $vehicle->fresh()?->purchase_amount)->toBe('45000.500')
        ->and((string) $vehicle->fresh()?->initial_contribution_amount)->toBe('9000.000')
        ->and(Activity::query()->where('subject_type', $vehicle->getMorphClass())->where('subject_id', $vehicle->id)->exists())->toBeTrue();
});

it('ignore le statut et le kilométrage envoyés dans le formulaire', function () {
    $vehicle = app(SaveVehicle::class)->handle(null, vehicleData(['status' => 'retired', 'mileage' => '99999']), $this->admin);

    expect($vehicle->fresh()?->status)->not->toBe(VehicleStatus::Retired)
        ->and($vehicle->fresh()?->mileage)->toBe(0);
});

it('refuse une immatriculation déjà connue, quelle que soit sa présentation', function (string $plate) {
    app(SaveVehicle::class)->handle(null, vehicleData(), $this->admin);

    expect(fn () => app(SaveVehicle::class)->handle(null, vehicleData([
        'internal_code' => 'V-002', 'vin' => 'VF1XYZ0000000001', 'plate_number' => $plate,
    ]), $this->admin))->toThrow(ValidationException::class);

    expect(Vehicle::query()->count())->toBe(1);
})->with(['123TU4567', '123-TU-4567', ' 123 tu  4567 ']);

it('refuse un numéro de châssis ou un code interne déjà connus', function (array $duplicate) {
    app(SaveVehicle::class)->handle(null, vehicleData(), $this->admin);

    expect(fn () => app(SaveVehicle::class)->handle(null, vehicleData(array_merge([
        'internal_code' => 'V-002', 'plate_number' => '999 TU 9999', 'vin' => 'VF1XYZ0000000001',
    ], $duplicate)), $this->admin))->toThrow(ValidationException::class);
})->with([
    'châssis' => [['vin' => 'vf1abc1234567890']],
    'code interne' => [['internal_code' => 'v-001']],
]);

it('permet de modifier un véhicule sans buter sur sa propre immatriculation', function () {
    $vehicle = app(SaveVehicle::class)->handle(null, vehicleData(), $this->admin);

    app(SaveVehicle::class)->handle($vehicle, vehicleData(['color' => 'Blanc']), $this->admin);

    expect($vehicle->fresh()?->color)->toBe('Blanc');
});

it('refuse une catégorie ou une agence inactive pour un nouveau véhicule', function (string $what) {
    if ($what === 'category') {
        $this->category->forceFill(['is_active' => false])->save();
    } else {
        $this->sfax->forceFill(['is_active' => false])->save();
    }

    expect(fn () => app(SaveVehicle::class)->handle(null, vehicleData(), $this->admin))
        ->toThrow(FleetException::class);
})->with(['category', 'agency']);

it('refuse un montant négatif ou une date d\'acquisition future', function (array $invalid) {
    expect(fn () => app(SaveVehicle::class)->handle(null, vehicleData($invalid), $this->admin))
        ->toThrow(ValidationException::class);
})->with([
    'prix négatif' => [['purchase_amount' => '-1']],
    'trois décimales de trop' => [['initial_contribution_amount' => '10,0001']],
    'acquisition future' => [['acquired_on' => '2099-01-01']],
]);

it('ne modifie plus un véhicule sorti du parc', function () {
    $vehicle = app(SaveVehicle::class)->handle(null, vehicleData(), $this->admin);
    $vehicle->forceFill(['status' => VehicleStatus::Retired])->saveQuietly();

    expect(fn () => app(SaveVehicle::class)->handle($vehicle, vehicleData(['color' => 'Rouge']), $this->admin))
        ->toThrow(FleetException::class, 'sorti du parc');
});

it('ne change jamais la localisation par la fiche', function () {
    $vehicle = app(SaveVehicle::class)->handle(null, vehicleData(), $this->admin);

    app(SaveVehicle::class)->handle($vehicle, vehicleData(['agency_id' => $this->sousse->id, 'current_agency_id' => $this->djerba->id]), $this->admin);

    expect($vehicle->fresh()?->agency_id)->toBe($this->sousse->id)
        ->and($vehicle->fresh()?->current_agency_id)->toBe($this->sfax->id);
});

it('met une immatriculation sous sa forme canonique', function (string $input, string $expected) {
    expect(PlateNumber::canonical($input))->toBe($expected);
})->with([
    ['123tu4567', '123 TU 4567'],
    ['123 TU 4567', '123 TU 4567'],
    ['123-TU-4567', '123 TU 4567'],
    ['rs 123456', 'RS 123456'],
    ['  12.345 ', '12345'],
]);

// --- Droits et portée ----------------------------------------------------------

it('réserve la création et la modification au super admin et à l\'admin (scénario 12)', function () {
    $vehicle = app(SaveVehicle::class)->handle(null, vehicleData(), $this->admin);

    expect(fn () => app(SaveVehicle::class)->handle(null, vehicleData(['internal_code' => 'V-9', 'plate_number' => '9 TU 9', 'vin' => 'VF1Z9999999']), $this->agent))
        ->toThrow(FleetException::class)
        ->and(fn () => app(SaveVehicle::class)->handle($vehicle, vehicleData(['color' => 'Noir']), $this->agent))
        ->toThrow(FleetException::class);

    expect($vehicle->fresh()?->color)->toBeNull();
});

it('montre à l\'agent les seuls véhicules localisés dans ses agences', function () {
    $here = Vehicle::factory()->create(['agency_id' => $this->sousse->id, 'current_agency_id' => $this->sfax->id, 'vehicle_category_id' => $this->category->id]);
    $elsewhere = Vehicle::factory()->create(['agency_id' => $this->sfax->id, 'current_agency_id' => $this->sousse->id, 'vehicle_category_id' => $this->category->id]);

    $this->actingAs($this->agent);

    Livewire::test(ListVehicles::class)
        ->assertCanSeeTableRecords([$here])
        ->assertCanNotSeeTableRecords([$elsewhere])
        ->assertActionHidden('create')
        ->assertActionHidden(TestAction::make('edit')->table($here));

    $this->get(VehicleResource::getUrl('view', ['record' => $here]))->assertOk();
    $this->get(VehicleResource::getUrl('edit', ['record' => $here]))->assertForbidden();
    $this->get(VehicleResource::getUrl('create'))->assertForbidden();
    $this->get(VehicleResource::getUrl('view', ['record' => $elsewhere]))->assertNotFound();
});

it('liste tout le parc pour le super admin et l\'admin, sans suppression', function (string $who) {
    $vehicles = Vehicle::factory()->count(2)->sequence(
        ['agency_id' => $this->sfax->id, 'current_agency_id' => $this->sfax->id],
        ['agency_id' => $this->sousse->id, 'current_agency_id' => $this->sousse->id],
    )->create(['vehicle_category_id' => $this->category->id]);

    $this->actingAs($this->{$who});

    Livewire::test(ListVehicles::class)
        ->assertCanSeeTableRecords($vehicles)
        ->assertActionDoesNotExist(TestAction::make('delete')->table($vehicles[0]));

    $this->get(VehicleResource::getUrl('edit', ['record' => $vehicles[1]]))->assertOk();
})->with(['superAdmin', 'admin']);

it('crée un véhicule depuis l\'écran de l\'admin', function () {
    $this->actingAs($this->admin);

    Livewire::test(CreateVehicle::class)
        ->fillForm(vehicleData())
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Vehicle::query()->where('plate_number', '123 TU 4567')->exists())->toBeTrue();
});

// --- Documents (B-05) ----------------------------------------------------------

it('enregistre une assurance avec compagnie, police et échéance', function () {
    $vehicle = Vehicle::factory()->create(['agency_id' => $this->sfax->id, 'vehicle_category_id' => $this->category->id]);
    $insurer = Supplier::query()->create(['type' => 'insurer', 'name' => 'Assureur fictif', 'is_active' => true]);

    $document = app(SaveVehicleDocument::class)->handle($vehicle, null, [
        'type' => 'insurance', 'supplier_id' => $insurer->id, 'reference' => 'POL-42',
        'valid_from' => '2026-01-01', 'valid_until' => '2026-12-31', 'amount' => '1 250,000',
    ], $this->admin);

    expect($document->type)->toBe(VehicleDocumentType::Insurance)
        ->and($document->created_by)->toBe($this->admin->id)
        ->and($document->isValidOn(CarbonImmutable::parse('2026-12-31 23:59')))->toBeTrue()
        ->and($document->isValidOn(CarbonImmutable::parse('2027-01-01 00:00')))->toBeFalse()
        ->and($document->isValidOn(CarbonImmutable::parse('2025-12-31')))->toBeFalse();
});

it('exige une échéance, sauf pour la carte grise, et une échéance après le début', function () {
    $vehicle = Vehicle::factory()->create(['agency_id' => $this->sfax->id, 'vehicle_category_id' => $this->category->id]);
    $save = fn (array $data) => app(SaveVehicleDocument::class)->handle($vehicle, null, $data, $this->admin);

    expect(fn () => $save(['type' => 'technical_inspection', 'valid_from' => '2026-01-01']))->toThrow(ValidationException::class)
        ->and(fn () => $save(['type' => 'insurance', 'valid_from' => '2026-06-01', 'valid_until' => '2026-05-31']))->toThrow(ValidationException::class);

    expect($save(['type' => 'registration', 'reference' => 'CG-1'])->valid_until)->toBeNull()
        ->and($save(['type' => 'road_tax', 'valid_until' => '2026-12-31'])->valid_from)->toBeNull();
});

it('refuse à l\'agent l\'ajout ou la modification d\'un document', function () {
    $vehicle = Vehicle::factory()->create(['agency_id' => $this->sfax->id, 'vehicle_category_id' => $this->category->id]);

    expect(fn () => app(SaveVehicleDocument::class)->handle($vehicle, null, ['type' => 'road_tax', 'valid_until' => '2026-12-31'], $this->agent))
        ->toThrow(FleetException::class);
    expect(VehicleDocument::query()->count())->toBe(0);
});

it('ne rattache jamais un document à un autre véhicule', function () {
    $first = Vehicle::factory()->create(['agency_id' => $this->sfax->id, 'vehicle_category_id' => $this->category->id]);
    $second = Vehicle::factory()->create(['agency_id' => $this->sfax->id, 'vehicle_category_id' => $this->category->id]);
    $document = app(SaveVehicleDocument::class)->handle($first, null, ['type' => 'road_tax', 'valid_until' => '2026-12-31'], $this->admin);

    expect(fn () => app(SaveVehicleDocument::class)->handle($second, $document, ['type' => 'road_tax', 'valid_until' => '2027-12-31'], $this->admin))
        ->toThrow(FleetException::class);
    expect($document->fresh()?->vehicle_id)->toBe($first->id);
});
