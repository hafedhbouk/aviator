<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\Authorization\Permission;
use Spatie\Activitylog\Models\Activity;

/**
 * Journal d'audit (J-04, J-05) : consultation par le super admin (audit.view) ;
 * aucune création, modification ni suppression depuis l'interface. Il contient des
 * valeurs avant et après, y compris des coordonnées de clients : pas d'export.
 */
class ActivityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo(Permission::AuditView->value);
    }

    public function view(User $user, Activity $activity): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Activity $activity): bool
    {
        return false;
    }

    public function delete(User $user, Activity $activity): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
