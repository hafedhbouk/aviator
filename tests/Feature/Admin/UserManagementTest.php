<?php

use App\Domain\Administration\Actions\SaveUser;
use App\Domain\Administration\Exceptions\UserManagementException;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Agency;
use App\Models\User;
use App\Support\Authorization\Role;
use Filament\Auth\Notifications\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->superAdmin = withTwoFactor(userWithProfile(Role::SuperAdmin));
});

it('réserve la gestion des utilisateurs au super admin', function (Role $role) {
    $this->actingAs(withTwoFactor(userWithProfile($role)))
        ->get(route('filament.admin.resources.users.index'))
        ->assertForbidden();
})->with([Role::Admin, Role::AgencyUser]);

it('liste les comptes pour le super admin', function () {
    $agent = userWithProfile(Role::AgencyUser);

    $this->actingAs($this->superAdmin);

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$agent, $this->superAdmin])
        ->assertTableActionDoesNotExist('delete');
});

it('crée un utilisateur d\'agence rattaché à son agence et à une agence de remplacement, et lui envoie un lien', function () {
    Notification::fake();
    [$sfax, $sousse] = Agency::factory()->count(2)->create();

    $this->actingAs($this->superAdmin);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Agent Sfax',
            'email' => 'agent.sfax@example.test',
            'role' => Role::AgencyUser->value,
            'agency_id' => $sfax->id,
            'agency_ids' => [$sousse->id],
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::query()->where('email', 'agent.sfax@example.test')->sole();

    expect($user->hasProfile(Role::AgencyUser))->toBeTrue()
        ->and($user->agency_id)->toBe($sfax->id)
        ->and($user->agencies()->pluck('agencies.id')->sort()->values()->all())
        ->toBe(collect([$sfax->id, $sousse->id])->sort()->values()->all());

    Notification::assertSentTo($user, ResetPassword::class);
});

it('exige une agence pour un utilisateur d\'agence', function () {
    $this->actingAs($this->superAdmin);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Agent sans agence',
            'email' => 'agent@example.test',
            'role' => Role::AgencyUser->value,
        ])
        ->call('create')
        ->assertHasFormErrors(['agency_id' => 'required']);

    expect(User::query()->where('email', 'agent@example.test')->exists())->toBeFalse();
});

it('crée un admin sans agence', function () {
    Notification::fake();
    $this->actingAs($this->superAdmin);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Admin siège',
            'email' => 'admin@example.test',
            'role' => Role::Admin->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $admin = User::query()->where('email', 'admin@example.test')->sole();

    expect($admin->hasProfile(Role::Admin))->toBeTrue()
        ->and($admin->agency_id)->toBeNull()
        ->and($admin->agencies()->count())->toBe(0);
});

it('ne propose pas le profil super admin dans le formulaire', function () {
    $this->actingAs($this->superAdmin);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Faux super admin',
            'email' => 'faux@example.test',
            'role' => Role::SuperAdmin->value,
        ])
        ->call('create')
        ->assertHasFormErrors(['role']);
});

it('refuse de modifier un compte super admin depuis l\'interface', function () {
    $other = userWithProfile(Role::SuperAdmin);

    $this->actingAs($this->superAdmin)
        ->get(route('filament.admin.resources.users.edit', ['record' => $other]))
        ->assertForbidden();
});

it('désactive un utilisateur d\'agence', function () {
    $agency = Agency::factory()->create();
    $agent = userWithProfile(Role::AgencyUser, ['agency_id' => $agency->id]);
    $agent->agencies()->attach($agency);

    $this->actingAs($this->superAdmin);

    Livewire::test(EditUser::class, ['record' => $agent->getRouteKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($agent->fresh()?->is_active)->toBeFalse();
});

it('journalise un changement de profil dans le journal d\'audit', function () {
    $agency = Agency::factory()->create();
    $agent = userWithProfile(Role::AgencyUser, ['agency_id' => $agency->id]);

    app(SaveUser::class)->handle($agent, [
        'name' => $agent->name,
        'email' => $agent->email,
        'role' => Role::Admin->value,
    ], $this->superAdmin);

    expect($agent->fresh()?->hasProfile(Role::Admin))->toBeTrue()
        ->and(Activity::query()->where('log_name', 'audit')->where('event', 'role_changed')->exists())->toBeTrue();
});

it('empêche un utilisateur de se désactiver lui-même', function () {
    $admin = userWithProfile(Role::Admin);

    app(SaveUser::class)->handle($admin, [
        'name' => $admin->name,
        'email' => $admin->email,
        'role' => Role::Admin->value,
        'is_active' => false,
    ], $admin);
})->throws(UserManagementException::class, 'propre compte');

it('n\'autorise jamais la suppression d\'un compte', function () {
    $agent = userWithProfile(Role::AgencyUser);

    expect($this->superAdmin->can('delete', $agent))->toBeFalse()
        ->and($this->superAdmin->can('deleteAny', User::class))->toBeFalse();
});
