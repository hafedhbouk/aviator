<?php

use App\Domain\Setup\Actions\SaveRatePlan;
use App\Domain\Setup\Actions\SaveSeason;
use App\Enums\VehicleStatus;
use App\Models\Agency;
use App\Models\Customer;
use App\Models\CustomerRecommendation;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\VehicleFinancing;
use App\Support\Authorization\Role;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoFleetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Données de démonstration de l'atelier (lot L15) : chargées par les actions du
 * domaine sur un paramétrage existant, jamais en production, une seule fois.
 */

beforeEach(function () {
    CarbonImmutable::setTestNow('2027-03-01 08:00');
    $this->admin = withTwoFactor(userWithProfile(Role::SuperAdmin));
    $this->sfax = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->sousse = Agency::factory()->create(['code' => 'SOU', 'name' => 'Sousse']);
    $this->category = VehicleCategory::factory()->create(['code' => 'CIT', 'name' => 'Citadine']);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function demoParametrage(): void
{
    $season = app(SaveSeason::class)->handle(null, ['name' => 'Année 2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31'], test()->admin);
    app(SaveRatePlan::class)->handle(null, [
        'vehicle_category_id' => test()->category->id, 'season_id' => $season->id, 'agency_id' => null,
        'valid_from' => '2027-01-01', 'valid_to' => null, 'is_active' => true,
        'lines' => [['min_days' => 1, 'max_days' => null, 'daily_amount' => '100,000']],
    ], test()->admin);
}

it('charge le parc, les clients, les réservations et le leasing de démonstration', function () {
    demoParametrage();

    $this->seed(DemoFleetSeeder::class);

    $vehicles = Vehicle::query()->withoutGlobalScopes()->get();

    expect($vehicles)->toHaveCount(11)
        ->and($vehicles->every(fn (Vehicle $v): bool => str_starts_with($v->internal_code, 'DEMO-')))->toBeTrue()
        ->and(Customer::query()->count())->toBe(5)
        ->and(Customer::query()->where('last_name', '!=', 'DEMO')->count())->toBe(0)
        ->and(Reservation::query()->withoutGlobalScopes()->count())->toBe(6)
        ->and($vehicles->where('status', VehicleStatus::Maintenance))->toHaveCount(2)
        ->and($vehicles->firstWhere('internal_code', 'DEMO-SFX-90')?->status)->toBe(VehicleStatus::Unavailable)
        ->and(CustomerRecommendation::query()->where('level', 'blacklist')->count())->toBe(1)
        ->and(VehicleFinancing::query()->sole()->installments()->count())->toBe(36);
});

it('ne charge rien une seconde fois', function () {
    demoParametrage();
    $this->seed(DemoFleetSeeder::class);

    $this->seed(DemoFleetSeeder::class);

    expect(Vehicle::query()->withoutGlobalScopes()->count())->toBe(11)
        ->and(Reservation::query()->withoutGlobalScopes()->count())->toBe(6);
});

it('refuse de charger la démonstration en production', function () {
    demoParametrage();
    app()->instance('env', 'production');

    expect(fn () => (new DemoFleetSeeder)->run())->toThrow(RuntimeException::class, 'production')
        ->and(Vehicle::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('explique le paramétrage manquant au lieu de charger une démonstration partielle', function () {
    expect(fn () => (new DemoFleetSeeder)->run())->toThrow(RuntimeException::class, 'grille tarifaire')
        ->and(Vehicle::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(Customer::query()->count())->toBe(0);
});
