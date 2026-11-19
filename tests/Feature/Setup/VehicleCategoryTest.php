<?php

use App\Domain\Setup\Actions\SaveVehicleCategory;
use App\Domain\Setup\CategoryRules;
use App\Domain\Setup\Exceptions\SetupException;
use App\Filament\Resources\VehicleCategories\Pages\CreateVehicleCategory;
use App\Filament\Resources\VehicleCategories\Pages\EditVehicleCategory;
use App\Filament\Resources\VehicleCategories\Pages\ListVehicleCategories;
use App\Filament\Resources\VehicleCategories\VehicleCategoryResource;
use App\Models\Agency;
use App\Models\VehicleCategory;
use App\Settings\ReservationSettings;
use App\Support\Authorization\Role;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->superAdmin = withTwoFactor(userWithProfile(Role::SuperAdmin));
    $this->admin = withTwoFactor(userWithProfile(Role::Admin));
    $this->agent = userWithProfile(Role::AgencyUser, ['agency_id' => Agency::factory()->create()->id]);
});

function categoryData(array $overrides = []): array
{
    return array_merge(['code' => 'eco', 'name' => 'Citadine', 'is_active' => true], $overrides);
}

// --- Valeurs générales (Q41, Q42) --------------------------------------------------

it('pose les valeurs générales des questions ouvertes : 60 min, 21 ans, permis de 2 ans', function () {
    $settings = app(ReservationSettings::class);

    expect($settings->preparation_buffer_minutes)->toBe(60)
        ->and($settings->min_driver_age)->toBe(21)
        ->and($settings->min_license_years)->toBe(2);
});

it('applique la valeur générale quand la catégorie ne la surcharge pas, et « non fixé » sinon', function () {
    $category = app(SaveVehicleCategory::class)->handle(null, categoryData(), $this->admin);
    $rules = CategoryRules::for($category);

    expect($rules->minDriverAge)->toBe(21)
        ->and($rules->minLicenseYears)->toBe(2)
        ->and($rules->preparationBufferMinutes)->toBe(60)
        ->and($rules->depositAmount)->toBeNull()
        ->and($rules->insuranceExcessAmount)->toBeNull()
        ->and($rules->extraKmAmount)->toBeNull()
        ->and($rules->hasUnlimitedMileage())->toBeTrue();
});

it('applique les surcharges de la catégorie (Q14, Q41, Q42, Q43, Q45)', function () {
    $category = app(SaveVehicleCategory::class)->handle(null, categoryData([
        'code' => 'suv', 'name' => 'SUV',
        'min_driver_age' => '25', 'min_license_years' => '3', 'preparation_buffer_minutes' => '90',
        'deposit_amount' => '1 500,000', 'insurance_excess_amount' => '2000', 'included_km_per_day' => '250',
        'extra_km_amount' => '0,350',
    ]), $this->admin);
    $rules = CategoryRules::for($category->fresh());

    expect($rules->minDriverAge)->toBe(25)
        ->and($rules->minLicenseYears)->toBe(3)
        ->and($rules->preparationBufferMinutes)->toBe(90)
        ->and($rules->depositAmount?->toDecimalString())->toBe('1500.000')
        ->and($rules->insuranceExcessAmount?->toDecimalString())->toBe('2000.000')
        ->and($rules->includedKmPerDay)->toBe(250)
        ->and($rules->extraKmAmount?->toDecimalString())->toBe('0.350')
        ->and($rules->hasUnlimitedMileage())->toBeFalse();
});

it('suit un changement de la valeur générale pour les catégories sans surcharge', function () {
    $category = app(SaveVehicleCategory::class)->handle(null, categoryData(), $this->admin);
    $settings = app(ReservationSettings::class);
    $settings->min_driver_age = 23;
    $settings->save();

    expect(CategoryRules::for($category, app(ReservationSettings::class))->minDriverAge)->toBe(23);
});

it('stocke les montants en DECIMAL(15,3), sans float', function () {
    $category = app(SaveVehicleCategory::class)->handle(null, categoryData(['deposit_amount' => '300,5']), $this->admin);

    expect(VehicleCategory::query()->toBase()->where('id', $category->id)->value('deposit_amount'))->toBe('300.500');
});

// --- Contrôles ----------------------------------------------------------------------

