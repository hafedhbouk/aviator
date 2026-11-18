<?php

use App\Models\Agency;
use App\Models\User;
use App\Support\Agency\AgencyAccessException;
use App\Support\Agency\CurrentAgency;
use App\Support\Authorization\Role;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Spatie\Activitylog\Models\Activity;
use Tests\Fixtures\Models\AgencyDocument;
use Tests\Fixtures\Policies\AgencyDocumentPolicy;

uses(RefreshDatabase::class);

beforeEach(function () {
    Gate::policy(AgencyDocument::class, AgencyDocumentPolicy::class);

    $this->sfax = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->sousse = Agency::factory()->create(['code' => 'SOU', 'name' => 'Sousse']);
    $this->tunis = Agency::factory()->create(['code' => 'TUN', 'name' => 'Tunis']);

    // Documents créés hors requête authentifiée (aucune portée appliquée).
    $this->docSfax = AgencyDocument::query()->create(['agency_id' => $this->sfax->id, 'title' => 'Contrat Sfax']);
    $this->docSousse = AgencyDocument::query()->create(['agency_id' => $this->sousse->id, 'title' => 'Contrat Sousse']);
    $this->docTunis = AgencyDocument::query()->create(['agency_id' => $this->tunis->id, 'title' => 'Contrat Tunis']);
});

function agentOf(Agency $agency, Agency ...$replacements): User
{
    $agent = userWithProfile(Role::AgencyUser, ['agency_id' => $agency->id]);

    foreach ($replacements as $replacement) {
        $agent->agencyAssignments()->create([
            'agency_id' => $replacement->id,
            'starts_on' => now()->subDay()->toDateString(),
            'ends_on' => now()->addWeek()->toDateString(),
        ]);
    }

    return $agent;
}

/**
 * @return list<string>
 */
function visibleTitles(): array
{
    return AgencyDocument::query()->orderBy('title')->pluck('title')->all();
}

// --- Lecture ----------------------------------------------------------------

it('limite l\'utilisateur d\'agence aux documents de son agence (RG-13)', function () {
    $this->actingAs(agentOf($this->sfax));

    expect(visibleTitles())->toBe(['Contrat Sfax'])
        ->and(AgencyDocument::query()->count())->toBe(1)
        ->and(AgencyDocument::query()->find($this->docSousse->id))->toBeNull();
});

it('étend la lecture aux agences de remplacement (Q69)', function () {
    $this->actingAs(agentOf($this->sfax, $this->sousse));

    expect(visibleTitles())->toBe(['Contrat Sfax', 'Contrat Sousse']);
});

it('laisse le super admin et l\'admin voir toutes les agences', function (Role $role) {
    $this->actingAs(userWithProfile($role));

    expect(visibleTitles())->toBe(['Contrat Sfax', 'Contrat Sousse', 'Contrat Tunis']);
})->with([Role::SuperAdmin, Role::Admin]);

it('ne montre rien à un utilisateur d\'agence sans agence', function () {
    $this->actingAs(userWithProfile(Role::AgencyUser));

    expect(visibleTitles())->toBe([]);
});

it('ne limite pas les traitements sans utilisateur connecté (commandes, file)', function () {
    Auth::logout();

    expect(visibleTitles())->toHaveCount(3);
});

// --- Accès direct par l'adresse de la page ----------------------------------

it('renvoie 404 sur le document d\'une autre agence ouvert par son adresse', function () {
    Route::middleware('web')->get('/_test/documents/{document}', fn (AgencyDocument $document) => $document->title);

    $this->actingAs(agentOf($this->sfax));

    $this->get('/_test/documents/'.$this->docSfax->id)->assertOk()->assertSee('Contrat Sfax');
    $this->get('/_test/documents/'.$this->docSousse->id)->assertNotFound();
});

// --- Policies, même si le scope est contourné -------------------------------

it('refuse la modification d\'un document d\'une autre agence même sans le scope', function () {
    $agent = agentOf($this->sfax);
    $this->actingAs($agent);

    $foreign = AgencyDocument::query()->withoutGlobalScopes()->findOrFail($this->docSousse->id);

    expect(Gate::forUser($agent)->allows('update', $foreign))->toBeFalse()
        ->and(Gate::forUser($agent)->allows('update', $this->docSfax))->toBeTrue();
});

