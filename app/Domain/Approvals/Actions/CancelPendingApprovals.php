<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Actions;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Models\ApprovalRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Annule les demandes encore en attente sur un objet (remise modifiée, réservation
 * annulée ou expirée) : le super admin ne décide plus d'une demande devenue sans objet.
 * Une demande déjà tranchée n'est jamais touchée.
 */
final class CancelPendingApprovals
{
    /**
     * @return int nombre de demandes annulées
     */
    public function handle(Model $subject, ?ApprovalType $type, string $why): int
    {
        return DB::transaction(function () use ($subject, $type, $why): int {
            $pending = ApprovalRequest::query()->withoutGlobalScopes()
                ->where('subject_type', $subject->getMorphClass())
                ->where('subject_id', $subject->getKey())
                ->when($type !== null, fn ($query) => $query->where('type', $type?->value))
                ->where('status', ApprovalStatus::Pending->value)
                ->lockForUpdate()
                ->get();

            foreach ($pending as $request) {
                $request->forceFill([
                    'status' => ApprovalStatus::Cancelled,
                    'decided_at' => CarbonImmutable::now(),
                    'comment' => $why,
                ])->save();
            }

            return $pending->count();
        });
    }
}
