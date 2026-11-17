<?php

use App\Domain\Administration\Actions\SaveUser;
use App\Models\Agency;
use App\Models\User;
use App\Settings\SecuritySettings;
use App\Support\Authorization\Role;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/**
 * Simule une requête d'une session ouverte : l'utilisateur est relu en base,
 * comme en production (pas d'instance gardée en mémoire par actingAs).
 */
function requestAsSessionOf(User $user): TestResponse
{
    Auth::forgetGuards();

    return test()->withSession([Auth::guard('web')->getName() => $user->getKey()])->get('/admin');
}

function insertSession(User $user): void
{
    DB::table('sessions')->insert([
        'id' => Str::random(40),
        'user_id' => $user->getKey(),
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Test',
        'payload' => base64_encode(serialize([])),
        'last_activity' => now()->getTimestamp(),
    ]);
}

// --- Codes de secours de la 2FA --------------------------------------------

it('active les codes de récupération de la 2FA', function () {
    $provider = Filament::getPanel('admin')->getMultiFactorAuthenticationProviders()['app'];

    expect($provider)->toBeInstanceOf(AppAuthentication::class)
        ->and($provider->isRecoverable())->toBeTrue();
});

it('stocke les codes de récupération hachés, jamais en clair', function () {
    $user = userWithProfile(Role::SuperAdmin);
    $provider = Filament::getPanel('admin')->getMultiFactorAuthenticationProviders()['app'];
    $codes = $provider->generateRecoveryCodes();

    $provider->saveRecoveryCodes($user, $codes);
    $raw = (string) DB::table('users')->where('id', $user->id)->value('app_authentication_recovery_codes');

    expect($codes)->not->toBeEmpty()
        ->and($raw)->not->toContain($codes[0])
        ->and($user->fresh()?->getAppAuthenticationRecoveryCodes())->not->toContain($codes[0]);
});

// --- aviator:reset-2fa ------------------------------------------------------

it('réinitialise la 2FA, coupe les sessions et journalise (accès de secours)', function () {
    $user = withTwoFactor(userWithProfile(Role::SuperAdmin));
    $user->saveAppAuthenticationRecoveryCodes(['code-haché']);
    insertSession($user);
    $token = $user->fresh()?->getRememberToken();

    $this->artisan('aviator:reset-2fa', ['email' => $user->email])
        ->expectsConfirmation("Réinitialiser la 2FA de {$user->name} ({$user->email}, Super admin) ? Ses sessions seront coupées.", 'yes')
        ->assertSuccessful();

    $fresh = $user->fresh();

    expect($fresh?->getAppAuthenticationSecret())->toBeNull()
        ->and($fresh?->getAppAuthenticationRecoveryCodes())->toBeNull()
        ->and($fresh?->getRememberToken())->not->toBe($token)
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and(Activity::query()->where('log_name', 'security')->where('event', 'two_factor_reset')->exists())->toBeTrue();
});

it('impose une nouvelle activation de la 2FA à la connexion suivante', function () {
    $user = withTwoFactor(userWithProfile(Role::SuperAdmin));

    $this->artisan('aviator:reset-2fa', ['email' => $user->email])
        ->expectsConfirmation("Réinitialiser la 2FA de {$user->name} ({$user->email}, Super admin) ? Ses sessions seront coupées.", 'yes')
        ->assertSuccessful();

    requestAsSessionOf($user)->assertRedirect((string) Filament::getPanel('admin')->getSetUpRequiredMultiFactorAuthenticationUrl());
});

it('ne modifie rien sans confirmation', function () {
    $user = withTwoFactor(userWithProfile(Role::Admin));

    $this->artisan('aviator:reset-2fa', ['email' => $user->email])
        ->expectsConfirmation("Réinitialiser la 2FA de {$user->name} ({$user->email}, Admin du siège) ? Ses sessions seront coupées.", 'no')
        ->assertFailed();

    expect($user->fresh()?->getAppAuthenticationSecret())->not->toBeNull();
});

it('signale une adresse inconnue', function () {
    $this->artisan('aviator:reset-2fa', ['email' => 'inconnu@example.test'])->assertFailed();
});

it('refuse de réinitialiser une 2FA en production sans --force', function () {
    $user = withTwoFactor(userWithProfile(Role::SuperAdmin));
    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan('aviator:reset-2fa', ['email' => $user->email])->assertFailed();

    expect($user->fresh()?->getAppAuthenticationSecret())->not->toBeNull();
});

// --- Désactivation d'un compte ----------------------------------------------

it('coupe toutes les sessions et les jetons « se souvenir de moi » à la désactivation', function () {
    $agency = Agency::factory()->create();
    $agent = userWithProfile(Role::AgencyUser, ['agency_id' => $agency->id]);
    insertSession($agent);
    insertSession($agent);
    $other = userWithProfile(Role::AgencyUser, ['agency_id' => $agency->id]);
    insertSession($other);
    $token = $agent->fresh()?->getRememberToken();

    requestAsSessionOf($agent)->assertOk();

    app(SaveUser::class)->handle($agent->fresh(), [
        'name' => $agent->name,
        'email' => $agent->email,
        'role' => Role::AgencyUser->value,
        'agency_id' => $agency->id,
        'is_active' => false,
    ], withTwoFactor(userWithProfile(Role::SuperAdmin)));

    expect(DB::table('sessions')->where('user_id', $agent->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $other->id)->count())->toBe(1)
        ->and($agent->fresh()?->getRememberToken())->not->toBe($token)
        ->and(Activity::query()->where('log_name', 'security')->where('event', 'user_deactivated')->exists())->toBeTrue();

    // La requête suivante de cette session est refusée.
    requestAsSessionOf($agent)->assertForbidden();
});

// --- 2FA des utilisateurs d'agence (Q76) ------------------------------------

it('désactive par défaut la 2FA obligatoire des utilisateurs d\'agence', function () {
    expect(app(SecuritySettings::class)->require_2fa_for_agency_users)->toBeFalse();

    requestAsSessionOf(userWithProfile(Role::AgencyUser))->assertOk();
});

it('impose la 2FA aux utilisateurs d\'agence quand le réglage est activé', function () {
    $settings = app(SecuritySettings::class);
    $settings->require_2fa_for_agency_users = true;
    $settings->save();

    requestAsSessionOf(userWithProfile(Role::AgencyUser))
        ->assertRedirect((string) Filament::getPanel('admin')->getSetUpRequiredMultiFactorAuthenticationUrl());

    requestAsSessionOf(withTwoFactor(userWithProfile(Role::AgencyUser)))->assertOk();
});
