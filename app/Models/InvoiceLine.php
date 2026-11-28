<?php

namespace App\Models;

use App\Enums\InvoiceLineNature;
use App\Support\Audit\AuditsChanges;
use App\Support\Billing\LocksWithInvoice;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne d'une facture (L3) : modifiable seulement au brouillon (LocksWithInvoice).
 * Le code fiscal est **choisi** pour chaque ligne, jamais déduit du libellé ni de la
 * nature (N2, D54) ; il est obligatoire à la validation (L4).
 *
 * @property int $id
 * @property int $invoice_id
 * @property int $position
 * @property InvoiceLineNature $nature
 * @property string|null $source_type
 * @property int|null $source_id
 * @property int|null $tax_code_id
 * @property string $label
 * @property string $quantity
 * @property Money $unit_amount_excl_tax
 * @property Money $discount_amount
 * @property Money $total_excl_tax_amount
 * @property-read Invoice $invoice
 * @property-read TaxCode|null $taxCode
 */
#[Fillable([
    'invoice_id', 'position', 'nature', 'source_type', 'source_id', 'tax_code_id', 'label', 'quantity',
    'unit_amount_excl_tax', 'discount_amount', 'total_excl_tax_amount',
])]
class InvoiceLine extends Model
{
    use AuditsChanges;
    use LocksWithInvoice;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'nature' => InvoiceLineNature::class,
            'source_id' => 'integer',
            'unit_amount_excl_tax' => MoneyCast::class,
            'discount_amount' => MoneyCast::class,
            'total_excl_tax_amount' => MoneyCast::class,
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
     * @return BelongsTo<TaxCode, $this>
     */
    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class);
    }
}
