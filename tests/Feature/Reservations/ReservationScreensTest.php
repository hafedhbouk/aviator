<?php

use App\Domain\Availability\Actions\SaveReservation;
use App\Domain\Crm\Actions\AddRecommendation;
use App\Domain\Setup\Actions\SaveRatePlan;
use App\Domain\Setup\Actions\SaveSeason;
use App\Enums\ReservationStatus;
use App\Filament\Resources\Reservations\Pages\CreateReservation;
use App\Filament\Resources\Reservations\Pages\ListReservations;
use App\Filament\Resources\Reservations\Pages\ViewReservation;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Models\Agency;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\VehicleDocument;
use App\Support\Authorization\Role;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * Écrans des réservations (lot L11) : liste limitée à l'agence (RG-13), assistant du
 * comptoir (parcours P1), refus affichés sans rien enregistrer, actions de la fiche.
 */

beforeEach(function () {
    CarbonImmutable::setTestNow('2027-03-01 08:00');

    $this->superAdmin = withTwoFactor(userWithProfile(Role::SuperAdmin));
    $admin = withTwoFactor(userWithProfile(Role::Admin));
    $this->sfax = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->sousse = Agency::factory()->create(['code' => 'SOU', 'name' => 'Sousse']);
    $this->agent = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sfax->id]);
    $this->agentSousse = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sousse->id]);

    $this->category = VehicleCategory::factory()->create(['code' => 'CIT', 'name' => 'Citadine']);
    $season = app(SaveSeason::class)->handle(null, ['name' => 'Année 2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31'], $admin);
    app(SaveRatePlan::class)->handle(null, [
        'vehicle_category_id' => $this->category->id, 'season_id' => $season->id, 'agency_id' => null,
        'valid_from' => '2027-01-01', 'valid_to' => null, 'is_active' => true,
        'lines' => [['min_days' => 1, 'max_days' => null, 'daily_amount' => '100,000']],
    ], $admin);

    $this->vehicle = Vehicle::factory()->create(['agency_id' => $this->sfax->id, 'vehicle_category_id' => $this->category->id]);
    foreach (['insurance', 'technical_inspection'] as $type) {
        VehicleDocument::query()->create(['vehicle_id' => $this->vehicle->id, 'type' => $type, 'valid_from' => '2027-01-01', 'valid_until' => '2027-12-31']);
    }

    $this->customer = Customer::factory()->create(['origin_agency_id' => $this->sfax->id, 'first_name' => 'Amel', 'last_name' => 'Trabelsi']);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function screenFormData(array $overrides = []): array
{
    return array_merge([
        'customer_id' => test()->customer->id,
        'agency_id' => test()->sfax->id,
        'vehicle_category_id' => test()->category->id,
        'starts_at' => '2027-03-10 09:00:00',
        'ends_at' => '2027-03-13 09:00:00',
        'vehicle_id' => test()->vehicle->id,
        'option_lines' => [],
        'status' => 'confirmed',
    ], $overrides);
}

function screenFoundCustomer(bool $blacklisted = false): array
{
    return [['customer_id' => test()->customer->id, 'name' => 'Amel Trabelsi', 'blacklisted' => $blacklisted, 'watched' => false, 'rentals' => 0]];
}

it('crée une réservation par l\'assistant du comptoir', function () {
    $this->actingAs($this->agent);

    Livewire::test(CreateReservation::class)
        ->set('customerResults', screenFoundCustomer())
        ->fillForm(screenFormData())
        ->call('create')
        ->assertHasNoFormErrors();

    $reservation = Reservation::query()->sole();

    expect($reservation->status)->toBe(ReservationStatus::Confirmed)
        ->and($reservation->vehicle_id)->toBe($this->vehicle->id)
        ->and($reservation->created_by)->toBe($this->agent->id);
});

