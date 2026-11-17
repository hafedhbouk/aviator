<?php

use App\Domain\Administration\Actions\AssignReplacementAgency;
use App\Domain\Administration\Actions\RevokeReplacementAgency;
use App\Domain\Administration\Exceptions\AgencyAssignmentException;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\RelationManagers\AgencyAssignmentsRelationManager;
use App\Models\Agency;
use App\Models\AgencyAssignment;
use App\Models\User;
use App\Support\Authorization\Role;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Mercredi 18/11/2026, 10:00 à Tunis.
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00', 'Africa/Tunis'));

    $this->sfax = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->sousse = Agency::factory()->create(['code' => 'SOU', 'name' => 'Sousse']);
    $this->superAdmin = withTwoFactor(userWithProfile(Role::SuperAdmin));
    $this->agent = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sfax->id]);
});

function assignToSousse(string $from, string $to): AgencyAssignment
{
    return app(AssignReplacementAgency::class)->handle(test()->superAdmin, test()->agent, test()->sousse->id, $from, $to);
}

function agentCanActOnSousse(): bool
{
    return test()->agent->fresh()->canActOnAgency(test()->sousse->id);
}

// --- Avant, pendant et après la période --------------------------------------

it('ne donne aucun accès avant la date de début', function () {
    assignToSousse('2026-11-20', '2026-11-25');

    expect(agentCanActOnSousse())->toBeFalse()
        ->and($this->agent->fresh()->authorizedAgencyIds())->toBe([$this->sfax->id]);
});

it('donne accès pendant la période, bornes incluses', function (string $now) {
    assignToSousse('2026-11-18', '2026-11-20');
    $this->travelTo(CarbonImmutable::parse($now, 'Africa/Tunis'));

    expect(agentCanActOnSousse())->toBeTrue();
})->with([
    'premier jour, 00:00' => ['2026-11-18 00:00:00'],
    'milieu de période' => ['2026-11-19 12:00:00'],
    'dernier jour, 23:59' => ['2026-11-20 23:59:59'],
]);

it('retire l\'accès dès le lendemain de la date de fin', function () {
    assignToSousse('2026-11-18', '2026-11-20');
    $this->travelTo(CarbonImmutable::parse('2026-11-21 00:00:00', 'Africa/Tunis'));

    expect(agentCanActOnSousse())->toBeFalse();
});

it('apprécie les dates dans le fuseau Africa/Tunis, pas en UTC', function () {
    assignToSousse('2026-11-18', '2026-11-20');

    // 20/11 à 23:30 UTC = 21/11 à 00:30 à Tunis : la période est terminée.
    $this->travelTo(CarbonImmutable::parse('2026-11-20 23:30:00', 'UTC'));
    expect(agentCanActOnSousse())->toBeFalse();

    // 20/11 à 22:59 UTC = 20/11 à 23:59 à Tunis : encore dans la période.
    $this->travelTo(CarbonImmutable::parse('2026-11-20 22:59:00', 'UTC'));
    expect(agentCanActOnSousse())->toBeTrue();
});

// --- Règles de création -----------------------------------------------------

it('journalise chaque affectation dans le journal d\'audit', function () {
    assignToSousse('2026-11-18', '2026-11-20');

    $activity = Activity::query()->where('event', 'agency_assignment_created')->sole();

    expect($activity->log_name)->toBe('audit')
        ->and($activity->causer?->is($this->superAdmin))->toBeTrue()
        ->and($activity->subject?->is($this->agent))->toBeTrue()
        ->and($activity->properties['agency'])->toBe('SOU')
        ->and($activity->properties['starts_on'])->toBe('2026-11-18')
        ->and($activity->properties['ends_on'])->toBe('2026-11-20');
});

it('réserve la création d\'une affectation au super admin', function (Role $role) {
    app(AssignReplacementAgency::class)->handle(userWithProfile($role), $this->agent, $this->sousse->id, '2026-11-18', '2026-11-20');
})->with([Role::Admin, Role::AgencyUser])->throws(AuthorizationException::class);

