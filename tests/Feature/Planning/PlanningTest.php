<?php

use App\Domain\Availability\Actions\CloseReservation;
use App\Domain\Availability\Actions\SaveReservation;
use App\Domain\Availability\Planning\PlanningBlockType;
use App\Domain\Availability\Planning\PlanningProjection;
use App\Domain\Availability\Planning\PlanningQuery;
use App\Domain\Setup\Actions\SaveRatePlan;
use App\Domain\Setup\Actions\SaveSeason;
use App\Filament\Pages\Planning;
use App\Filament\Planning\PlanningCalendar;
use App\Filament\Resources\Reservations\Pages\CreateReservation;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Models\Agency;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\VehicleDocument;
use App\Support\Authorization\Role;
use Carbon\CarbonImmutable;
use Guava\Calendar\ValueObjects\CalendarEvent;
use Guava\Calendar\ValueObjects\CalendarResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * Planning définitif (C-02, D35, lot L12) : données réelles, projection « Occupé »
 * (Q38), heure de Tunis, préremplissage de l'assistant, nombre de requêtes constant.
 *
 * Tests témoins des trois contournements de guava/calendar 3.2.2 (condition 1 de D35) :
 * ils reproduisent le défaut du paquet et échoueront le jour où une mise à jour le
 * corrige ; il faudra alors retirer le contournement et mettre à jour
 * docs/rapports/tickets_guava_calendar.md.
 */

beforeEach(function () {
    CarbonImmutable::setTestNow('2027-03-01 08:00');

    $admin = withTwoFactor(userWithProfile(Role::Admin));
    $this->superAdmin = withTwoFactor(userWithProfile(Role::SuperAdmin));
    $this->sfax = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->sousse = Agency::factory()->create(['code' => 'SOU', 'name' => 'Sousse']);
    $this->agentSfax = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sfax->id]);
    $this->agentSousse = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sousse->id]);
    $this->category = VehicleCategory::factory()->create(['code' => 'CIT', 'name' => 'Citadine', 'preparation_buffer_minutes' => 60]);
    $season = app(SaveSeason::class)->handle(null, ['name' => 'Année 2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31'], $admin);
    app(SaveRatePlan::class)->handle(null, [
        'vehicle_category_id' => $this->category->id, 'season_id' => $season->id, 'agency_id' => null,
        'valid_from' => '2027-01-01', 'valid_to' => null, 'is_active' => true,
        'lines' => [['min_days' => 1, 'max_days' => null, 'daily_amount' => '100,000']],
    ], $admin);
    $this->customer = Customer::factory()->create(['origin_agency_id' => $this->sfax->id, 'first_name' => 'Amel', 'last_name' => 'Trabelsi']);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function planningVehicle(int $agencyId): Vehicle
{
    $vehicle = Vehicle::factory()->create(['agency_id' => $agencyId, 'vehicle_category_id' => test()->category->id]);
    foreach (['insurance', 'technical_inspection'] as $type) {
        VehicleDocument::query()->create(['vehicle_id' => $vehicle->id, 'type' => $type, 'valid_from' => '2027-01-01', 'valid_until' => '2027-12-31']);
    }

    return $vehicle;
}

function planningReservation(Vehicle $vehicle, User $agent, string $from = '2027-03-03 09:00', string $to = '2027-03-05 09:00'): Reservation
{
    return app(SaveReservation::class)->handle(null, [
        'customer_id' => test()->customer->id, 'agency_id' => $vehicle->current_agency_id, 'vehicle_category_id' => test()->category->id,
        'vehicle_id' => $vehicle->id, 'starts_at' => $from, 'ends_at' => $to,
    ], $agent);
}

/**
 * @return list<array<string, mixed>>
 */
function planningEvents(User $user): array
{
    test()->actingAs($user);

    return Livewire::test(PlanningCalendar::class)->instance()
        ->getEventsJs(['startStr' => '2027-03-01T00:00:00', 'endStr' => '2027-03-08T00:00:00', 'tzOffset' => 60]);
}

// --- Données réelles et projection (Q38) ----------------------------------------------------

it('montre la réservation de son agence en détail et celle d\'une autre agence en « Occupé »', function () {
    $mine = planningReservation(planningVehicle($this->sfax->id), $this->agentSfax);
    planningReservation(planningVehicle($this->sousse->id), $this->agentSousse);

    $events = collect(planningEvents($this->agentSfax));
    [$own, $foreign] = $events->partition(fn (array $event) => isset($event['extendedProps']['url']));

    expect($own)->toHaveCount(1)
        ->and($own->first()['title'])->toBe("{$mine->number} · Amel Trabelsi")
        ->and($own->first()['extendedProps']['url'])->toBe(ReservationResource::getUrl('view', ['record' => $mine]))
        ->and($foreign)->toHaveCount(1)
        ->and($foreign->first()['title'])->toBe(PlanningProjection::OCCUPIED_LABEL)
        ->and(json_encode($foreign->values()->all()))->not->toContain('Trabelsi')
        ->and(json_encode($foreign->values()->all()))->not->toContain('RES-')
        ->and(json_encode($foreign->values()->all()))->not->toContain('"model"');
});

