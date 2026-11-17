<?php

use App\Models\User;
use App\Support\Authorization\Role;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function setUpUrl(): string
{
    return (string) Filament::getPanel('admin')->getSetUpRequiredMultiFactorAuthenticationUrl();
}

it('impose l\'activation de la 2FA au super admin et à l\'admin (J-03)', function (Role $role) {
    $this->actingAs(userWithProfile($role))
        ->get('/admin')
        ->assertRedirect(setUpUrl());
})->with([Role::SuperAdmin, Role::Admin]);

it('laisse entrer le super admin et l\'admin une fois la 2FA activée', function (Role $role) {
    $this->actingAs(withTwoFactor(userWithProfile($role)))
        ->get('/admin')
        ->assertOk();
})->with([Role::SuperAdmin, Role::Admin]);

it('n\'impose pas la 2FA à l\'utilisateur d\'agence', function () {
    $this->actingAs(userWithProfile(Role::AgencyUser))
        ->get('/admin')
        ->assertOk();
});

it('refuse un compte désactivé, même avec la 2FA', function () {
    $user = withTwoFactor(userWithProfile(Role::Admin, ['is_active' => false]));

    $this->actingAs($user)->get('/admin')->assertForbidden();
});

it('refuse un compte sans profil', function () {
    $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
});

it('redirige un visiteur non connecté vers la page de connexion', function () {
    $this->get('/admin')->assertRedirect((string) Filament::getPanel('admin')->getLoginUrl());
});

it('enregistre la date de dernière connexion', function () {
    $user = userWithProfile(Role::AgencyUser);

    event(new Login('web', $user, false));

    expect($user->fresh()?->last_login_at)->not->toBeNull();
});

it('affiche la page de connexion en français', function () {
    $this->get((string) Filament::getPanel('admin')->getLoginUrl())
        ->assertOk()
        ->assertSee('Connexion');
});
