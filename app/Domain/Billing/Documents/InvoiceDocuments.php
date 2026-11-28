<?php

declare(strict_types=1);

namespace App\Domain\Billing\Documents;

use App\Models\Contract;
use App\Models\Invoice;
use App\Models\InvoiceTax;
use App\Support\Money\Money;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * PDF d'une facture (E-10, E8), par dompdf, comme les documents du contrat. Tout
 * vient de la facture validée : client et émetteur figés, lignes, taxes enregistrées,
 * totaux ; rien n'est recalculé. Hors production, mention « Règles fiscales non
 * confirmées » en filigrane et en pied de page (E6). Le gabarit complet (avoirs, reçus)
 * est au lot L13.
 */
final class InvoiceDocuments
{
    public function invoicePdf(Invoice $invoice): string
    {
        return Pdf::loadView('pdf.invoice', $this->viewData($invoice))
            ->setPaper('a4')
            ->setOption(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans', 'isFontSubsettingEnabled' => true])
            ->output();
    }

    /**
     * @return array<string, mixed>
     */
    public function viewData(Invoice $invoice): array
    {
        $invoice->loadMissing(['lines.taxCode', 'taxes']);

        return [
            'invoice' => $invoice,
            'issuer' => $invoice->issuer_snapshot ?? [],
            'customer' => $invoice->customer_snapshot ?? [],
            'contractNumber' => $invoice->contract_id === null ? null
                : Contract::query()->withoutGlobalScopes()->whereKey($invoice->contract_id)->value('number'),
            'rates' => $this->rates($invoice),
            'company' => null,
            'unvalidated' => false,
            'fiscalNotice' => ! app()->environment('production'),
        ];
    }

    /**
     * Bases et TVA par taux (référentiel §5), regroupées depuis les taxes enregistrées.
     *
     * @return list<array{label: string, base: Money, tax: Money}>
     */
    public function rates(Invoice $invoice): array
    {
        $rates = [];

        /** @var InvoiceTax $tax */
        foreach ($invoice->taxes as $tax) {
            $label = $tax->rate !== null
                ? 'TVA '.str_replace('.', ',', rtrim(rtrim($tax->rate, '0'), '.')).' %'
                : $tax->nature->label();
            $rates[$label] ??= ['label' => $label, 'base' => Money::zero(), 'tax' => Money::zero()];
            $rates[$label]['base'] = $rates[$label]['base']->plus($tax->base_amount);
            $rates[$label]['tax'] = $rates[$label]['tax']->plus($tax->tax_amount);
        }

        return array_values($rates);
    }
}
