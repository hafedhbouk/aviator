<?php

use App\Domain\Fleet\Actions\RetireVehicle;
use App\Domain\Fleet\Actions\SaveVehicleDocument;
use App\Domain\Fleet\DepartureGuard;
use App\Domain\Fleet\Exceptions\FleetException;
use App\Domain\Fleet\Exceptions\IllegalVehicleTransition;
use App\Domain\Fleet\Exceptions\VehicleNotRentable;
use App\Domain\Fleet\StatusConsistencyCheck;
use App\Domain\Fleet\VehicleStatusService;
use App\Enums\RetirementReason;
use App\Enums\VehicleDocumentType;
use App\Enums\VehicleStatus;
use App\Filament\Resources\Vehicles\Pages\ViewVehicle;
use App\Models\Agency;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\VehicleDocument;
use App\Models\VehicleStatusLog;
use App\Support\Authorization\Role;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * Machine à états du véhicule (B-09, lot L5) : transitions autorisées, journal en
 * ajout seul, documents obligatoires au départ (scénario 10), statut dérivé des
 * documents, sortie du parc (B-10).
 */

beforeEach(function () {
    $this->admin = withTwoFactor(userWithProfile(Role::Admin));
    $this->sfax = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->agent = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sfax->id]);
    $this->vehicle = Vehicle::factory()->create([
        'agency_id' => $this->sfax->id,
        'vehicle_category_id' => VehicleCategory::factory()->create()->id,
    ])->refresh();
    $this->statuses = app(VehicleStatusService::class);
});

/**
 * Met un véhicule dans un statut de départ pour l'essai (hors du service, sans événement).
 */
function inStatus(Vehicle $vehicle, VehicleStatus $status): Vehicle
{
    $vehicle->forceFill(['status' => $status])->saveQuietly();

    return $vehicle->refresh();
}

function withDocuments(Vehicle $vehicle, string $until = '2027-12-31', array $types = ['insurance', 'technical_inspection']): void
{
    foreach ($types as $type) {
        VehicleDocument::query()->create(['vehicle_id' => $vehicle->id, 'type' => $type, 'valid_from' => '2026-01-01', 'valid_until' => $until]);
    }
}

// --- Transitions ----------------------------------------------------------------

/** Machine à états de l'architecture §7 : cahier §5.1 et transitions de Q29. */
const ALLOWED = [
    'available' => ['reserved', 'rented', 'maintenance', 'unavailable', 'retired'],
    'reserved' => ['available', 'rented', 'maintenance', 'unavailable'],
    'rented' => ['available', 'maintenance', 'unavailable'],
    'maintenance' => ['available', 'unavailable', 'retired'],
    'unavailable' => ['available', 'maintenance', 'retired'],
    'retired' => [],
];

it('applique exactement la table des transitions', function () {
    foreach (VehicleStatus::cases() as $from) {
        foreach (VehicleStatus::cases() as $to) {
            if ($from === $to) {
                continue;
            }

            expect(VehicleStatusService::allows($from, $to))
                ->toBe(in_array($to->value, ALLOWED[$from->value], true), "{$from->value} → {$to->value}");
        }
    }
});

it('refuse une transition absente de la table, sans rien écrire', function (VehicleStatus $from, VehicleStatus $to) {
    inStatus($this->vehicle, $from);

    expect(fn () => $this->statuses->transition($this->vehicle, $to, actor: $this->admin))
        ->toThrow(IllegalVehicleTransition::class);

    expect($this->vehicle->fresh()?->status)->toBe($from)
        ->and(VehicleStatusLog::query()->count())->toBe(0);
})->with([
    'sorti → disponible (définitif)' => [VehicleStatus::Retired, VehicleStatus::Available],
    'loué → réservé' => [VehicleStatus::Rented, VehicleStatus::Reserved],
    'loué → sorti' => [VehicleStatus::Rented, VehicleStatus::Retired],
    'maintenance → loué' => [VehicleStatus::Maintenance, VehicleStatus::Rented],
    'réservé → sorti' => [VehicleStatus::Reserved, VehicleStatus::Retired],
]);

it('journalise chaque transition : avant, après, cause, motif, auteur', function () {
    inStatus($this->vehicle, VehicleStatus::Available);

    $this->statuses->transition($this->vehicle, VehicleStatus::Maintenance, cause: $this->sfax, actor: $this->admin, reason: 'Vidange');

    $log = VehicleStatusLog::query()->sole();

    expect($this->vehicle->status)->toBe(VehicleStatus::Maintenance)
        ->and($log->from_status)->toBe(VehicleStatus::Available)
        ->and($log->to_status)->toBe(VehicleStatus::Maintenance)
        ->and($log->cause_type)->toBe($this->sfax->getMorphClass())
        ->and($log->cause_id)->toBe($this->sfax->id)
        ->and($log->reason)->toBe('Vidange')
        ->and($log->user_id)->toBe($this->admin->id);
});

