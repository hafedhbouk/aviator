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
 * Imputation d'un encaissement sur une facture (E-06, Q81). Ajout seul, écrite sous
 * verrou par App\Domain\Billing ; ne compte plus quand l'encaissement est annulé.
 *
 * @property int $id
 * @property int $payment_id
 * @property int $invoice_id
 * @property Money $amount
 * @property int|null $created_by
 * @property-read Payment $payment
 * @property-read Invoice $invoice
 */
#[Fillable(['payment_id', 'invoice_id', 'amount', 'created_by'])]
class PaymentAllocation extends Model
{
    use AuditsChanges;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Une imputation ne se modifie pas : l\'encaissement s\'annule avec un motif.'));
        static::deleting(fn () => throw new LogicException('Une imputation ne se supprime pas : l\'encaissement s\'annule avec un motif.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => MoneyCast::class];
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
