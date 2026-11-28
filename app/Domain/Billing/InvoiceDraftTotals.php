<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Support\Money\Money;

/**
 * Total hors taxes provisoire d'un brouillon : somme des lignes, remises déduites. La
 * TVA, le timbre et le net à payer sont calculés à la validation (L4, TaxCalculator).
 */
final class InvoiceDraftTotals
{
    public static function refresh(Invoice $invoice): void
    {
        $total = InvoiceLine::query()->where('invoice_id', $invoice->id)->get()
            ->reduce(fn (Money $sum, InvoiceLine $line): Money => $sum->plus($line->total_excl_tax_amount), Money::zero());

        $invoice->forceFill(['total_excl_tax_amount' => $total])->save();
    }
}
