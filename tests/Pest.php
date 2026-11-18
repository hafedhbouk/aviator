<?php

use App\Models\User;
use App\Support\Authorization\Role;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role as RoleModel;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Suite de tests Aviator
|--------------------------------------------------------------------------
|
| Tous les tests tournent sur MariaDB 10.11 (base aviator_test), jamais sur
| SQLite : les règles anti-chevauchement reposent sur des verrous de ligne.
|
*/

pest()->extend(TestCase::class)->in('Feature', 'Unit', 'Arch');

/**
 * Crée un utilisateur doté d'un profil, après synchronisation des rôles et permissions.
 *
 * @param  array<string, mixed>  $attributes
 */
function userWithProfile(Role $role, array $attributes = []): User
{
    if (! RoleModel::query()->where('name', $role->value)->exists()) {
        Artisan::call('aviator:sync-permissions');
    }

    $user = User::factory()->create($attributes);
    $user->assignRole($role->value);

    return $user;
}

/**
 * Active la 2FA par application d'un utilisateur (secret de test, base32).
 */
function withTwoFactor(User $user): User
{
    $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP');

    return $user->refresh();
}
