<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Support\Agency\BelongsToAgency;
use App\Support\Audit\AuditsChanges;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Demande d'approbation au super admin (D46, P5) : remise au-delà du seuil (RG-10),
 * dérogation à la liste noire (RG-15). Écrite uniquement par App\Domain\Approvals.
 *
 * - Les données de la demande (type, objet, payload, motif, demandeur) sont figées à
 *   la création : le modèle refuse de les modifier.
 * - Une demande n'est jamais supprimée (RG-11) : elle est approuvée, refusée ou annulée.
 * - Document de l'agence de son objet (BelongsToAgency, RG-13).
 *
 * @property int $id
 * @property int $agency_id
 * @property ApprovalType $type
 * @property string $subject_type
 * @property int $subject_id
 * @property array<string, mixed> $payload
 * @property string $reason
 * @property int $requested_by
 * @property ApprovalStatus $status
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $comment
 * @property CarbonImmutable|null $created_at
 */
class ApprovalRequest extends Model
{
    use AuditsChanges;
    use BelongsToAgency;

    /** Colonnes figées à la création de la demande. */
    private const FROZEN = ['agency_id', 'type', 'subject_type', 'subject_id', 'payload', 'reason', 'requested_by'];

    protected $fillable = [
        'agency_id', 'type', 'subject_type', 'subject_id', 'payload', 'reason', 'requested_by',
        'status', 'decided_by', 'decided_at', 'comment',
    ];

    protected static function booted(): void
    {
        static::updating(function (ApprovalRequest $request): void {
            if ($request->isDirty(self::FROZEN)) {
                throw new LogicException('Les données d\'une demande d\'approbation ne se modifient pas.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Une demande d\'approbation ne se supprime pas.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ApprovalType::class,
            'status' => ApprovalStatus::class,
            'payload' => 'array',
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === ApprovalStatus::Pending;
    }

    public function isApproved(): bool
    {
        return $this->status === ApprovalStatus::Approved;
    }

    /**
     * Objet de la demande, lu sans portée par agence (le super admin décide pour toutes).
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo()->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
