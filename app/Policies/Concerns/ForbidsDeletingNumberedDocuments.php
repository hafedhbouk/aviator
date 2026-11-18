<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Policies des documents numérotés (HasSequenceNumber) : aucune suppression d'un
 * document numéroté, pour personne, super admin compris. Les suppressions en masse
 * sont refusées dans l'interface ; un brouillon sans numéro peut être supprimé si
 * la policy du document l'autorise (canDeleteDraft).
 */
trait ForbidsDeletingNumberedDocuments
{
    public function delete(User $user, Model $record): bool
    {
        return ! $this->numbered($record) && $this->canDeleteDraft($user, $record);
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    /**
     * Droit de supprimer un brouillon sans numéro : refusé par défaut, à ouvrir
     * document par document.
     */
    protected function canDeleteDraft(User $user, Model $record): bool
    {
        return false;
    }

    private function numbered(Model $record): bool
    {
        return method_exists($record, 'isNumbered') ? (bool) $record->isNumbered() : $record->getAttribute('number') !== null;
    }
}
