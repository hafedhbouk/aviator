<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Actions;

use App\Domain\Approvals\ApprovalNotifier;
use App\Domain\Approvals\Exceptions\ApprovalException;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Ouvre une demande d'approbation au super admin (D46, RG-10, RG-15, P5).
 *
 * - Les données utiles à la décision sont figées dans `payload` (montants en chaînes
 *   décimales) ; le motif du demandeur est obligatoire.
 * - Une seule demande en attente par objet et par type : une nouvelle demande annule
 *   la précédente (remise modifiée avant la décision).
 * - Le super admin est notifié après la validation de la transaction, par un texte
 *   générique sans montant, nom ni motif (RG-20).
 */
final class RequestApproval
{
    public const MIN_REASON_LENGTH = 5;

    public function __construct(
        private readonly CancelPendingApprovals $cancel,
        private readonly ApprovalNotifier $notifier,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(ApprovalType $type, Model $subject, int $agencyId, array $payload, ?string $reason, User $requester): ApprovalRequest
    {
        $reason = trim((string) $reason);

        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            throw ApprovalException::reasonRequired(match ($type) {
                ApprovalType::Discount => 'une remise au-delà du seuil',
                ApprovalType::BlacklistOverride => 'une demande de dérogation',
            }, self::MIN_REASON_LENGTH);
        }

        return DB::transaction(function () use ($type, $subject, $agencyId, $payload, $reason, $requester): ApprovalRequest {
            $this->cancel->handle($subject, $type, 'Remplacée par une nouvelle demande.');

            $request = ApprovalRequest::query()->create([
                'agency_id' => $agencyId,
                'type' => $type,
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'payload' => $payload,
                'reason' => $reason,
                'requested_by' => $requester->id,
                'status' => ApprovalStatus::Pending,
            ]);

            DB::afterCommit(fn () => $this->notifier->requested($request));

            return $request;
        });
    }
}
