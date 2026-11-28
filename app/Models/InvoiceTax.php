<?php

namespace App\Models;

use App\Enums\FiscalValidationStatus;
use App\Enums\TaxNature;
use App\Support\Billing\LocksWithInvoice;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Taxe d'une facture, par taux (référentiel §5) : copie figée de la règle appliquée
 * (`rule_snapshot`), base agrégée et TVA arrondie. Écrite à la validation (L4), puis
 * verrouillée avec la facture (LocksWithInvoice).
 *
 * @property int $id
 * @property int $invoice_id
 * @property int $tax_rule_id
 * @property int $tax_code_id
 * @property string $code
 * @property TaxNature $nature
 * @property string|null $rate
 * @property Money $base_amount
 * @property Money $tax_amount
 * @property FiscalValidationStatus $validation_status
 * @property array<string, mixed> $rule_snapshot
 * @property-read Invoice $invoice
 */
#[Fillable([
    'invoice_id', 'tax_rule_id', 'tax_code_id', 'code', 'nature', 'rate', 'base_amount', 'tax_amount',
    'validation_status', 'rule_snapshot',
])]
class InvoiceTax extends Model
{
    use LocksWithInvoice;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nature' => TaxNature::class,
            'base_amount' => MoneyCast::class,
            'tax_amount' => MoneyCast::class,
            'validation_status' => FiscalValidationStatus::class,
            'rule_snapshot' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
