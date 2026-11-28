<?php

namespace App\Models;

use App\Enums\ReminderChannel;
use App\Support\Audit\AuditsChanges;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Relance d'une facture impayée (E-08, Q53) : canal, date, issue, auteur. Ajout seul.
 * Écrite uniquement par App\Domain\Billing\Payments\RecordReminder.
 *
 * @property int $id
 * @property int $invoice_id
 * @property ReminderChannel $channel
 * @property CarbonImmutable $reminded_at
 * @property string|null $outcome
 * @property int|null $created_by
 * @property-read Invoice $invoice
 * @property-read User|null $author
 */
#[Fillable(['invoice_id', 'channel', 'reminded_at', 'outcome', 'created_by'])]
class PaymentReminder extends Model
{
    use AuditsChanges;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Une relance enregistrée ne se modifie pas.'));
        static::deleting(fn () => throw new LogicException('Une relance enregistrée ne se supprime pas.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => ReminderChannel::class,
            'reminded_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
