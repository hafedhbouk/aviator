<?php

use App\Domain\Availability\Actions\SaveReservation;
use App\Domain\Fleet\Actions\SaveVehicle;
use App\Domain\Fleet\Exceptions\FleetException;
use App\Domain\Fleet\Financing\Actions\SaveFinancing;
use App\Domain\Setup\Actions\SaveRatePlan;
use App\Domain\Setup\Actions\SaveSeason;
use App\Enums\FinancingMode;
use App\Filament\Pages\Planning;
use App\Filament\Pages\SwitchAgency;
use App\Filament\Resources\Reservations\Pages\CreateReservation;
use App\Filament\Resources\Reservations\Pages\ViewReservation;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Filament\Resources\VehicleFinancings\Pages\CreateVehicleFinancing;
use App\Filament\Resources\VehicleFinancings\VehicleFinancingResource;
use App\Filament\Resources\Vehicles\Pages\EditVehicle;
use App\Filament\Resources\Vehicles\VehicleResource;
use App\Models\Agency;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\VehicleDocument;
use App\Models\VehicleFinancing;
use App\Support\Authorization\Role;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * Droits de la phase 4 (lot L14) : matrice par profil sur les nouveaux écrans, et
 * scénario 12 rejoué par type de document (réservation, véhicule, financement) à
 * trois niveaux : policy, action de domaine, requête Livewire directe.
 * Le domaine des réservations est aussi couvert par ReservationTest (RG-13).
 */

