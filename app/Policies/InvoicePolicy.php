<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;
use App\Policies\Concerns\ForbidsDeletingNumberedDocuments;
use Illuminate\Database\Eloquent\Model;

/**
 * Factures et avoirs (module E, RG-13) : chaque profil agit sur les documents des
 * agences où il agit. Une facture validée ne se modifie ni ne se supprime, pour
 * personne, super admin compris (RG-06) ; un brouillon se modifie et se supprime.
 * La matrice complète des droits de la phase 6 est vérifiée au lot L15.
 */
class InvoicePolicy
{
    use ForbidsDeletingNumberedDocuments;

    public function viewAny(User $actor): bool
    {
        return $actor->canActOnAllAgencies() || $actor->authorizedAgencyIds() !== [];
    }

    public function view(User $actor, Invoice $invoice): bool
    {
        return $actor->canActOnAgency($invoice->agency_id);
    }

    public function create(User $actor): bool
    {
        return $this->viewAny($actor);
    }

    public function update(User $actor, Invoice $invoice): bool
    {
        return $invoice->status === InvoiceStatus::Draft && $actor->canActOnAgency($invoice->agency_id);
    }

    protected function canDeleteDraft(User $user, Model $record): bool
    {
        return $record instanceof Invoice && $this->update($user, $record);
    }
}
