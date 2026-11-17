<?php

use App\Models\Agency;
use App\Models\Customer;
use App\Support\Authorization\Role;
use App\Support\Privacy\CustomerDataAccess;
use App\Support\Privacy\ExportKind;
use App\Support\Privacy\ExportLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

function access(): CustomerDataAccess
{
    return app(CustomerDataAccess::class);
}

// --- Matrice D13 ------------------------------------------------------------

it('réserve le carnet d\'adresses et l\'export de fichier clients au super admin', function (Role $role, bool $expected) {
    $user = userWithProfile($role);

    expect(access()->canViewAddressBook($user))->toBe($expected)
        ->and(access()->canExportCustomerFile($user))->toBe($expected)
        ->and($user->can('viewAny', Customer::class))->toBe($expected)
        ->and($user->can('export', Customer::class))->toBe($expected);
})->with([
    'super admin' => [Role::SuperAdmin, true],
    'admin' => [Role::Admin, false],
    'utilisateur d\'agence' => [Role::AgencyUser, false],
]);

it('donne à chaque profil le bon niveau d\'export (D13)', function (Role $role, ExportKind $kind, ExportLevel $expected) {
    expect(access()->exportLevel(userWithProfile($role), $kind))->toBe($expected);
})->with([
    'super admin, fichier clients' => [Role::SuperAdmin, ExportKind::CustomerFile, ExportLevel::Full],
    'super admin, liste métier' => [Role::SuperAdmin, ExportKind::BusinessList, ExportLevel::Full],
    'super admin, comptable' => [Role::SuperAdmin, ExportKind::Accounting, ExportLevel::Accounting],
    'admin, fichier clients' => [Role::Admin, ExportKind::CustomerFile, ExportLevel::Denied],
    'admin, liste métier' => [Role::Admin, ExportKind::BusinessList, ExportLevel::Aggregates],
    'admin, statistiques' => [Role::Admin, ExportKind::Statistics, ExportLevel::Aggregates],
    'admin, comptable' => [Role::Admin, ExportKind::Accounting, ExportLevel::Accounting],
    'agence, fichier clients' => [Role::AgencyUser, ExportKind::CustomerFile, ExportLevel::Denied],
    'agence, liste métier' => [Role::AgencyUser, ExportKind::BusinessList, ExportLevel::Denied],
    'agence, comptable' => [Role::AgencyUser, ExportKind::Accounting, ExportLevel::Denied],
]);

it('limite les exports comptables au nom et au matricule fiscal, même pour le super admin', function () {
    $level = access()->exportLevel(userWithProfile(Role::SuperAdmin), ExportKind::Accounting);

    expect($level)->toBe(ExportLevel::Accounting)
        ->and($level->includesNames())->toBeTrue()
        ->and($level->includesContactDetails())->toBeFalse();
});

it('montre les noms des clients à l\'admin dans les listes métier, sans coordonnées', function () {
    $admin = userWithProfile(Role::Admin);

    expect(access()->canViewNames($admin))->toBeTrue()
        ->and(access()->canViewAddressBook($admin))->toBeFalse();
});

it('limite les noms visibles par un utilisateur d\'agence aux documents de son agence', function () {
    [$sfax, $sousse] = Agency::factory()->count(2)->create();
    $agent = userWithProfile(Role::AgencyUser, ['agency_id' => $sfax->id]);

    expect(access()->canViewNames($agent, $sfax->id))->toBeTrue()
        ->and(access()->canViewNames($agent, $sousse->id))->toBeFalse()
        ->and(access()->canViewNames($agent))->toBeFalse();
});

it('réserve les notifications groupées aux clients au super admin (K-18)', function (Role $role, bool $expected) {
    expect(access()->canNotifyCustomersInBulk(userWithProfile($role)))->toBe($expected);
})->with([[Role::SuperAdmin, true], [Role::Admin, false], [Role::AgencyUser, false]]);

it('interdit la suppression d\'un client, même au super admin', function () {
    $customer = Customer::factory()->create();

    expect(userWithProfile(Role::SuperAdmin)->can('delete', $customer))->toBeFalse();
});

// --- Scénario 13 (partie droits) : refus journalisé --------------------------