it('ne journalise rien quand le statut ne change pas', function () {
    inStatus($this->vehicle, VehicleStatus::Maintenance);

    $this->statuses->transition($this->vehicle, VehicleStatus::Maintenance);

    expect(VehicleStatusLog::query()->count())->toBe(0);
});

it('garde l\'historique en ajout seul', function () {
    inStatus($this->vehicle, VehicleStatus::Available);
    $this->statuses->transition($this->vehicle, VehicleStatus::Maintenance);
    $log = VehicleStatusLog::query()->sole();

    expect(fn () => $log->update(['reason' => 'modifié']))->toThrow(LogicException::class)
        ->and(fn () => $log->delete())->toThrow(LogicException::class);
});

it('refuse toute écriture du statut hors du service', function () {
    expect(fn () => $this->vehicle->forceFill(['status' => VehicleStatus::Retired])->save())
        ->toThrow(LogicException::class, 'VehicleStatusService');

    expect($this->vehicle->fresh()?->status)->not->toBe(VehicleStatus::Retired);
});

// --- Scénario 10 : documents obligatoires au départ ------------------------------

it('refuse le départ d\'un véhicule à l\'assurance expirée (scénario 10)', function () {
    withDocuments($this->vehicle, '2027-12-31', ['technical_inspection']);
    VehicleDocument::query()->create(['vehicle_id' => $this->vehicle->id, 'type' => 'insurance', 'valid_from' => '2025-01-01', 'valid_until' => '2026-11-18']);
    inStatus($this->vehicle, VehicleStatus::Available);

    expect(fn () => $this->statuses->transition($this->vehicle, VehicleStatus::Rented, actor: $this->agent,
        departureAt: CarbonImmutable::parse('2026-11-19 09:00', 'Africa/Tunis')))
        ->toThrow(VehicleNotRentable::class, 'assurance non valide');

    expect($this->vehicle->fresh()?->status)->toBe(VehicleStatus::Available);
});

it('refuse le départ sans visite technique valide, et nomme les deux documents manquants', function () {
    inStatus($this->vehicle, VehicleStatus::Reserved);

    expect(fn () => $this->statuses->transition($this->vehicle, VehicleStatus::Rented, departureAt: CarbonImmutable::parse('2026-11-19 09:00')))
        ->toThrow(VehicleNotRentable::class, 'assurance et visite technique non valides');
});

it('accepte le départ le dernier jour de validité, le refuse le lendemain', function () {
    withDocuments($this->vehicle, '2026-11-30');
    inStatus($this->vehicle, VehicleStatus::Available);

    $this->statuses->transition($this->vehicle, VehicleStatus::Rented, departureAt: CarbonImmutable::parse('2026-11-30 23:30', 'Africa/Tunis'));
    expect($this->vehicle->status)->toBe(VehicleStatus::Rented);

    inStatus($this->vehicle, VehicleStatus::Available);
    expect(fn () => $this->statuses->transition($this->vehicle, VehicleStatus::Rented,
        departureAt: CarbonImmutable::parse('2026-12-01 00:10', 'Africa/Tunis')))
        ->toThrow(VehicleNotRentable::class);
});

it('juge la validité au jour de Tunis, même pour une heure donnée en UTC', function () {
    withDocuments($this->vehicle, '2026-11-30');

    // 30/11 à 23:30 UTC = 01/12 à 00:30 à Tunis : le document a expiré.
    expect(app(DepartureGuard::class)->missingOn($this->vehicle, CarbonImmutable::parse('2026-11-30 23:30', 'UTC')->timezone('Africa/Tunis')))
        ->toBe(DepartureGuard::required());
    expect(fn () => app(DepartureGuard::class)->assertCanDepart($this->vehicle, CarbonImmutable::parse('2026-11-30 23:30', 'UTC')))
        ->toThrow(VehicleNotRentable::class);
});

it('signale un document qui expire pendant la location, sans refuser le départ', function () {
    withDocuments($this->vehicle, '2026-12-05');

    $expiring = app(DepartureGuard::class)->expiringDuring($this->vehicle,
        CarbonImmutable::parse('2026-12-01 10:00'), CarbonImmutable::parse('2026-12-10 10:00'));

    expect($expiring)->toBe([VehicleDocumentType::Insurance, VehicleDocumentType::TechnicalInspection]);
    app(DepartureGuard::class)->assertCanDepart($this->vehicle, CarbonImmutable::parse('2026-12-01 10:00'));
});

// --- Statut dérivé des documents --------------------------------------------------

