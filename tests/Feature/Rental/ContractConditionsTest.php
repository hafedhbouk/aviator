<?php

use App\Domain\Fleet\Actions\SaveVehicle;
use App\Domain\Rental\Actions\OpenContract;
use App\Domain\Setup\Actions\SaveRatePlan;
use App\Domain\Setup\Actions\SaveSeason;
use App\Domain\Setup\Actions\SaveVehicleCategory;
use App\Domain\Setup\Actions\UpdateSettings;
use App\Models\Agency;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\VehicleDocument;
use App\Settings\PricingSettings;
use App\Support\Authorization\Role;
use App\Support\Sequences\SequenceAllocator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/*
 * Conditions de kilométrage, de carburant et de retard (phase 5, lot L4, D-05, D50,
 * D51, Q43, Q44, Q91, Q92) : paramètres vides par défaut, saisis au paramétrage ;
 * conditions figées sur le contrat à sa création.
 */

beforeEach(function () {
    CarbonImmutable::setTestNow('2027-03-01 08:00');

    $this->superAdmin = withTwoFactor(userWithProfile(Role::SuperAdmin));
    $this->admin = withTwoFactor(userWithProfile(Role::Admin));
    $this->sfax = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->agent = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sfax->id]);
    $this->category = VehicleCategory::factory()->create(['code' => 'CIT', 'name' => 'Citadine']);
    $season = app(SaveSeason::class)->handle(null, ['name' => 'Année 2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31'], $this->admin);
    app(SaveRatePlan::class)->handle(null, [
        'vehicle_category_id' => $this->category->id, 'season_id' => $season->id, 'agency_id' => null,
        'valid_from' => '2027-01-01', 'valid_to' => null, 'is_active' => true,
        'lines' => [['min_days' => 1, 'max_days' => null, 'daily_amount' => '100,000']],
    ], $this->admin);
    app(SequenceAllocator::class)->prepare(OpenContract::sequence());
});

afterEach(fn () => CarbonImmutable::setTestNow());

function conditionsVehicle(?int $tank): Vehicle
{
    $vehicle = Vehicle::factory()->create(['agency_id' => test()->sfax->id, 'vehicle_category_id' => test()->category->id, 'fuel_tank_liters' => $tank]);
    foreach (['insurance', 'technical_inspection'] as $type) {
        VehicleDocument::query()->create(['vehicle_id' => $vehicle->id, 'type' => $type, 'valid_from' => '2027-01-01', 'valid_until' => '2027-12-31']);
    }

    return $vehicle;
}

function conditionsContract(Vehicle $vehicle): Contract
{
    return app(OpenContract::class)->atCounter([
        'customer_id' => Customer::factory()->create(['origin_agency_id' => test()->sfax->id])->id, 'agency_id' => test()->sfax->id,
        'vehicle_id' => $vehicle->id, 'starts_at' => '2027-03-10 09:00', 'ends_at' => '2027-03-13 09:00',
    ], test()->agent);
}

it('laisse vides par défaut le prix du litre, les frais de service, le réservoir et le tarif horaire', function () {
    $contract = conditionsContract(conditionsVehicle(null));

    expect(app(PricingSettings::class)->fuel_liter_amount)->toBeNull()
        ->and(app(PricingSettings::class)->fuel_service_amount)->toBeNull()
        ->and($contract->mileage_policy)->toBe(Contract::MILEAGE_UNLIMITED)
        ->and($contract->included_km_per_day)->toBeNull()
        ->and($contract->fuel_tank_liters)->toBeNull()
        ->and($contract->fuel_liter_amount)->toBeNull()
        ->and($contract->fuel_service_amount)->toBeNull()
        ->and($contract->late_hour_amount)->toBeNull()
        ->and($contract->insurance_excess_amount)->toBeNull();
});

it('enregistre le prix du litre et les frais de service au millime, sans float', function () {
    $settings = app(UpdateSettings::class)->handle(app(PricingSettings::class), ['fuel_liter_amount' => '2,525', 'fuel_service_amount' => '10'], $this->superAdmin);

    expect($settings->fuel_liter_amount)->toBe('2.525')
        ->and($settings->fuel_service_amount)->toBe('10.000');

    app(UpdateSettings::class)->handle(app(PricingSettings::class), ['fuel_service_amount' => ''], $this->superAdmin);
    expect(app(PricingSettings::class)->fuel_service_amount)->toBeNull();

    expect(fn () => app(UpdateSettings::class)->handle(app(PricingSettings::class), ['fuel_liter_amount' => 'deux dinars'], $this->superAdmin))
        ->toThrow(ValidationException::class);
});