it('journalise le refus quand l\'admin ou un agent tente d\'ouvrir le carnet ou d\'exporter', function (Role $role) {
    $user = userWithProfile($role);

    expect($user->can('viewAny', Customer::class))->toBeFalse()
        ->and($user->can('export', Customer::class))->toBeFalse();

    $refusals = Activity::query()
        ->where('log_name', 'customer_access')
        ->where('event', 'access_denied')
        ->where('causer_id', $user->id)
        ->get();

    expect($refusals->pluck('properties.ability')->all())->toContain('address_book', 'customer_file_export')
        ->and($refusals->first()?->properties['profile'])->toBe($role->value);
})->with([Role::Admin, Role::AgencyUser]);

it('journalise l\'utilisation du carnet par le super admin (K-03)', function () {
    $superAdmin = userWithProfile(Role::SuperAdmin);

    access()->recordAddressBookUse($superAdmin, 'address_book_viewed', ['filters' => []]);

    expect(Activity::query()->where('log_name', 'customer_access')->where('event', 'address_book_viewed')->count())->toBe(1);
});

// --- Assouplissement par permission (D17) ------------------------------------

it('assouplit l\'export des noms pour l\'admin sans changer le code, puis le rétablit', function () {
    $admin = userWithProfile(Role::Admin);

    $this->artisan('aviator:permission', ['action' => 'grant', 'role' => 'admin', 'permission' => 'customers.export_names', '--by' => 'Direction Aviator'])
        ->assertSuccessful();

    expect(access()->exportLevel($admin->fresh(), ExportKind::BusinessList))->toBe(ExportLevel::Names)
        ->and(access()->exportLevel($admin->fresh(), ExportKind::CustomerFile))->toBe(ExportLevel::Denied);

    $this->artisan('aviator:permission', ['action' => 'revoke', 'role' => 'admin', 'permission' => 'customers.export_names', '--by' => 'Direction Aviator'])
        ->assertSuccessful();

    expect(access()->exportLevel($admin->fresh(), ExportKind::BusinessList))->toBe(ExportLevel::Aggregates);
});

it('trace chaque changement de permission : qui, quoi, quand', function () {
    userWithProfile(Role::Admin);

    $this->artisan('aviator:permission', ['action' => 'grant', 'role' => 'admin', 'permission' => 'customers.export_names', '--by' => 'Direction Aviator'])
        ->assertSuccessful();

    $activity = Activity::query()->where('log_name', 'audit')->where('event', 'permission_granted')->sole();

    expect($activity->properties['role'])->toBe('admin')
        ->and($activity->properties['permission'])->toBe('customers.export_names')
        ->and($activity->properties['decided_by'])->toBe('Direction Aviator')
        ->and($activity->properties['operator'])->not->toBeEmpty()
        ->and($activity->created_at)->not->toBeNull();
});

it('demande qui a décidé du changement si --by est absent', function () {
    userWithProfile(Role::Admin);

    $this->artisan('aviator:permission', ['action' => 'grant', 'role' => 'admin', 'permission' => 'customers.export_names'])
        ->expectsQuestion('Qui a décidé de ce changement (nom et fonction) ?', 'Gérant')
        ->assertSuccessful();

    expect(Activity::query()->where('event', 'permission_granted')->value('properties')['decided_by'])->toBe('Gérant');
});

it('refuse une permission non assouplissable', function (string $role, string $permission) {
    userWithProfile(Role::Admin);

    $this->artisan('aviator:permission', ['action' => 'grant', 'role' => $role, 'permission' => $permission, '--by' => 'Direction'])
        ->assertFailed();

    expect(Activity::query()->where('event', 'permission_granted')->exists())->toBeFalse();
})->with([
    'carnet d\'adresses à l\'admin' => ['admin', 'customers.address_book'],
    'export clients à l\'admin' => ['admin', 'customers.export'],
    'export des noms aux agences' => ['agency_user', 'customers.export_names'],
    'retrait d\'une permission du super admin' => ['super_admin', 'users.manage'],
    'permission inconnue' => ['admin', 'inconnue.permission'],
]);

it('refuse un changement de permission en production sans --force', function () {
    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan('aviator:permission', ['action' => 'grant', 'role' => 'admin', 'permission' => 'customers.export_names', '--by' => 'Direction'])
        ->assertFailed();
});

it('conserve l\'assouplissement lors d\'une nouvelle synchronisation des permissions', function () {
    $admin = userWithProfile(Role::Admin);
    $this->artisan('aviator:permission', ['action' => 'grant', 'role' => 'admin', 'permission' => 'customers.export_names', '--by' => 'Direction']);

    $this->artisan('aviator:sync-permissions')->assertSuccessful();

    expect(access()->exportLevel($admin->fresh(), ExportKind::BusinessList))->toBe(ExportLevel::Names);
});
