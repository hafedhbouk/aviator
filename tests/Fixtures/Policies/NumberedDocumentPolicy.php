<?php

namespace Tests\Fixtures\Policies;

use App\Models\User;
use App\Policies\Concerns\ForbidsDeletingNumberedDocuments;
use Illuminate\Database\Eloquent\Model;

/**
 * Policy de test d'un document numéroté : brouillons supprimables par le super admin.
 */
class NumberedDocumentPolicy
{
    use ForbidsDeletingNumberedDocuments;

    protected function canDeleteDraft(User $user, Model $record): bool
    {
        return $user->checkPermissionTo('users.manage');
    }
}
