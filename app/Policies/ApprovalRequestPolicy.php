<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ApprovalRequest;
use App\Models\User;
use App\Support\Authorization\Permission;

/**
 * Demandes d'approbation (D46, P5) : consultées et tranchées par le super admin seul
 * (`approvals.decide`, Q52 : pas de délégation). Le demandeur suit l'état de sa demande
 * depuis le document concerné. Aucune création, modification ni suppression par
 * l'interface : tout passe par App\Domain\Approvals.
 */
class ApprovalRequestPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermissionTo(Permission::ApprovalsDecide->value);
    }

    public function view(User $actor, ApprovalRequest $request): bool
    {
        return $this->viewAny($actor);
    }

    public function decide(User $actor, ApprovalRequest $request): bool
    {
        return $this->viewAny($actor) && $request->isPending();
    }

    public function create(User $actor): bool
    {
        return false;
    }

    public function update(User $actor, ApprovalRequest $request): bool
    {
        return false;
    }

    public function delete(User $actor, ApprovalRequest $request): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