it('refuse des surcharges hors limites ou des montants invalides', function (array $overrides, string $field) {
    try {
        app(SaveVehicleCategory::class)->handle(null, categoryData($overrides), $this->admin);
        $this->fail('Une ValidationException était attendue.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }

    expect(VehicleCategory::query()->count())->toBe(0);
})->with([
    'âge sous 18 ans' => [['min_driver_age' => '17'], 'min_driver_age'],
    'âge non entier' => [['min_driver_age' => '21,5'], 'min_driver_age'],
    'permis négatif' => [['min_license_years' => '-1'], 'min_license_years'],
    'délai de plus d\'un jour' => [['preparation_buffer_minutes' => '1441'], 'preparation_buffer_minutes'],
    'kilométrage nul' => [['included_km_per_day' => '0'], 'included_km_per_day'],
    'caution négative' => [['deposit_amount' => '-100'], 'deposit_amount'],
    'franchise à 4 décimales' => [['insurance_excess_amount' => '10,1234'], 'insurance_excess_amount'],
    'prix du km en float' => [['extra_km_amount' => 0.35], 'extra_km_amount'],
    'code invalide' => [['code' => 'a b'], 'code'],
    'nom manquant' => [['name' => ''], 'name'],
]);

it('exige un code unique, en majuscules', function () {
    app(SaveVehicleCategory::class)->handle(null, categoryData(), $this->admin);

    expect(VehicleCategory::query()->sole()->code)->toBe('ECO');
    expect(fn () => app(SaveVehicleCategory::class)->handle(null, categoryData(['code' => 'ECO', 'name' => 'Autre']), $this->admin))
        ->toThrow(ValidationException::class);
});

it('garde son code à la modification sans se heurter à lui-même', function () {
    $category = app(SaveVehicleCategory::class)->handle(null, categoryData(), $this->admin);

    app(SaveVehicleCategory::class)->handle($category, categoryData(['name' => 'Citadine éco']), $this->admin);

    expect($category->fresh()?->name)->toBe('Citadine éco');
});

// --- Interface et droits -------------------------------------------------------------

it('crée et modifie une catégorie depuis l\'interface, montants à la française', function () {
    $this->actingAs($this->admin);

    Livewire::test(CreateVehicleCategory::class)
        ->fillForm(categoryData(['deposit_amount' => '800,000', 'min_driver_age' => '23']))
        ->call('create')
        ->assertHasNoFormErrors();

    $category = VehicleCategory::query()->sole();

    Livewire::test(EditVehicleCategory::class, ['record' => $category->getRouteKey()])
        ->assertSchemaStateSet(['deposit_amount' => '800,000', 'min_driver_age' => 23])
        ->fillForm(['deposit_amount' => '900,5'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($category->fresh()?->deposit_amount?->toDecimalString())->toBe('900.500');
});

it('signale un montant mal saisi dans le formulaire', function () {
    $this->actingAs($this->admin);

    Livewire::test(CreateVehicleCategory::class)
        ->fillForm(categoryData(['deposit_amount' => '12,3456']))
        ->call('create')
        ->assertHasFormErrors(['deposit_amount' => 'regex']);
});

it('laisse l\'utilisateur d\'agence lire les catégories, sans les modifier', function () {
    $category = VehicleCategory::factory()->create();
    $this->actingAs($this->agent);

    Livewire::test(ListVehicleCategories::class)
        ->assertCanSeeTableRecords([$category])
        ->assertActionHidden('create')
        ->assertActionHidden(TestAction::make('edit')->table($category))
        ->assertActionDoesNotExist(TestAction::make('delete')->table($category));

    $this->get(VehicleCategoryResource::getUrl('view', ['record' => $category]))->assertOk();
    $this->get(VehicleCategoryResource::getUrl('edit', ['record' => $category]))->assertForbidden();
    $this->get(VehicleCategoryResource::getUrl('create'))->assertForbidden();

    expect(fn () => app(SaveVehicleCategory::class)->handle($category, categoryData(), $this->agent))
        ->toThrow(SetupException::class);
});

it('journalise la modification d\'une catégorie avec avant et après', function () {
    $this->actingAs($this->admin);
    $category = app(SaveVehicleCategory::class)->handle(null, categoryData(['deposit_amount' => '500']), $this->admin);
    app(SaveVehicleCategory::class)->handle($category, categoryData(['deposit_amount' => '600']), $this->admin);

    $activity = Activity::query()->where('log_name', 'audit')->where('event', 'updated')
        ->where('subject_type', VehicleCategory::class)->sole();

    expect($activity->properties['old']['deposit_amount'])->toBe('500.000')
        ->and($activity->properties['attributes']['deposit_amount'])->toBe('600.000');
});