it('refuse le retard à l\'heure tant qu\'une catégorie active n\'a pas de tarif horaire (D51, Q91)', function () {
    expect(fn () => app(UpdateSettings::class)->handle(app(PricingSettings::class), ['late_unit' => 'hour'], $this->superAdmin))
        ->toThrow(ValidationException::class, 'Citadine')
        ->and(app(PricingSettings::class)->late_unit)->toBe('day');

    app(SaveVehicleCategory::class)->handle($this->category, [
        'code' => 'CIT', 'name' => 'Citadine', 'late_hour_amount' => '15,000', 'is_active' => true,
    ], $this->superAdmin);

    expect((string) $this->category->fresh()?->late_hour_amount)->toBe('15.000');

    app(UpdateSettings::class)->handle(app(PricingSettings::class), ['late_unit' => 'hour'], $this->superAdmin);
    expect(app(PricingSettings::class)->late_unit)->toBe('hour');
});

it('enregistre la capacité du réservoir d\'un véhicule (Q92)', function () {
    $data = [
        'internal_code' => 'V-900', 'plate_number' => '200 TU 9000', 'vin' => 'VF1TANK0000000001', 'vehicle_category_id' => $this->category->id,
        'brand' => 'Peugeot', 'model' => '208', 'year' => '2025', 'fuel' => 'petrol', 'transmission' => 'manual',
        'agency_id' => $this->sfax->id, 'financing_mode' => 'cash', 'fuel_tank_liters' => '44',
    ];

    expect(app(SaveVehicle::class)->handle(null, $data, $this->admin)->fuel_tank_liters)->toBe(44)
        ->and(fn () => app(SaveVehicle::class)->handle(null, ['internal_code' => 'V-901', 'plate_number' => '200 TU 9001', 'vin' => 'VF1TANK0000000002', 'fuel_tank_liters' => '0'] + $data, $this->admin))
        ->toThrow(ValidationException::class);
});

it('fige sur le contrat les conditions du moment : un paramétrage modifié ensuite ne le change pas (D-05)', function () {
    app(UpdateSettings::class)->handle(app(PricingSettings::class), ['fuel_liter_amount' => '2,525', 'fuel_service_amount' => '10,000'], $this->superAdmin);
    $this->category->forceFill([
        'included_km_per_day' => 250, 'extra_km_amount' => '0.300', 'late_hour_amount' => '15.000', 'insurance_excess_amount' => '1500.000',
    ])->save();

    $contract = conditionsContract(conditionsVehicle(45));

    expect($contract->mileage_policy)->toBe(Contract::MILEAGE_DAILY_ALLOWANCE)
        ->and($contract->included_km_per_day)->toBe(250)
        ->and((string) $contract->extra_km_amount)->toBe('0.300')
        ->and($contract->fuel_tank_liters)->toBe(45)
        ->and((string) $contract->fuel_liter_amount)->toBe('2.525')
        ->and((string) $contract->fuel_service_amount)->toBe('10.000')
        ->and((string) $contract->late_hour_amount)->toBe('15.000')
        ->and((string) $contract->insurance_excess_amount)->toBe('1500.000');

    app(UpdateSettings::class)->handle(app(PricingSettings::class), ['fuel_liter_amount' => '3,000'], $this->superAdmin);
    $this->category->forceFill(['extra_km_amount' => '0.500', 'late_hour_amount' => '20.000'])->save();
    Vehicle::query()->whereKey($contract->vehicle_id)->update(['fuel_tank_liters' => 60]);

    $fresh = $contract->fresh();

    expect((string) $fresh?->fuel_liter_amount)->toBe('2.525')
        ->and((string) $fresh?->extra_km_amount)->toBe('0.300')
        ->and((string) $fresh?->late_hour_amount)->toBe('15.000')
        ->and($fresh?->fuel_tank_liters)->toBe(45);
});
