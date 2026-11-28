<?php

namespace App\Models;

use App\Support\Audit\AuditsChanges;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Règlement d'un avoir (E19) : remboursement (décaissement tracé, mode et référence) ou
 * imputation sur une facture du même client. Ajout seul. Écrit uniquement par
 * App\Domain\Billing (SettleCreditNote), sous verrou de l'avoir.
 *
 * @property int $id
 * @property int $credit_note_id
 * @property string $kind « refund » ou « allocation »
 * @property Money $amount
 * @property int|null $invoice_id
 * @property int|null $payment_method_id
 * @property string|null $reference
 * @property CarbonImmutable $settled_on
 * @property int|null $created_by
 * @property-read Invoice $creditNote
 * @property-read Invoice|null $invoice
 */
#[Fillable(['credit_note_id', 'kind', 'amount', 'invoice_id', 'payment_method_id', 'reference', 'settled_on', 'created_by'])]
class CreditNoteSettlement extends Model
{
    use AuditsChanges;

    public const REFUND = 'refund';

    public const ALLOCATION = 'allocation';

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Un règlement d\'avoir ne se modifie pas.'));
        static::deleting(fn () => throw new LogicException('Un règlement d\'avoir ne se supprime pas.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'settled_on' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'credit_note_id');
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }
}
