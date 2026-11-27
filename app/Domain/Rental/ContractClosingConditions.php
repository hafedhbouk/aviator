<?php

declare(strict_types=1);

namespace App\Domain\Rental;

use App\Enums\ApprovalStatus;
use App\Models\ApprovalRequest;
use App\Models\Contract;
use App\Models\ContractExtension;

/**
 * Conditions de clôture d'un contrat (D49, D-11), lues sous le verrou du contrat par
 * CloseContract. Liste extensible : en phase 5, retour validé et aucune approbation en
 * attente ; **la phase 6 ajoutera** la facture émise et la caution soldée, ici même.
 */
final class ContractClosingConditions
{
    /**
     * Motifs qui empêchent la clôture (vide = clôture possible).
     *
     * @return list<string>
     */
    public function failures(Contract $contract): array
    {
        $failures = [];

        if ($contract->returned_at === null) {
            $failures[] = 'retour non validé';
        }

        if ($this->pendingApprovals($contract) > 0) {
            $failures[] = 'une demande d\'approbation attend le super admin';
        }

        return $failures;
    }

    private function pendingApprovals(Contract $contract): int
    {
        $extensionIds = ContractExtension::query()->where('contract_id', $contract->id)->pluck('id');

        return ApprovalRequest::query()->withoutGlobalScopes()
            ->where('status', ApprovalStatus::Pending->value)
            ->where(function ($query) use ($contract, $extensionIds): void {
                $query->where(fn ($q) => $q->where('subject_type', $contract->getMorphClass())->where('subject_id', $contract->id))
                    ->orWhere(fn ($q) => $q->where('subject_type', (new ContractExtension)->getMorphClass())->whereIn('subject_id', $extensionIds))
                    ->when($contract->discount_approval_id !== null, fn ($q) => $q->orWhere('id', $contract->discount_approval_id));
            })
            ->count();
    }
}
