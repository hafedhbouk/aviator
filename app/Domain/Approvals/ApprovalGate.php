<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

use App\Domain\Approvals\Exceptions\ApprovalException;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Models\ApprovalRequest;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * Lecture de l'état des approbations d'un document, pour les actions qui en dépendent
 * (D46) : départ d'un contrat (remise approuvée), confirmation d'une réservation d'un
 * client sur liste noire (dérogation approuvée **pour ce document**, jamais pour le
 * client en général).
 */
final class ApprovalGate
{
    /**
     * État de la remise d'un document : null si aucune approbation n'est nécessaire
     * (pas de remise, ou remise sous le seuil enregistrée sans demande).
     */
    public function discountStatus(Money $discount, ?int $approvalId): ?ApprovalStatus
    {
        if ($discount->isZero() || $approvalId === null) {
            return null;
        }

        // Demande introuvable : traitée comme en attente (le départ reste bloqué).
        return ApprovalRequest::query()->withoutGlobalScopes()->find($approvalId)->status ?? ApprovalStatus::Pending;
    }

    /**
     * @throws ApprovalException remise en attente ou refusée
     */
    public function assertDiscountCleared(Money $discount, ?int $approvalId): void
    {
        $status = $this->discountStatus($discount, $approvalId);

        if ($status !== null && $status !== ApprovalStatus::Approved) {
            throw ApprovalException::discountPending();
        }
    }

    /**
     * Dérogation approuvée pour ce document précis.
     */
    public function overrideApproved(Model $subject, ?int $approvalId): bool
    {
        return $this->override($subject, $approvalId)?->isApproved() ?? false;
    }

    /**
     * Dérogation en attente pour ce document précis.
     */
    public function overridePending(Model $subject, ?int $approvalId): bool
    {
        return $this->override($subject, $approvalId)?->isPending() ?? false;
    }

    private function override(Model $subject, ?int $approvalId): ?ApprovalRequest
    {
        if ($approvalId === null || ! $subject->exists) {
            return null;
        }

        return ApprovalRequest::query()->withoutGlobalScopes()
            ->whereKey($approvalId)
            ->where('type', ApprovalType::BlacklistOverride->value)
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->first();
    }
}