it('classe en réservation, sans rien en charger, le bloc d\'une autre agence et de son client', function () {
    $sousseCustomer = Customer::factory()->create(['origin_agency_id' => $this->sousse->id, 'first_name' => 'Karim', 'last_name' => 'Sahli']);
    $vehicle = planningVehicle($this->sousse->id);
    app(SaveReservation::class)->handle(null, [
        'customer_id' => $sousseCustomer->id, 'agency_id' => $this->sousse->id, 'vehicle_category_id' => $this->category->id,
        'vehicle_id' => $vehicle->id, 'starts_at' => '2027-03-03 09:00', 'ends_at' => '2027-03-05 09:00',
    ], $this->agentSousse);
    $this->actingAs($this->agentSfax);

    [$block] = app(PlanningQuery::class)
        ->blocks([$vehicle->id], CarbonImmutable::parse('2027-03-01'), CarbonImmutable::parse('2027-03-08'));

    expect($block->type)->toBe(PlanningBlockType::Reservation)
        ->and($block->customerName)->toBeNull()
        ->and($block->reference)->toBeNull()
        ->and(json_encode(planningEvents($this->agentSfax)))->not->toContain('Sahli');
});

it('détaille toutes les réservations pour le super admin', function () {
    planningReservation(planningVehicle($this->sfax->id), $this->agentSfax);
    planningReservation(planningVehicle($this->sousse->id), $this->agentSousse);

    expect(collect(planningEvents($this->superAdmin))->pluck('title')->filter(fn ($t) => str_contains((string) $t, 'Trabelsi')))->toHaveCount(2);
});

it('n\'affiche plus une réservation annulée et ignore les blocs hors de la fenêtre', function () {
    $vehicle = planningVehicle($this->sfax->id);
    $cancelled = planningReservation($vehicle, $this->agentSfax);
    app(CloseReservation::class)->cancel($cancelled, 'Annulation de test', $this->agentSfax);
    planningReservation($vehicle, $this->agentSfax, '2027-04-03 09:00', '2027-04-05 09:00');

    expect(planningEvents($this->agentSfax))->toBe([]);
});

it('fait finir le bloc après le délai de préparation, hachuré', function () {
    planningReservation(planningVehicle($this->sfax->id), $this->agentSfax);

    [$event] = planningEvents($this->agentSfax);

    expect($event['end'])->toStartWith('2027-03-05T10:00')
        ->and($event['styles'])->toContain('repeating-linear-gradient');
});

