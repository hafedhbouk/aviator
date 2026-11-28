<?php

namespace App\Models;

use App\Domain\Billing\Exceptions\InvoiceLockedException;
use App\Enums\InvoiceStatus;
use App\Enums\JustificationKind;
use App\Support\Audit\AuditsChanges;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Justificatif rattaché à une ligne de facture (N2, D55) : dossier de dommage complet
 * ou pièce jointe privée avec son empreinte. Rattaché ou retiré seulement tant que la
 * facture est un brouillon ; jamais modifié. Écrit par App\Domain\Billing.
 *
 * @property int $id
 * @property int $invoice_line_id
 * @property JustificationKind $kind
 * @property int|null $damage_file_id
 * @property string|null $file_path
 * @property string|null $file_sha256
 * @property string|null $description
 * @property int|null $created_by
 * @property-read InvoiceLine $line
 * @property-read DamageFile|null $damageFile
 */
#[Fillable(['invoice_line_id', 'kind', 'damage_file_id', 'file_path', 'file_sha256', 'description', 'created_by'])]
class InvoiceLineJustification extends Model
{
    use AuditsChanges;

    protected static function booted(): void
    {
        static::creating(fn (self $justification) => self::assertDraft($justification->invoice_line_id));
        static::updating(fn () => throw InvoiceLockedException::lineLocked());
        static::deleting(fn (self $justification) => self::assertDraft($justification->invoice_line_id));
    }

    private static function assertDraft(int $lineId): void
    {
        $invoiceId = InvoiceLine::query()->whereKey($lineId)->value('invoice_id');
        $status = Invoice::query()->withoutGlobalScopes()->whereKey($invoiceId)->value('status');

        if ($status !== null && $status !== InvoiceStatus::Draft) {
            throw InvoiceLockedException::lineLocked();
        }
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['kind' => JustificationKind::class];
    }

    /**
     * @return BelongsTo<InvoiceLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(InvoiceLine::class, 'invoice_line_id');
    }

    /**
     * @return BelongsTo<DamageFile, $this>
     */
    public function damageFile(): BelongsTo
    {
        return $this->belongsTo(DamageFile::class);
    }
}