it('affiche le devis calculé par le domaine pendant la saisie', function () {
    $this->actingAs($this->agent);

    Livewire::test(CreateReservation::class)
        ->set('customerResults', screenFoundCustomer())
        ->fillForm(screenFormData())
        ->assertSee('3 jour(s) : location 300,000 DT')
        ->assertSee('total HT 300,000 DT');
});

it('affiche le refus d\'une réservation sur liste noire et n\'enregistre rien', function () {
    app(AddRecommendation::class)->handle($this->customer, ['level' => 'blacklist', 'reason_code' => 'fraude'], $this->superAdmin);
    $this->actingAs($this->agent);

    // Même si l'option était forcée dans le formulaire, le domaine refuse.
    Livewire::test(CreateReservation::class)
        ->set('customerResults', screenFoundCustomer())
        ->fillForm(screenFormData())
        ->call('create')
        ->assertNotified('Réservation impossible pour ce client. Contactez le super admin.');

    expect(Reservation::query()->count())->toBe(0);
});

it('montre à l\'agent les seules réservations de son agence (RG-13)', function () {
    $mine = app(SaveReservation::class)->handle(null, screenFormData(), $this->agent);
    $sousseVehicle = Vehicle::factory()->create(['agency_id' => $this->sousse->id, 'vehicle_category_id' => $this->category->id]);
    foreach (['insurance', 'technical_inspection'] as $type) {
        VehicleDocument::query()->create(['vehicle_id' => $sousseVehicle->id, 'type' => $type, 'valid_from' => '2027-01-01', 'valid_until' => '2027-12-31']);
    }
    $theirs = app(SaveReservation::class)->handle(null, screenFormData(['agency_id' => $this->sousse->id, 'vehicle_id' => $sousseVehicle->id]), $this->agentSousse);

    $this->actingAs($this->agent);

    Livewire::test(ListReservations::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs])
        ->assertActionDoesNotExist(TestAction::make('delete')->table($mine));

    $this->get(ReservationResource::getUrl('view', ['record' => $mine]))->assertOk();
    $this->get(ReservationResource::getUrl('view', ['record' => $theirs]))->assertNotFound();
    $this->get(ReservationResource::getUrl('create'))->assertOk();
});

it('annule une réservation depuis sa fiche, motif obligatoire', function () {
    $reservation = app(SaveReservation::class)->handle(null, screenFormData(), $this->agent);
    $this->actingAs($this->agent);

    Livewire::test(ViewReservation::class, ['record' => $reservation->getRouteKey()])
        ->callAction('cancel', ['reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);

    expect($reservation->fresh()?->status)->toBe(ReservationStatus::Confirmed);

    Livewire::test(ViewReservation::class, ['record' => $reservation->getRouteKey()])
        ->callAction('cancel', ['reason' => 'Le client a annulé par téléphone'])
        ->assertHasNoActionErrors();

    expect($reservation->fresh()?->status)->toBe(ReservationStatus::Cancelled);
});

it('affecte un véhicule depuis la fiche d\'une réservation sans véhicule', function () {
    $reservation = app(SaveReservation::class)->handle(null, screenFormData(['vehicle_id' => null]), $this->agent);
    $this->actingAs($this->agent);

    Livewire::test(ViewReservation::class, ['record' => $reservation->getRouteKey()])
        ->callAction('assignVehicle', ['vehicle_id' => $this->vehicle->id])
        ->assertHasNoActionErrors();

    expect($reservation->fresh()?->vehicle_id)->toBe($this->vehicle->id);
});

it('modifie les dates depuis la fiche et affiche le refus d\'une période impossible', function () {
    $reservation = app(SaveReservation::class)->handle(null, screenFormData(), $this->agent);
    $this->actingAs($this->agent);

    Livewire::test(ViewReservation::class, ['record' => $reservation->getRouteKey()])
        ->callAction('reschedule', ['starts_at' => '2027-03-10 09:00:00', 'ends_at' => '2027-03-15 09:00:00'])
        ->assertHasNoActionErrors();

    expect($reservation->fresh()?->quote['billable_days'])->toBe(5);
});
