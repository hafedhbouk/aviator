<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Authorization\Permission;
use App\Support\Authorization\Role;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Crée les rôles et les permissions déclarés dans le code et rétablit les
 * attributions par défaut (D16). Idempotente : rejouée à chaque déploiement.
 *
 * Les permissions accordées en plus par « aviator:permission grant » (D17) sont
 * conservées : la synchronisation ne retire que ce qui n'existe plus dans le code.
 */
class SyncPermissionsCommand extends Command
{
    /** @var string */
    protected $signature = 'aviator:sync-permissions';

    /** @var string */
    protected $description = 'Crée ou met à jour les rôles et permissions déclarés dans le code';

    public function handle(PermissionRegistrar $registrar): int
    {
        $registrar->forgetCachedPermissions();
        $guard = (string) config('auth.defaults.guard', 'web');

        foreach (Role::cases() as $role) {
            RoleModel::findOrCreate($role->value, $guard);
        }

        foreach (Permission::cases() as $permission) {
            $model = PermissionModel::findOrCreate($permission->value, $guard);

            foreach ($permission->defaultRoles() as $role) {
                RoleModel::findByName($role->value, $guard)->givePermissionTo($model);
            }
        }

        $declared = array_map(fn (Permission $p): string => $p->value, Permission::cases());
        $obsolete = PermissionModel::query()->where('guard_name', $guard)->whereNotIn('name', $declared)->get();

        foreach ($obsolete as $permission) {
            $permission->delete();
        }

        $registrar->forgetCachedPermissions();

        $this->components->info(sprintf(
            'Rôles : %d. Permissions : %d. Permissions obsolètes retirées : %d.',
            count(Role::cases()),
            count($declared),
            $obsolete->count(),
        ));

        return self::SUCCESS;
    }
}
