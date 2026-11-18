<?php

use App\Filament\Auth\EditProfile;
use App\Filament\Auth\Login;
use App\Filament\Auth\ResetPassword;
use App\Models\User;
use App\Support\Authorization\Role;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
 * Parcours réels d'authentification, par les pages Filament du back-office :
 * aucun test ne déclenche lui-même l'événement qu'il vérifie (CLAUDE.md).
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

const VALID_PASSWORD = 'Locat!on-Djerba-2026';

/**
 * @return list<string>
 */
function securityEvents(): array
{
    return Activity::query()->where('log_name', 'security')->orderBy('id')->pluck('event')->all();
}

function accountFor(Role $role): User
{
    $user = userWithProfile($role, ['name' => 'Samira Belhadj', 'email' => 'samira.belhadj@aviator.test']);

    return $role === Role::AgencyUser ? $user : withTwoFactor($user);
}

// --- D19 : réinitialisation par lien --------------------------------------------

it('refuse, par le lien de réinitialisation, un mot de passe contenant le nom ou l\'e-mail (D19)', function (Role $role, string $password) {
    $user = accountFor($role);
    $token = Password::broker()->createToken($user);

    Livewire::test(ResetPassword::class, ['email' => $user->email, 'token' => $token])
        ->fillForm(['password' => $password, 'passwordConfirmation' => $password])
        ->call('resetPassword')
        ->assertHasFormErrors(['password']);

    expect(Hash::check('password', $user->refresh()->password))->toBeTrue()
        ->and(securityEvents())->not->toContain('password_reset');
})->with([
    'admin, nom' => [Role::Admin, 'Belhadj#Location-26'],
    'admin, e-mail' => [Role::Admin, 'X9!samira.belhadj!X9'],
    'agent, prénom' => [Role::AgencyUser, 'Samira#Location-26'],
    'agent, e-mail' => [Role::AgencyUser, 'Q7!SamiraBelhadj!Q7'],
]);

it('accepte, par le lien de réinitialisation, un mot de passe conforme et journalise la réinitialisation (D19)', function (Role $role) {
    $user = accountFor($role);
    $token = Password::broker()->createToken($user);

    Livewire::test(ResetPassword::class, ['email' => $user->email, 'token' => $token])
        ->fillForm(['password' => VALID_PASSWORD, 'passwordConfirmation' => VALID_PASSWORD])
        ->call('resetPassword')
        ->assertHasNoFormErrors();

    expect(Hash::check(VALID_PASSWORD, $user->refresh()->password))->toBeTrue()
        ->and(Activity::query()->where('log_name', 'security')->where('event', 'password_reset')->value('causer_id'))->toBe($user->id);
})->with([Role::Admin, Role::AgencyUser]);

it('applique aussi les règles de composition par le lien de réinitialisation (D19)', function () {
    $user = accountFor(Role::AgencyUser);
    $token = Password::broker()->createToken($user);

    Livewire::test(ResetPassword::class, ['email' => $user->email, 'token' => $token])
        ->fillForm(['password' => 'courtmaisfaux', 'passwordConfirmation' => 'courtmaisfaux'])
        ->call('resetPassword')
        ->assertHasFormErrors(['password']);
});

// --- D19 : changement depuis le profil ------------------------------------------

it('refuse, depuis le profil, un mot de passe contenant le nom ou l\'e-mail (D19)', function (Role $role, string $password) {
    $user = accountFor($role);
    $this->actingAs($user);

    Livewire::test(EditProfile::class)
        ->fillForm(['password' => $password, 'passwordConfirmation' => $password, 'currentPassword' => 'password'])
        ->call('save')
        ->assertHasFormErrors(['password']);

    expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
})->with([
    'admin, nom' => [Role::Admin, 'Belhadj#Location-26'],
    'agent, e-mail' => [Role::AgencyUser, 'Q7!SamiraBelhadj!Q7'],
]);

it('contrôle le nouveau nom saisi dans le même formulaire du profil (D19)', function () {
    $user = accountFor(Role::AgencyUser);
    $this->actingAs($user);

    Livewire::test(EditProfile::class)
        ->fillForm([
            'name' => 'Karim Trabelsi',
            'password' => 'Trabelsi#Location-26',
            'passwordConfirmation' => 'Trabelsi#Location-26',
            'currentPassword' => 'password',
        ])
        ->call('save')
        ->assertHasFormErrors(['password']);
});

it('accepte, depuis le profil, un mot de passe conforme (D19)', function (Role $role) {
    $user = accountFor($role);
    $this->actingAs($user);

    Livewire::test(EditProfile::class)
        ->fillForm(['password' => VALID_PASSWORD, 'passwordConfirmation' => VALID_PASSWORD, 'currentPassword' => 'password'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Hash::check(VALID_PASSWORD, $user->refresh()->password))->toBeTrue();
})->with([Role::Admin, Role::AgencyUser]);

// --- Journal de sécurité : vraies connexions par le formulaire -----------------

it('journalise une connexion réussie puis la déconnexion', function () {
    $user = accountFor(Role::AgencyUser);

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    $this->assertAuthenticatedAs($user);

    $this->post(Filament::getLogoutUrl());

    $this->assertGuest();
    expect(securityEvents())->toBe(['login', 'logout']);
});

it('journalise un blocage après 6 vraies tentatives de connexion par le formulaire, sans le mot de passe essayé', function () {
    $user = accountFor(Role::AgencyUser);
    $login = Livewire::test(Login::class);

    foreach (range(1, 6) as $attempt) {
        $login->fillForm(['email' => strtoupper($user->email), 'password' => 'Mauvais#Mot-2026'])
            ->call('authenticate');
    }

    expect(securityEvents())->toBe([
        'login_failed', 'login_failed', 'login_failed', 'login_failed', 'login_failed',
        'login_lockout',
    ]);

    $lockout = Activity::query()->where('log_name', 'security')->where('event', 'login_lockout')->sole();
    $failed = Activity::query()->where('log_name', 'security')->where('event', 'login_failed')->first();

    expect($failed?->properties['email'])->toBe($user->email)
        ->and($failed?->causer_id)->toBe($user->id)
        ->and($lockout->properties['email'])->toBe($user->email)
        ->and($lockout->properties['retry_after_seconds'])->toBeGreaterThan(0)
        ->and((string) json_encode(Activity::query()->pluck('properties')))->not->toContain('Mauvais#Mot-2026');

    // Une 7e tentative, même avec le bon mot de passe, est refusée et journalisée.
    $login->fillForm(['email' => $user->email, 'password' => 'password'])->call('authenticate');

    $this->assertGuest();
    expect(securityEvents())->toHaveCount(7)
        ->and(securityEvents()[6])->toBe('login_throttled');
});