beforeEach(function () {
    CarbonImmutable::setTestNow('2027-03-01 08:00');

    $this->sfax = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->sousse = Agency::factory()->create(['code' => 'SOU', 'name' => 'Sousse']);
    $this->users = [
        'super admin' => withTwoFactor(userWithProfile(Role::SuperAdmin)),
        'admin' => withTwoFactor(userWithProfile(Role::Admin)),
        'agent de Sfax' => userWithProfile(Role::AgencyUser, ['agency_id' => $this->sfax->id]),
    ];
    $category = VehicleCategory::factory()->create();
    $this->sfaxVehicle = Vehicle::factory()->create(['agency_id' => $this->sfax->id, 'vehicle_category_id' => $category->id, 'financing_mode' => FinancingMode::Leasing]);
    $this->sousseVehicle = Vehicle::factory()->create(['agency_id' => $this->sousse->id, 'vehicle_category_id' => $category->id, 'financing_mode' => FinancingMode::Leasing]);

    // Réservations créées par le domaine, comme au comptoir (saison, grille, documents).
    $admin = $this->users['admin'];
    $season = app(SaveSeason::class)->handle(null, ['name' => 'Année 2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31'], $admin);
    app(SaveRatePlan::class)->handle(null, [
        'vehicle_category_id' => $category->id, 'season_id' => $season->id, 'agency_id' => null,
        'valid_from' => '2027-01-01', 'valid_to' => null, 'is_active' => true,
        'lines' => [['min_days' => 1, 'max_days' => null, 'daily_amount' => '100,000']],
    ], $admin);
    $reservation = function (Agency $agency, Vehicle $vehicle) use ($category, $admin): Reservation {
        foreach (['insurance', 'technical_inspection'] as $type) {
            VehicleDocument::query()->create(['vehicle_id' => $vehicle->id, 'type' => $type, 'valid_from' => '2027-01-01', 'valid_until' => '2027-12-31']);
        }

        return app(SaveReservation::class)->handle(null, [
            'customer_id' => Customer::factory()->create(['origin_agency_id' => $agency->id])->id,
            'agency_id' => $agency->id, 'vehicle_category_id' => $category->id, 'vehicle_id' => $vehicle->id,
            'starts_at' => '2027-03-10 09:00', 'ends_at' => '2027-03-12 09:00',
        ], $admin);
    };
    $this->sfaxReservation = $reservation($this->sfax, $this->sfaxVehicle);
    $this->sousseReservation = $reservation($this->sousse, $this->sousseVehicle);

    $this->lessor = Supplier::query()->create(['type' => 'leasing', 'name' => 'Société de leasing fictive', 'is_active' => true]);
    $this->financing = app(SaveFinancing::class)->handle($this->sfaxVehicle, null, [
        'type' => 'leasing', 'supplier_id' => $this->lessor->id, 'reference' => 'LS-2027-001',
        'starts_on' => '2027-01-15', 'first_due_on' => '2027-02-15', 'ends_on' => '2030-01-15',
        'initial_payment_amount' => '0,000', 'monthly_amount_excl_tax' => '1 000,000', 'monthly_amount_incl_tax' => '1 190,000',
        'residual_value_amount' => '0,000', 'purchase_option_on' => '2030-01-15',
    ], $this->users['admin']);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * Code HTTP attendu pour chaque écran : super admin, admin, agent de Sfax.
 *
 * @return array<string, array{callable(): string, int, int, int}>
 */
function phase4Screens(): array
{
    return [
        'parc : liste' => [fn () => VehicleResource::getUrl('index'), 200, 200, 200],
        'parc : création' => [fn () => VehicleResource::getUrl('create'), 200, 200, 403],
        'parc : fiche de Sfax' => [fn () => VehicleResource::getUrl('view', ['record' => test()->sfaxVehicle]), 200, 200, 200],
        'parc : modification de Sfax' => [fn () => VehicleResource::getUrl('edit', ['record' => test()->sfaxVehicle]), 200, 200, 403],
        'parc : fiche de Sousse' => [fn () => VehicleResource::getUrl('view', ['record' => test()->sousseVehicle]), 200, 200, 404],
        'financements : liste' => [fn () => VehicleFinancingResource::getUrl('index'), 200, 200, 403],
        'financements : création' => [fn () => VehicleFinancingResource::getUrl('create'), 200, 200, 403],
        'financements : fiche' => [fn () => VehicleFinancingResource::getUrl('view', ['record' => test()->financing]), 200, 200, 403],
        'réservations : liste' => [fn () => ReservationResource::getUrl('index'), 200, 200, 200],
        'réservations : assistant' => [fn () => ReservationResource::getUrl('create'), 200, 200, 200],
        'réservations : fiche de Sfax' => [fn () => ReservationResource::getUrl('view', ['record' => test()->sfaxReservation]), 200, 200, 200],
        'réservations : fiche de Sousse' => [fn () => ReservationResource::getUrl('view', ['record' => test()->sousseReservation]), 200, 200, 404],
        'planning' => [fn () => Planning::getUrl(), 200, 200, 200],
        'agence active (sans remplacement)' => [fn () => SwitchAgency::getUrl(), 403, 403, 403],
    ];
}

it('applique la matrice des droits de la phase 4 à chaque profil', function (string $profile, int $column) {
    $this->actingAs($this->users[$profile]);
    $failures = [];

    foreach (phase4Screens() as $screen => $expected) {
        $status = $this->get($expected[0]())->status();

        if ($status !== $expected[$column]) {
            $failures[] = "{$screen} : {$status} au lieu de {$expected[$column]}";
        }
    }

    expect($failures)->toBe([]);
})->with([
    'super admin' => ['super admin', 1],
    'admin' => ['admin', 2],
    'agent de Sfax' => ['agent de Sfax', 3],
]);

// --- Scénario 12 : un agent de Sfax agit sur un document de Sousse ---------------------------

it('refuse à l\'agent de Sfax la réservation de Sousse : policy, domaine, requête Livewire', function () {
    /** @var User $agent */
    $agent = $this->users['agent de Sfax'];

    expect($agent->can('view', $this->sousseReservation))->toBeFalse()
        ->and($agent->can('update', $this->sousseReservation))->toBeFalse()
        ->and($agent->can('update', $this->sfaxReservation))->toBeTrue();

    $this->actingAs($agent);

    // Fiche ouverte par une requête Livewire directe : introuvable dans sa portée.
    Livewire::test(ViewReservation::class, ['record' => $this->sousseReservation->getKey()])->assertNotFound();

    // Assistant : agence de Sousse injectée dans l'état du formulaire, refusée.
    Livewire::test(CreateReservation::class)
        ->set('data.agency_id', $this->sousse->id)
        ->call('create')
        ->assertHasErrors(['data.agency_id']);

    expect(Reservation::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('refuse à l\'agent la modification d\'un véhicule : policy, domaine, requête Livewire', function () {
    /** @var User $agent */
    $agent = $this->users['agent de Sfax'];

    expect($agent->can('update', $this->sfaxVehicle))->toBeFalse()
        ->and($agent->can('update', $this->sousseVehicle))->toBeFalse()
        ->and(fn () => app(SaveVehicle::class)->handle($this->sousseVehicle, ['color' => 'Noir'], $agent))
        ->toThrow(FleetException::class);

    $this->actingAs($agent);

    Livewire::test(EditVehicle::class, ['record' => $this->sfaxVehicle->getKey()])->assertForbidden();
    Livewire::test(EditVehicle::class, ['record' => $this->sousseVehicle->getKey()])->assertNotFound();

    expect($this->sousseVehicle->fresh()?->color)->not->toBe('Noir');
});

it('refuse à l\'agent tout financement : policy, domaine, requête Livewire', function () {
    /** @var User $agent */
    $agent = $this->users['agent de Sfax'];

    expect($agent->can('viewAny', VehicleFinancing::class))->toBeFalse()
        ->and($agent->can('view', $this->financing))->toBeFalse()
        ->and($agent->can('create', VehicleFinancing::class))->toBeFalse()
        ->and(fn () => app(SaveFinancing::class)->handle($this->sousseVehicle, null, ['type' => 'leasing'], $agent))
        ->toThrow(FleetException::class);

    $this->actingAs($agent);

    Livewire::test(CreateVehicleFinancing::class)->assertForbidden();

    expect(VehicleFinancing::query()->count())->toBe(1);
});

// --- Recherche globale et sélecteurs (D13) --------------------------------------------------

it('ferme la recherche globale sur les écrans de la phase 4', function () {
    foreach ([VehicleResource::class, VehicleFinancingResource::class, ReservationResource::class] as $resource) {
        expect($resource::canGloballySearch())->toBeFalse($resource);
    }
});

it('ne propose dans l\'assistant aucun client avant une recherche, et refuse un client injecté', function () {
    $this->actingAs($this->users['agent de Sfax']);
    $stranger = Customer::factory()->create(['origin_agency_id' => $this->sousse->id]);

    Livewire::test(CreateReservation::class)
        ->assertSet('customerResults', [])
        ->set('data.customer_id', $stranger->id)
        ->call('create')
        ->assertHasErrors(['data.customer_id']);
});
