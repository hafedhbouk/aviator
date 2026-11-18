<?php

use App\Domain\Administration\Actions\SaveUser;
use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Models\Agency;
use App\Models\Customer;
use App\Models\User;
use App\Support\Authorization\Role;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

function lastAudit(string $event, object $subject): Activity
{
    return Activity::query()
        ->where('log_name', 'audit')
        ->where('event', $event)
        ->where('subject_type', $subject::class)
        ->where('subject_id', $subject->getKey())
        ->latest('id')
        ->firstOrFail();
}

// --- Historique des modifications (J-05) -------------------------------------

it('trace qui, quand, avant et après pour une modification de compte', function () {
    $superAdmin = withTwoFactor(userWithProfile(Role::SuperAdmin));
    $agency = Agency::factory()->create();
    $agent = userWithProfile(Role::AgencyUser, ['agency_id' => $agency->id, 'name' => 'Ancien nom']);

    $this->actingAs($superAdmin);
    app(SaveUser::class)->handle($agent, [
        'name' => 'Nouveau nom',
        'email' => $agent->email,
        'role' => Role::AgencyUser->value,
        'agency_id' => $agency->id,
    ], $superAdmin);

    $activity = lastAudit('updated', $agent);

    expect($activity->causer?->is($superAdmin))->toBeTrue()
        ->and($activity->created_at)->not->toBeNull()
        ->and($activity->properties['old']['name'])->toBe('Ancien nom')
        ->and($activity->properties['attributes']['name'])->toBe('Nouveau nom');
});

it('ne copie jamais un mot de passe dans le journal, mais signale son changement', function () {
    $user = userWithProfile(Role::Admin);

    $user->update(['password' => 'Nouveau#Secret-2026']);

    $activity = lastAudit('updated', $user);
    $json = (string) json_encode($activity->properties);

    expect($json)->not->toContain('Nouveau#Secret-2026')
        ->and($json)->not->toContain((string) $user->fresh()?->password)
        ->and($activity->properties['sensitive_changed'])->toBe(['password'])
        ->and($activity->properties->has('attributes.password'))->toBeFalse();
});

it('signale l\'activation de la 2FA sans copier le secret', function () {
    $user = userWithProfile(Role::SuperAdmin);

    $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP');

    $activity = lastAudit('updated', $user);

    expect((string) json_encode($activity->properties))->not->toContain('JBSWY3DPEHPK3PXP')
        ->and($activity->properties['sensitive_changed'])->toBe(['app_authentication_secret']);
});

it('trace les clients sans copier le numéro de pièce, ni en clair ni chiffré', function () {
    $customer = Customer::factory()->create(['id_document_number' => '01234567', 'city' => 'Sfax']);
    $created = lastAudit('created', $customer);

    $customer->update(['id_document_number' => '07654321']);
    $updated = lastAudit('updated', $customer);

    $raw = (string) $customer->getRawOriginal('id_document_number');

    foreach ([$created, $updated] as $activity) {
        $json = (string) json_encode($activity->properties);

        expect($json)->not->toContain('01234567')
            ->and($json)->not->toContain('07654321')
            ->and($json)->not->toContain($raw)
            ->and($activity->properties['sensitive_changed'])->toBe(['id_document_number']);
    }

    expect($created->properties['attributes']['city'])->toBe('Sfax');
});

it('trace les modifications d\'une agence', function () {
    $agency = Agency::factory()->create(['phone' => '+216 75 000 000']);

    $agency->update(['phone' => '+216 75 111 111']);

    $activity = lastAudit('updated', $agency);

    expect($activity->properties['old']['phone'])->toBe('+216 75 000 000')
        ->and($activity->properties['attributes']['phone'])->toBe('+216 75 111 111');
});

it('n\'écrit rien quand rien n\'a changé', function () {
    $agency = Agency::factory()->create();
    $before = Activity::query()->count();

    $agency->update(['name' => $agency->name]);

    expect(Activity::query()->count())->toBe($before);
});

// --- Journal de sécurité des connexions (J-04) --------------------------------

it('journalise les connexions, déconnexions et réinitialisations de mot de passe', function () {
    $user = userWithProfile(Role::AgencyUser);

    event(new Login('web', $user, false));
    event(new Logout('web', $user));
    event(new PasswordReset($user));

    $events = Activity::query()->where('log_name', 'security')->where('causer_id', $user->id)->pluck('event')->all();

    expect($events)->toContain('login', 'logout', 'password_reset');
});

it('journalise un échec de connexion sans le mot de passe essayé', function () {
    $user = userWithProfile(Role::Admin);

    event(new Failed('web', $user, ['email' => strtoupper($user->email), 'password' => 'Mauvais#Mot-2026']));

    $activity = Activity::query()->where('log_name', 'security')->where('event', 'login_failed')->sole();

    expect($activity->properties['email'])->toBe(mb_strtolower($user->email))
        ->and((string) json_encode($activity->properties))->not->toContain('Mauvais#Mot-2026');
});

it('journalise un blocage après trop d\'échecs', function () {
    event(new Lockout(Request::create('/admin/login', 'POST', ['email' => 'inconnu@example.test'])));

    expect(Activity::query()->where('log_name', 'security')->where('event', 'login_lockout')->value('properties')['email'])
        ->toBe('inconnu@example.test');
});

// --- Page du journal ----------------------------------------------------------

it('ouvre le journal d\'audit au super admin seulement', function (Role $role, int $status) {
    $this->actingAs(withTwoFactor(userWithProfile($role)))
        ->get(route('filament.admin.resources.journal-audit.index'))
        ->assertStatus($status);
})->with([
    'super admin' => [Role::SuperAdmin, 200],
    'admin' => [Role::Admin, 403],
    'utilisateur d\'agence' => [Role::AgencyUser, 403],
]);

it('affiche le détail d\'une entrée au super admin', function () {
    $superAdmin = withTwoFactor(userWithProfile(Role::SuperAdmin));
    $agency = Agency::factory()->create();
    $agency->update(['phone' => '+216 75 222 222']);

    $this->actingAs($superAdmin)
        ->get(route('filament.admin.resources.journal-audit.view', ['record' => lastAudit('updated', $agency)]))
        ->assertOk()
        ->assertSee('+216 75 222 222');
});

it('ne propose ni modification, ni suppression, ni export du journal', function () {
    $superAdmin = withTwoFactor(userWithProfile(Role::SuperAdmin));
    Agency::factory()->create();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($superAdmin);

    $activity = Activity::query()->firstOrFail();

    Livewire::test(ListActivityLogs::class)
        ->assertCanSeeTableRecords([$activity])
        ->assertActionDoesNotExist(TestAction::make('edit')->table($activity))
        ->assertActionDoesNotExist(TestAction::make('delete')->table($activity))
        ->assertActionDoesNotExist(TestAction::make('export')->table());

    expect($superAdmin->can('update', $activity))->toBeFalse()
        ->and($superAdmin->can('delete', $activity))->toBeFalse()
        ->and($superAdmin->can('create', Activity::class))->toBeFalse();
});

it('n\'attribue aucun auteur à une action faite sans utilisateur connecté (console, file)', function () {
    auth()->logout();

    $agency = Agency::factory()->create();

    expect(lastAudit('created', $agency)->causer_id)->toBeNull();
});

it('attribue l\'action à l\'utilisateur connecté', function () {
    $admin = userWithProfile(Role::Admin);
    $this->actingAs($admin);

    $agency = Agency::factory()->create();

    expect(lastAudit('created', $agency)->causer?->is($admin))->toBeTrue()
        ->and(User::query()->whereKey($admin->id)->exists())->toBeTrue();
});
