<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\Authorization\Permission;
use App\Support\Authorization\Role;

/**
 * Gestion des comptes : super admin uniquement (J-01, J-02). Aucun compte n'est
 * supprimé (désactivation à la place) ; les comptes super admin se gèrent en
 * ligne de commande.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::UsersManage->value);
    }

    public function view(User $actor, User $user): bool
    {
        return $actor->can(Permission::UsersManage->value);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::UsersManage->value);
    }

    public function update(User $actor, User $user): bool
    {
        return $actor->can(Permission::UsersManage->value) && ! $user->hasProfile(Role::SuperAdmin);
    }

    public function sendPasswordResetLink(User $actor, User $user): bool
    {
        return $this->update($actor, $user);
    }

    public function delete(User $actor, User $user): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }

    public function restore(User $actor, User $user): bool
    {
        return false;
    }

    public function forceDelete(User $actor, User $user): bool
    {
        return false;
    }

    public function forceDeleteAny(User $actor): bool
    {
        return false;
    }
}
