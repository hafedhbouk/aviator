<?php

use App\Models\User;
use App\Support\Authorization\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\PendingCommand;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

const SUPER_ADMIN_PASSWORD = 'Location#Djerba-2026';

function createSuperAdmin(string $name, string $email, string $password, ?string $confirmation = null): PendingCommand
{
    return test()->artisan('aviator:create-super-admin')
        ->expectsQuestion('Nom complet', $name)
        ->expectsQuestion('Adresse e-mail', $email)
        ->expectsQuestion('Mot de passe (12 caractères minimum, majuscules, minuscules, chiffres et symboles)', $password)
        ->expectsQuestion('Confirmez le mot de passe', $confirmation ?? $password);
}

it('crée un super admin actif, sans agence, avec le profil super_admin', function () {
    createSuperAdmin('Direction Aviator', 'Direction@Example.test', SUPER_ADMIN_PASSWORD)
        ->doesntExpectOutputToContain(SUPER_ADMIN_PASSWORD)
        ->assertSuccessful();

    $user = User::query()->where('email', 'direction@example.test')->sole();

    expect($user->hasProfile(Role::SuperAdmin))->toBeTrue()
        ->and($user->is_active)->toBeTrue()
        ->and($user->agency_id)->toBeNull()
        ->and(Hash::check(SUPER_ADMIN_PASSWORD, $user->password))->toBeTrue()
        ->and($user->getAppAuthenticationSecret())->toBeNull();
});

it('journalise la création dans le journal de sécurité', function () {
    createSuperAdmin('Direction Aviator', 'direction@example.test', SUPER_ADMIN_PASSWORD)->assertSuccessful();

    expect(Activity::query()->where('log_name', 'security')->where('event', 'super_admin_created')->count())->toBe(1);
});

it('accepte un second super admin (compte de secours) mais refuse un troisième', function () {
    createSuperAdmin('Direction Aviator', 'direction@example.test', SUPER_ADMIN_PASSWORD)->assertSuccessful();
    createSuperAdmin('Compte de secours', 'secours@example.test', 'Agence!Tunis-2026')->assertSuccessful();

    $this->artisan('aviator:create-super-admin')
        ->expectsOutputToContain('Nombre maximal de super admins atteint (2)')
        ->assertFailed();

    expect(User::role(Role::SuperAdmin->value)->count())->toBe(2);
});

it('respecte la limite paramétrée (aviator.max_super_admins)', function () {
    config(['aviator.max_super_admins' => 1]);
    createSuperAdmin('Direction Aviator', 'direction@example.test', SUPER_ADMIN_PASSWORD)->assertSuccessful();

    $this->artisan('aviator:create-super-admin')->assertFailed();
});

it('refuse une saisie invalide sans créer de compte', function (string $name, string $email, string $password, ?string $confirmation) {
    createSuperAdmin($name, $email, $password, $confirmation)->assertFailed();

    expect(User::query()->count())->toBe(0);
})->with([
    'e-mail invalide' => ['Direction Aviator', 'pas-un-email', SUPER_ADMIN_PASSWORD, null],
    'mot de passe trop court' => ['Direction Aviator', 'direction@example.test', 'Court#1a', null],
    'sans symbole' => ['Direction Aviator', 'direction@example.test', 'LocationDjerba2026', null],
    'sans majuscule' => ['Direction Aviator', 'direction@example.test', 'location#djerba-2026', null],
    'confirmation différente' => ['Direction Aviator', 'direction@example.test', SUPER_ADMIN_PASSWORD, 'Autre#Mot-de-passe-1'],
    'contient le nom' => ['Hédi Mansour', 'gerance@example.test', 'Mansour#2026-Djerba', null],
    'contient l\'e-mail' => ['Direction Aviator', 'gerant@example.test', 'Gerant#2026-Djerba', null],
]);

it('refuse une adresse e-mail déjà utilisée', function () {
    User::factory()->create(['email' => 'direction@example.test']);

    createSuperAdmin('Direction Aviator', 'direction@example.test', SUPER_ADMIN_PASSWORD)->assertFailed();

    expect(User::role(Role::SuperAdmin->value)->count())->toBe(0);
});