it('autorise l\'admin sur toutes les agences dans les policies', function () {
    $admin = userWithProfile(Role::Admin);

    expect(Gate::forUser($admin)->allows('update', $this->docTunis))->toBeTrue();
});

// --- Écriture ---------------------------------------------------------------

it('rattache un nouveau document à l\'agence active de l\'utilisateur', function () {
    $this->actingAs(agentOf($this->sfax));

    $document = AgencyDocument::query()->create(['title' => 'Nouveau contrat']);

    expect($document->agency_id)->toBe($this->sfax->id);
});

it('refuse la création d\'un document pour une autre agence, même avec un formulaire falsifié', function () {
    $this->actingAs(agentOf($this->sfax));

    AgencyDocument::query()->create(['agency_id' => $this->tunis->id, 'title' => 'Falsifié']);
})->throws(AgencyAccessException::class);

it('refuse de modifier un document d\'une autre agence chargé sans le scope', function () {
    $this->actingAs(agentOf($this->sfax));

    $foreign = AgencyDocument::query()->withoutGlobalScopes()->findOrFail($this->docSousse->id);
    $foreign->update(['title' => 'Modifié']);
})->throws(AgencyAccessException::class);

it('refuse de déplacer un document vers une agence non autorisée', function () {
    $this->actingAs(agentOf($this->sfax));

    $this->docSfax->update(['agency_id' => $this->tunis->id]);
})->throws(AgencyAccessException::class);

it('laisse l\'admin créer un document pour l\'agence de son choix', function () {
    $this->actingAs(userWithProfile(Role::Admin));

    $document = AgencyDocument::query()->create(['agency_id' => $this->tunis->id, 'title' => 'Contrat siège']);

    expect($document->agency_id)->toBe($this->tunis->id);
});

it('refuse la création à un utilisateur d\'agence sans agence', function () {
    $this->actingAs(userWithProfile(Role::AgencyUser));

    AgencyDocument::query()->create(['title' => 'Sans agence']);
})->throws(AgencyAccessException::class, 'Aucune agence');

// --- Agence active (remplacement, Q69) --------------------------------------

it('change l\'agence active vers une agence de remplacement et l\'utilise pour les créations', function () {
    $agent = agentOf($this->sfax, $this->sousse);
    $this->actingAs($agent);

    app(CurrentAgency::class)->switchTo($agent, $this->sousse->id);
    $document = AgencyDocument::query()->create(['title' => 'Remplacement à Sousse']);

    expect(app(CurrentAgency::class)->id($agent))->toBe($this->sousse->id)
        ->and($document->agency_id)->toBe($this->sousse->id)
        ->and(Activity::query()->where('event', 'current_agency_switched')->exists())->toBeTrue();
});

it('refuse de changer l\'agence active vers une agence non autorisée', function () {
    $agent = agentOf($this->sfax);
    $this->actingAs($agent);

    expect(fn () => app(CurrentAgency::class)->switchTo($agent, $this->tunis->id))->toThrow(AgencyAccessException::class)
        ->and(app(CurrentAgency::class)->id($agent))->toBe($this->sfax->id);
});

it('revient à l\'agence par défaut si l\'agence choisie n\'est plus autorisée', function () {
    $agent = agentOf($this->sfax, $this->sousse);
    $this->actingAs($agent);
    app(CurrentAgency::class)->switchTo($agent, $this->sousse->id);

    $agent->agencyAssignments()->where('agency_id', $this->sousse->id)->update(['ends_on' => now()->subDay()->toDateString()]);

    expect(app(CurrentAgency::class)->id($agent))->toBe($this->sfax->id);
});

it('n\'attribue pas d\'agence active au super admin ni à l\'admin', function (Role $role) {
    expect(app(CurrentAgency::class)->id(userWithProfile($role)))->toBeNull();
})->with([Role::SuperAdmin, Role::Admin]);

it('renvoie une erreur 403 quand une requête dépasse la portée de son agence', function () {
    Route::middleware('web')->post('/_test/documents', fn () => AgencyDocument::query()->create([
        'agency_id' => request()->integer('agency_id'),
        'title' => 'Falsifié',
    ]));

    $this->actingAs(agentOf($this->sfax));

    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/_test/documents', ['agency_id' => $this->tunis->id])
        ->assertForbidden();
});