it('rend indisponible un véhicule sans documents valides, puis disponible quand ils sont saisis', function () {
    inStatus($this->vehicle, VehicleStatus::Available);

    $report = app(StatusConsistencyCheck::class)->run(CarbonImmutable::parse('2026-11-19'));
    expect($report)->toBe(['checked' => 1, 'unavailable' => 1, 'available' => 0])
        ->and($this->vehicle->fresh()?->status)->toBe(VehicleStatus::Unavailable);

    $save = app(SaveVehicleDocument::class);
    $save->handle($this->vehicle->fresh(), null, ['type' => 'insurance', 'valid_until' => '2099-12-31'], $this->admin);
    expect($this->vehicle->fresh()?->status)->toBe(VehicleStatus::Unavailable);

    $save->handle($this->vehicle->fresh(), null, ['type' => 'technical_inspection', 'valid_until' => '2099-12-31'], $this->admin);
    expect($this->vehicle->fresh()?->status)->toBe(VehicleStatus::Available);
});

it('est idempotent : une seconde exécution ne change rien', function () {
    inStatus($this->vehicle, VehicleStatus::Available);
    app(StatusConsistencyCheck::class)->run();
    $logs = VehicleStatusLog::query()->count();

    expect(app(StatusConsistencyCheck::class)->run())->toBe(['checked' => 1, 'unavailable' => 0, 'available' => 0])
        ->and(VehicleStatusLog::query()->count())->toBe($logs);
});

it('ne remet jamais en service un véhicule indisponible pour une autre raison', function () {
    withDocuments($this->vehicle);
    inStatus($this->vehicle, VehicleStatus::Available);
    $this->statuses->transition($this->vehicle, VehicleStatus::Unavailable, reason: 'Sinistre');

    app(StatusConsistencyCheck::class)->run(CarbonImmutable::parse('2026-11-19'));

    expect($this->vehicle->fresh()?->status)->toBe(VehicleStatus::Unavailable);
});

it('ne touche ni un véhicule loué ni un véhicule sorti', function (VehicleStatus $status) {
    inStatus($this->vehicle, $status);

    app(StatusConsistencyCheck::class)->run();

    expect($this->vehicle->fresh()?->status)->toBe($status);
})->with([VehicleStatus::Rented, VehicleStatus::Retired]);

it('planifie le contrôle chaque jour à 06:00 et l\'expose en commande', function () {
    $event = collect(Schedule::events())->first(fn ($event) => $event->description === 'fleet-check-status');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 6 * * *');

    $this->artisan('fleet:check-status')->assertSuccessful();
});

// --- Sortie du parc (B-10) ----------------------------------------------------------

it('sort un véhicule du parc avec date, motif et prix de cession', function () {
    inStatus($this->vehicle, VehicleStatus::Available);

    $retired = app(RetireVehicle::class)->handle($this->vehicle, [
        'retired_on' => '2026-11-15', 'retirement_reason' => 'sale', 'disposal_amount' => '18 500,000',
    ], $this->admin);

    expect($retired->status)->toBe(VehicleStatus::Retired)
        ->and($retired->retired_on?->toDateString())->toBe('2026-11-15')
        ->and($retired->retirement_reason)->toBe(RetirementReason::Sale->value)
        ->and((string) $retired->fresh()?->disposal_amount)->toBe('18500.000')
        ->and(VehicleStatusLog::query()->latest('id')->first()?->reason)->toBe('Vente');
});

it('refuse la sortie d\'un véhicule loué, une date future, ou par un agent', function () {
    inStatus($this->vehicle, VehicleStatus::Rented);
    expect(fn () => app(RetireVehicle::class)->handle($this->vehicle, ['retired_on' => '2026-11-15', 'retirement_reason' => 'scrap'], $this->admin))
        ->toThrow(IllegalVehicleTransition::class);

    inStatus($this->vehicle, VehicleStatus::Available);
    expect(fn () => app(RetireVehicle::class)->handle($this->vehicle, ['retired_on' => '2099-01-01', 'retirement_reason' => 'scrap'], $this->admin))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(RetireVehicle::class)->handle($this->vehicle, ['retired_on' => '2026-11-15', 'retirement_reason' => 'scrap'], $this->agent))
        ->toThrow(FleetException::class);

    expect($this->vehicle->fresh()?->status)->toBe(VehicleStatus::Available);
});

it('propose la sortie du parc à l\'admin, pas à l\'agent', function () {
    inStatus($this->vehicle, VehicleStatus::Available);

    $this->actingAs($this->admin);
    Livewire::test(ViewVehicle::class, ['record' => $this->vehicle->getRouteKey()])
        ->assertActionVisible('retire')
        ->callAction('retire', ['retired_on' => '2026-11-15', 'retirement_reason' => 'scrap'])
        ->assertHasNoActionErrors();
    expect($this->vehicle->fresh()?->status)->toBe(VehicleStatus::Retired);

    $other = inStatus(Vehicle::factory()->create(['agency_id' => $this->sfax->id, 'vehicle_category_id' => $this->vehicle->vehicle_category_id]), VehicleStatus::Available);
    $this->actingAs($this->agent);
    Livewire::test(ViewVehicle::class, ['record' => $other->getRouteKey()])
        ->assertActionHidden('retire');
});
