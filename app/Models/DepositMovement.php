<?php

namespace App\Models;

use App\Support\Audit\AuditsChanges;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Mouvement d'une caution (F-03 à F-06) : restitution (reçu `CAU-` numéroté, mode,
 * caisse pour les espèces) ou retenue (motif, justificatif, facture de frais,
 * approbation du super admin, encaissement « retenue sur caution » qui règle la facture).
 * Ajout seul, écrit sous verrou de la caution.
 *
 * @property int $id
 * @property int $deposit_id
 * @property string $type « refund » ou « retention »
 * @property Money $amount
 * @property string|null $reason
 * @property string|null $file_path
 * @property string|null $file_sha256
 * @property int|null $invoice_id
 * @property int|null $approval_request_id
 * @property int|null $payment_id
 * @property int|null $payment_method_id
 * @property string|null $reference
 * @property int|null $cash_session_id
 * @property string|null $receipt_number
 * @property string|null $receipt_sequence_key
 * @property int|null $created_by
 * @property-read Deposit $deposit
 */
#[Fillable([
    'deposit_id', 'type', 'amount', 'reason', 'file_path', 'file_sha256', 'invoice_id', 'approval_request_id', 'payment_id',
    'payment_method_id', 'reference', 'cash_session_id', 'receipt_number', 'receipt_sequence_key', 'created_by',
])]
class DepositMovement extends Model
{
    use AuditsChanges;

    public const REFUND = 'refund';

    public const RETENTION = 'retention';

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Un mouvement de caution ne se modifie pas.'));
        static::deleting(fn () => throw new LogicException('Un mouvement de caution ne se supprime pas.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => MoneyCast::class];
    }

    /**
     * @return BelongsTo<Deposit, $this>
     */
    public function deposit(): BelongsTo
    {
        return $this->belongsTo(Deposit::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