it('refuse une affectation invalide', function (Closure $call, string $message) {
    expect($call)->toThrow(AgencyAssignmentException::class, $message);
})->with([
    'fin avant le début' => [fn () => assignToSousse('2026-11-20', '2026-11-18'), 'postérieure ou égale'],
    'période déjà terminée' => [fn () => assignToSousse('2026-11-01', '2026-11-17'), 'déjà terminée'],
    'agence par défaut' => [fn () => app(AssignReplacementAgency::class)->handle(test()->superAdmin, test()->agent, test()->sfax->id, '2026-11-18', '2026-11-20'), 'agence par défaut'],
    'chevauchement' => [function () {
        assignToSousse('2026-11-18', '2026-11-22');
        assignToSousse('2026-11-21', '2026-11-25');
    }, 'existe déjà'],
]);

it('refuse d\'affecter un admin, qui agit déjà sur toutes les agences', function () {
    app(AssignReplacementAgency::class)->handle($this->superAdmin, userWithProfile(Role::Admin), $this->sousse->id, '2026-11-18', '2026-11-20');
})->throws(AgencyAssignmentException::class, 'utilisateur d\'agence');

it('accepte deux périodes successives dans la même agence', function () {
    assignToSousse('2026-11-18', '2026-11-20');
    assignToSousse('2026-11-21', '2026-11-25');

    expect($this->agent->agencyAssignments()->count())->toBe(2);
});

// --- Révocation -------------------------------------------------------------

it('supprime une affectation qui n\'a pas encore commencé', function () {
    $assignment = assignToSousse('2026-11-20', '2026-11-25');

    app(RevokeReplacementAgency::class)->handle($this->superAdmin, $assignment);

    expect(AgencyAssignment::query()->find($assignment->id))->toBeNull()
        ->and(Activity::query()->where('event', 'agency_assignment_revoked')->value('properties')['outcome'])->toBe('deleted');
});

it('clôt à la veille une affectation en cours, et l\'accès cesse aussitôt', function () {
    $assignment = assignToSousse('2026-11-18', '2026-11-25');
    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00', 'Africa/Tunis'));
    expect(agentCanActOnSousse())->toBeTrue();

    app(RevokeReplacementAgency::class)->handle($this->superAdmin, $assignment);

    expect($assignment->fresh()?->ends_on->toDateString())->toBe('2026-11-19')
        ->and(agentCanActOnSousse())->toBeFalse();
});

it('ne modifie pas une affectation déjà terminée', function () {
    $assignment = assignToSousse('2026-11-18', '2026-11-19');
    $this->travelTo(CarbonImmutable::parse('2026-11-22 09:00:00', 'Africa/Tunis'));

    app(RevokeReplacementAgency::class)->handle($this->superAdmin, $assignment);
})->throws(AgencyAssignmentException::class, 'déjà terminée');

it('réserve la révocation au super admin', function () {
    $assignment = assignToSousse('2026-11-18', '2026-11-25');

    app(RevokeReplacementAgency::class)->handle(userWithProfile(Role::Admin), $assignment);
})->throws(AuthorizationException::class);

// --- Interface (fiche de l'utilisateur) -------------------------------------

it('permet au super admin d\'affecter un agent en remplacement depuis sa fiche', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->superAdmin);

    Livewire::test(AgencyAssignmentsRelationManager::class, ['ownerRecord' => $this->agent, 'pageClass' => EditUser::class])
        ->callAction(TestAction::make('create')->table(), [
            'agency_id' => $this->sousse->id,
            'starts_on' => '2026-11-18',
            'ends_on' => '2026-11-20',
        ])
        ->assertHasNoFormErrors();

    expect(agentCanActOnSousse())->toBeTrue()
        ->and(Activity::query()->where('event', 'agency_assignment_created')->exists())->toBeTrue();
});

it('n\'ouvre pas la fiche des utilisateurs à l\'admin', function () {
    $this->actingAs(withTwoFactor(userWithProfile(Role::Admin)))
        ->get(route('filament.admin.resources.users.edit', ['record' => $this->agent]))
        ->assertForbidden();
});

it('ne garde aucun accès à une agence de remplacement pour un compte sans affectation', function () {
    expect(User::query()->findOrFail($this->agent->id)->authorizedAgencyIds())->toBe([$this->sfax->id]);
});