it('garde un nombre de requêtes constant, quel que soit le nombre de véhicules et de réservations', function () {
    $count = function (): int {
        $this->actingAs($this->superAdmin);
        $widget = Livewire::test(PlanningCalendar::class)->instance();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $widget->getEventsJs(['startStr' => '2027-03-01T00:00:00', 'endStr' => '2027-04-01T00:00:00', 'tzOffset' => 60]);
        $widget->getResourcesJs();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    planningReservation(planningVehicle($this->sfax->id), $this->agentSfax);
    $count();
    $few = $count();

    foreach (range(1, 6) as $i) {
        planningReservation(planningVehicle($i % 2 === 0 ? $this->sfax->id : $this->sousse->id), $i % 2 === 0 ? $this->agentSfax : $this->agentSousse,
            "2027-03-1{$i} 09:00", "2027-03-1{$i} 18:00");
    }

    expect($count())->toBe($few);
});

// --- Clic et préremplissage -------------------------------------------------------------------

it('ouvre l\'assistant prérempli avec le véhicule et la date de la case cliquée', function () {
    $vehicle = planningVehicle($this->sfax->id);
    $this->actingAs($this->agentSfax);

    $url = ReservationResource::getUrl('create', ['vehicle' => $vehicle->id, 'starts' => '2027-03-10 09:00']);
    $this->get($url)->assertOk();

    Livewire::withQueryParams(['vehicle' => $vehicle->id, 'starts' => '2027-03-10 09:00'])
        ->test(CreateReservation::class)
        ->assertSchemaStateSet([
            'vehicle_id' => $vehicle->id,
            'agency_id' => $this->sfax->id,
            'vehicle_category_id' => $this->category->id,
            'starts_at' => '2027-03-10 09:00',
        ]);
});

it('ne préremplit pas un véhicule d\'une agence hors de portée', function () {
    $vehicle = planningVehicle($this->sousse->id);
    $this->actingAs($this->agentSfax);

    Livewire::withQueryParams(['vehicle' => $vehicle->id])
        ->test(CreateReservation::class)
        ->assertSchemaStateSet(['vehicle_id' => null]);
});

it('ouvre le planning à tous les profils qui réservent', function (string $who) {
    $this->actingAs($who === 'agent' ? $this->agentSfax : $this->superAdmin);

    $this->get(Planning::getUrl())->assertOk();
})->with(['agent', 'superAdmin']);

// --- Tests témoins des contournements (condition 1 de D35) ---------------------------------------

it('témoin 1 : guava/calendar 3.2.2 envoie encore les ressources enfants vides', function () {
    $raw = CalendarResource::make('group')->title('Groupe')->children([CalendarResource::make('child')->title('Enfant')])->toCalendarObject();

    // Si ce test échoue, le paquet est corrigé : retirer le contournement 1 (PlanningCalendar::getResources).
    expect(json_encode($raw))->toContain('"children":[{}]');
});

it('contournement 1 : chaque véhicule du planning porte son identifiant et son titre', function () {
    $vehicle = planningVehicle($this->sfax->id);
    $this->actingAs($this->agentSfax);

    $resources = Livewire::test(PlanningCalendar::class)->instance()->getResourcesJs();

    expect($resources[0]['title'])->toBe('Sfax')
        ->and($resources[0]['children'][0]['id'])->toBe('vehicle-'.$vehicle->id)
        ->and($resources[0]['children'][0]['title'])->toContain($vehicle->plate_number);
});

it('témoin 2 : un clic sur un événement sans modèle provoque encore une erreur serveur (ticket 2)', function () {
    $this->actingAs($this->agentSfax);

    // Si ce test échoue, le paquet est corrigé : le contournement 2 peut être simplifié.
    expect(fn () => Livewire::test(PlanningCalendar::class)->call('onEventClickJs', ['event' => ['extendedProps' => []], 'view' => ['type' => 'resourceTimelineWeek'], 'tzOffset' => 60]))
        ->toThrow(Exception::class, 'Could not resolve event record');
});

it('contournement 2 : tout bloc sans lien est marqué non cliquable, et la page le rend inerte', function () {
    planningReservation(planningVehicle($this->sousse->id), $this->agentSousse);

    $events = planningEvents($this->agentSfax);

    expect($events)->not->toBeEmpty()
        ->and(collect($events)->every(fn (array $e) => isset($e['extendedProps']['url']) || str_contains($e['classNames'], 'planning-occupied')))->toBeTrue()
        ->and(file_get_contents(resource_path('views/filament/pages/planning.blade.php')))->toContain('.planning-occupied { pointer-events: none;');
});

it('témoin 3 : EventCalendar embarqué convertit encore au fuseau du navigateur par défaut (ticket 3)', function () {
    $bundle = (string) file_get_contents(base_path('vendor/guava/calendar/dist/js/calendar.js'));

    // Si ce test échoue, la bibliothèque a changé : refaire l'essai du navigateur sur un
    // autre fuseau (docs/rapports/tickets_guava_calendar.md, ticket 3) avant de livrer.
    expect($bundle)->toContain('timeZone:"local"')
        ->and($bundle)->toContain('t==="local"?Ua():t==="UTC"?0:cu(t)');
});

it('contournement 3 : heures envoyées et affichées à l\'heure de Tunis', function () {
    $raw = CalendarEvent::make()->title('x')->start(CarbonImmutable::parse('2027-03-03 09:00', 'Africa/Tunis'))->end(CarbonImmutable::parse('2027-03-03 10:00', 'Africa/Tunis'))
        ->toCalendarObject(-300, true);

    $this->actingAs($this->agentSfax);
    $options = Livewire::test(PlanningCalendar::class)->instance()->getOptions();

    // Même pour un navigateur à UTC−5 : chaîne à l'heure de Tunis, affichage imposé à +01:00.
    expect($raw['start'])->toBe('2027-03-03T09:00:00+01:00')
        ->and($options['timeZone'])->toBe('+01:00')
        ->and($options['nowIndicator'])->toBeFalse();
});

it('contournement 3 : un clic depuis un navigateur à New York préremplit l\'heure de Tunis de la case', function () {
    $vehicle = planningVehicle($this->sfax->id);
    $this->actingAs($this->agentSfax);

    // Case de 06:00 (Tunis) : la bibliothèque la rend en date locale du navigateur à
    // 06:00, soit 11:00 UTC pour New York (UTC−5).
    Livewire::test(PlanningCalendar::class)
        ->call('onDateClickJs', [
            'date' => '2027-03-10T11:00:00.000Z', 'dateStr' => '2027-03-10T06:00:00', 'allDay' => false, 'tzOffset' => -300,
            'view' => ['type' => 'resourceTimelineWeek', 'title' => '', 'currentStart' => '2027-03-08T05:00:00.000Z', 'currentEnd' => '2027-03-15T04:00:00.000Z', 'activeStart' => '2027-03-08T05:00:00.000Z', 'activeEnd' => '2027-03-15T04:00:00.000Z'],
            'resource' => ['id' => 'vehicle-'.$vehicle->id, 'title' => 'x', 'eventBackgroundColor' => null, 'eventTextColor' => null, 'extendedProps' => []],
        ])
        ->assertRedirect(ReservationResource::getUrl('create', ['vehicle' => $vehicle->id, 'starts' => '2027-03-10 06:00']));
});
