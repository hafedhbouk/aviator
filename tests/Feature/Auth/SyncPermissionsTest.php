<?php

use App\Support\Authorization\Permission;
use App\Support\Authorization\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;

uses(RefreshDatabase::class);

it('crée les 3 profils et toutes les permissions déclarées dans le code (D16)', function () {
    $this->artisan('aviator:sync-permissions')->assertSuccessful();

    expect(RoleModel::query()->pluck('name')->sort()->values()->all())
        ->toBe(['admin', 'agency_user', 'super_admin'])
        ->and(PermissionModel::query()->count())->toBe(count(Permission::cases()));
});

it('est idempotente', function () {
    $this->artisan('aviator:sync-permissions')->assertSuccessful();
    $this->artisan('aviator:sync-permissions')->assertSuccessful();

    expect(RoleModel::query()->count())->toBe(3)
        ->and(PermissionModel::query()->count())->toBe(count(Permission::cases()));
});

it('attribue les permissions par défaut de la matrice des droits', function (Role $role, array $expected) {
    $this->artisan('aviator:sync-permissions');

    $actual = RoleModel::findByName($role->value)->permissions->pluck('name')->sort()->values()->all();

    expect($actual)->toBe(collect($expected)->map(fn (Permission $p) => $p->value)->sort()->values()->all());
})->with([
    'super admin' => [Role::SuperAdmin, Permission::cases()],
    'admin' => [Role::Admin, [Permission::AgenciesAll, Permission::CustomersViewNames, Permission::ExportsAccounting, Permission::ReferenceDataManage]],
    'utilisateur d\'agence' => [Role::AgencyUser, [Permission::CustomersViewNames]],
]);

it('n\'accorde pas à l\'admin l\'export des noms de clients par défaut (D13)', function () {
    $this->artisan('aviator:sync-permissions');

    expect(RoleModel::findByName(Role::Admin->value)->hasPermissionTo(Permission::CustomersExportNames->value))->toBeFalse()
        ->and(Permission::CustomersExportNames->relaxableFor())->toBe([Role::Admin]);
});

it('conserve une permission accordée en plus, mais retire une permission qui n\'existe plus', function () {
    $this->artisan('aviator:sync-permissions');
    RoleModel::findByName(Role::Admin->value)->givePermissionTo(Permission::CustomersExportNames->value);
    PermissionModel::create(['name' => 'obsolete.permission', 'guard_name' => 'web']);

    $this->artisan('aviator:sync-permissions')->assertSuccessful();

    expect(RoleModel::findByName(Role::Admin->value)->hasPermissionTo(Permission::CustomersExportNames->value))->toBeTrue()
        ->and(PermissionModel::query()->where('name', 'obsolete.permission')->exists())->toBeFalse();
});
