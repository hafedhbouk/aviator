<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Actions;

use App\Domain\Approvals\ApprovalEffects;
use App\Domain\Approvals\ApprovalNotifier;
use App\Domain\Approvals\Exceptions\ApprovalException;
use App\Enums\ApprovalStatus;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Support\Authorization\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Décision du super admin sur une demande (D46, P5, étape 5).
 *
 * - Contrôle serveur du droit (`approvals.decide`, super admin seul, Q52 : pas de
 *   délégation) et de l'état, sous verrou de la ligne : une demande déjà tranchée est
 *   refusée, même si deux décisions arrivent au même moment.
 * - Refus toujours motivé ; approbation avec commentaire facultatif.
 * - Après la validation : effet sur l'objet (ApprovalEffects : un refus de remise
 *   retire la remise, un refus de dérogation annule le brouillon), puis notification
 *   du demandeur, sans donnée sensible (RG-20).
 */
final class DecideApproval
{
    public const MIN_COMMENT_LENGTH = 5;

    public function __construct(
        private readonly ApprovalEffects $effects,
        private readonly ApprovalNotifier $notifier,
    ) {}

    public function approve(ApprovalRequest $request, User $decider, ?string $comment = null): ApprovalRequest
    {
        return $this->decide($request, $decider, ApprovalStatus::Approved, $comment);
    }

    public function reject(ApprovalRequest $request, User $decider, ?string $comment): ApprovalRequest
    {
        if (mb_strlen(trim((string) $comment)) < self::MIN_COMMENT_LENGTH) {
            throw ApprovalException::commentRequired(self::MIN_COMMENT_LENGTH);
        }

        return $this->decide($request, $decider, ApprovalStatus::Rejected, $comment);
    }

    private function decide(ApprovalRequest $request, User $decider, ApprovalStatus $status, ?string $comment): ApprovalRequest
    {
        if (! $decider->is_active || ! $decider->hasPermissionTo(Permission::ApprovalsDecide->value)) {
            throw ApprovalException::notAllowed();
        }

        $comment = trim((string) $comment);

        $decided = DB::transaction(function () use ($request, $decider, $status, $comment): ApprovalRequest {
            $locked = ApprovalRequest::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($request->id);

            if (! $locked->isPending()) {
                throw ApprovalException::alreadyDecided($locked->status->label());
            }

            $locked->forceFill([
                'status' => $status,
                'decided_by' => $decider->id,
                'decided_at' => CarbonImmutable::now(),
                'comment' => $comment === '' ? null : $comment,
            ])->save();

            return $locked;
        });

        $warning = $this->effects->apply($decided, $decider);
        $this->notifier->decided($decided, $warning);

        return $decided->refresh();
    }
}
