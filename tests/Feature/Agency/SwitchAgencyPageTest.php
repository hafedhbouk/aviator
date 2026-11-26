<?php

use App\Filament\Pages\SwitchAgency;
use App\Filament\Resources\Reservations\Pages\CreateReservation;
use App\Models\Agency;
use App\Models\User;
use App\Support\Agency\CurrentAgency;
use App\Support\Authorization\Role;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/*
 * Sélecteur d'agence active (Q69, report de la phase 2, lot L13) : menu utilisateur,
 * page de choix, agence par défaut des nouvelles réservations.
 */

beforeEach(function () {
    $this->sfax = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->sousse = Agency::factory()->create(['code' => 'SOU', 'name' => 'Sousse']);
    $this->tunis = Agency::factory()->create(['code' => 'TUN', 'name' => 'Tunis']);
});

function replacingAgent(Agency $home, Agency ...$replacements): User
{
    $agent = userWithProfile(Role::AgencyUser, ['agency_id' => $home->id]);

    foreach ($replacements as $replacement) {
        $agent->agencyAssignments()->create([
            'agency_id' => $replacement->id,
            'starts_on' => now()->subDay()->toDateString(),
            'ends_on' => now()->addWeek()->toDateString(),
        ]);
    }

    return $agent;
}

it('montre l\'agence active dans le menu d\'un agent qui remplace un collègue', function () {
    $this->actingAs(replacingAgent($this->sfax, $this->sousse));

    $this->get(Dashboard::getUrl())->assertOk()
        ->assertSee('Agence active : Sfax')
        ->assertSee(SwitchAgency::getUrl());
});

it('change l\'agence active depuis la page et l\'applique à l\'assistant de réservation', function () {
    $agent = replacingAgent($this->sfax, $this->sousse);
    $this->actingAs($agent);

    Livewire::test(SwitchAgency::class)
        ->assertSchemaStateSet(['agency_id' => $this->sfax->id])
        ->set('data.agency_id', $this->sousse->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertNotified('Agence active : Sousse')
        ->assertRedirect(Dashboard::getUrl());

    expect(app(CurrentAgency::class)->id($agent))->toBe($this->sousse->id)
        ->and(Activity::query()->where('event', 'current_agency_switched')->where('causer_id', $agent->id)->exists())->toBeTrue();

    Livewire::test(CreateReservation::class)->assertSchemaStateSet(['agency_id' => $this->sousse->id]);
    $this->get(Dashboard::getUrl())->assertSee('Agence active : Sousse');
});

it('ne propose que les agences actives où l\'agent peut agir aujourd\'hui', function () {
    $this->tunis->forceFill(['is_active' => false])->save();
    $agent = replacingAgent($this->sfax, $this->sousse, $this->tunis);

    expect(SwitchAgency::choices($agent))->toBe([$this->sfax->id => 'Sfax', $this->sousse->id => 'Sousse']);
});

it('refuse une agence falsifiée et garde l\'agence active', function () {
    $agent = replacingAgent($this->sfax, $this->sousse);
    $this->actingAs($agent);

    Livewire::test(SwitchAgency::class)
        ->set('data.agency_id', $this->tunis->id)
        ->call('save')
        ->assertHasErrors(['data.agency_id']);

    expect(app(CurrentAgency::class)->id($agent))->toBe($this->sfax->id)
        ->and(Activity::query()->where('event', 'current_agency_switched')->exists())->toBeFalse();
});

it('masque le sélecteur quand il n\'y a pas de choix', function (string $who) {
    $user = match ($who) {
        'agent sans remplacement' => replacingAgent($this->sfax),
        'admin' => withTwoFactor(userWithProfile(Role::Admin)),
        'super admin' => withTwoFactor(userWithProfile(Role::SuperAdmin)),
    };
    $this->actingAs($user);

    $this->get(Dashboard::getUrl())->assertOk()->assertDontSee('Agence active');
    $this->get(SwitchAgency::getUrl())->assertForbidden();
})->with(['agent sans remplacement', 'admin', 'super admin']);
