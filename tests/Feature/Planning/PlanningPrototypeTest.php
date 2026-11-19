<?php

use App\Domain\Availability\Planning\PlanningBlock;
use App\Domain\Availability\Planning\PlanningBlockType;
use App\Domain\Availability\Planning\PlanningProjection;
use App\Filament\Pages\PlanningPrototype;
use App\Filament\Prototype\PlanningDemoData;
use App\Filament\Prototype\PlanningPrototypeCalendar;
use App\Models\Agency;
use App\Support\Authorization\Role;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * Prototype du planning (plan de la phase 4, lot L0, critère n° 3) : les données
 * envoyées au navigateur sont construites côté serveur ; un bloc d'une agence sur
 * laquelle l'utilisateur n'agit pas devient « Occupé », sans nom, numéro ni lien (Q38).
 */

beforeEach(function () {
    $this->djerba = Agency::factory()->headOffice()->create(['code' => 'DJE', 'name' => 'Djerba']);
    $this->sfax = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->sousse = Agency::factory()->create(['code' => 'SOU', 'name' => 'Sousse']);
});

function planningBlock(int $agencyId, PlanningBlockType $type = PlanningBlockType::Reservation): PlanningBlock
{
    $start = CarbonImmutable::parse('2026-12-03 09:00', 'Africa/Tunis');

    return new PlanningBlock(
        vehicleId: 7,
        agencyId: $agencyId,
        type: $type,
        startsAt: $start,
        endsAt: $start->addDays(2),
        bufferEndsAt: $start->addDays(2)->addMinutes(60),
        reference: 'RES-2026-00042',
        customerName: 'Client fictif 0042',
        late: true,
        url: '/admin/reservations/42',
    );
}

/**
 * Événements que le widget renvoie au navigateur pour la période donnée.
 *
 * @return list<array<string, mixed>>
 */
function planningPayload(string $from = '2026-11-30T00:00:00', string $to = '2027-01-04T00:00:00'): array
{
    return Livewire::test(PlanningPrototypeCalendar::class)
        ->instance()
        ->getEventsJs(['startStr' => $from, 'endStr' => $to, 'tzOffset' => 60]);
}

it('détaille un bloc de son agence et masque celui d\'une autre agence', function () {
    $agent = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sfax->id]);

    [$own, $other] = app(PlanningProjection::class)->forUser($agent, [
        planningBlock($this->sfax->id),
        planningBlock($this->sousse->id),
    ]);

    expect($own->detailed)->toBeTrue()
        ->and($own->label)->toBe('RES-2026-00042 · Client fictif 0042')
        ->and($own->url)->toBe('/admin/reservations/42')
        ->and($own->late)->toBeTrue()
        ->and($other->detailed)->toBeFalse()
        ->and($other->label)->toBe('Occupé')
        ->and($other->url)->toBeNull()
        ->and($other->late)->toBeFalse()
        ->and($other->id)->toBe('occupied-1')
        // La période et le délai de préparation restent visibles : c'est la disponibilité.
        ->and($other->startsAt->equalTo($own->startsAt))->toBeTrue()
        ->and($other->bufferEndsAt?->equalTo($own->bufferEndsAt))->toBeTrue();
});

it('détaille les blocs de toutes les agences pour le super admin et l\'admin', function (Role $role) {
    $user = userWithProfile($role, ['agency_id' => $this->djerba->id]);

    $entries = app(PlanningProjection::class)->forUser($user, [
        planningBlock($this->sfax->id),
        planningBlock($this->sousse->id),
    ]);

    expect(collect($entries)->every(fn ($entry) => $entry->detailed && $entry->label !== 'Occupé'))->toBeTrue();
})->with([Role::SuperAdmin, Role::Admin]);

it('libelle maintenance et immobilisation sans nom de client', function () {
    $admin = userWithProfile(Role::Admin, ['agency_id' => $this->djerba->id]);

    [$maintenance] = app(PlanningProjection::class)->forUser($admin, [
        planningBlock($this->sfax->id, PlanningBlockType::Maintenance),
    ]);

    expect($maintenance->label)->toBe('Maintenance');
});

it('n\'envoie au navigateur aucune donnée client d\'une autre agence', function () {
    $agent = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sfax->id]);
    $this->actingAs($agent);

    $demo = new PlanningDemoData([$this->djerba->id, $this->sfax->id, $this->sousse->id], CarbonImmutable::today());
    $sfaxVehicles = collect($demo->vehicles())->where('agency_id', $this->sfax->id)->map(fn ($v) => 'vehicle-'.$v['id'])->all();

    $events = planningPayload(now()->subWeek()->toIso8601String(), now()->addWeeks(3)->toIso8601String());
    [$own, $foreign] = collect($events)
        ->partition(fn (array $event) => in_array($event['resourceIds'][0], $sfaxVehicles, true));

    expect($own)->not->toBeEmpty()
        ->and($foreign)->not->toBeEmpty()
        ->and($own->contains(fn (array $event) => str_contains((string) $event['title'], 'Client fictif')))->toBeTrue();

    $serialized = json_encode($foreign->values()->all(), JSON_THROW_ON_ERROR);

    expect($foreign->pluck('title')->unique()->values()->all())->toBe(['Occupé'])
        ->and($serialized)->not->toContain('Client fictif')
        ->and($serialized)->not->toContain('RES-')
        ->and($serialized)->not->toContain('CTR-')
        ->and($serialized)->not->toContain('"url"')
        ->and($serialized)->not->toContain('"model"')
        ->and($foreign->every(fn (array $event) => str_contains($event['classNames'], 'planning-occupied')))->toBeTrue();
});

it('ne rend aucun bloc déplaçable ni redimensionnable (D39)', function () {
    $this->actingAs(userWithProfile(Role::SuperAdmin, ['agency_id' => $this->djerba->id]));

    $widget = Livewire::test(PlanningPrototypeCalendar::class)->instance();

    expect($widget->isEventDragEnabled())->toBeFalse()
        ->and($widget->isEventResizeEnabled())->toBeFalse();
});

it('fournit environ 300 occupations par mois sur 50 véhicules', function () {
    $demo = new PlanningDemoData([1, 2, 3, 4], CarbonImmutable::parse('2026-11-19'));
    $blocks = $demo->blocksBetween(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-12-01'));

    expect($demo->vehicles())->toHaveCount(50)
        ->and(count($blocks))->toBeGreaterThanOrEqual(250)->toBeLessThanOrEqual(400);
});

it('n\'est accessible que sur le poste et en test, jamais en recette ni en production', function () {
    $this->actingAs(userWithProfile(Role::SuperAdmin, ['agency_id' => $this->djerba->id]));

    expect(PlanningPrototype::canAccess())->toBeTrue();

    app()->detectEnvironment(fn () => 'production');
    expect(PlanningPrototype::canAccess())->toBeFalse();

    app()->detectEnvironment(fn () => 'staging');
    expect(PlanningPrototype::canAccess())->toBeFalse();

    app()->detectEnvironment(fn () => 'testing');
});
