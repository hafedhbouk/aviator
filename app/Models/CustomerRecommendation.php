<?php

namespace App\Models;

use App\Enums\RecommendationLevel;
use App\Enums\RecommendationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Recommandation sur un client (K-08 à K-11), première version (phase 4, lot L8).
 * **Ajout seul** (RG-16) : une recommandation n'est jamais modifiée ni supprimée ; elle
 * est remplacée par une nouvelle. Le niveau en vigueur est celui de la dernière
 * recommandation effective. Le motif n'est visible que des profils autorisés (phase 7).
 *
 * @property int $id
 * @property int $customer_id
 * @property int $author_id
 * @property int|null $agency_id
 * @property int|null $contract_id
 * @property RecommendationLevel $level
 * @property string $reason_code
 * @property string|null $comment
 * @property RecommendationStatus $status
 * @property int|null $approval_request_id
 * @property CarbonImmutable $created_at
 */
class CustomerRecommendation extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['customer_id', 'author_id', 'agency_id', 'contract_id', 'level', 'reason_code', 'comment', 'status', 'approval_request_id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Recommandations en ajout seul (RG-16) : aucune modification.'));
        static::deleting(fn () => throw new LogicException('Recommandations en ajout seul (RG-16) : aucune suppression.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => RecommendationLevel::class,
            'status' => RecommendationStatus::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
