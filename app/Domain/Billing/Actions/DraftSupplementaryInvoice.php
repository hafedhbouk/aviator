<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\ContractBilling\ContractBillingPlan;
use App\Domain\Billing\Exceptions\InvoiceDraftException;
use App\Domain\Billing\InvoiceDraftTotals;
use App\Enums\ContractStatus;
use App\Enums\InvoiceType;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Brouillon de facture complémentaire (E-05, Q87 c) : retard, dommages ou kilomètres
 * constatés après la facture de location. Série des factures `AVI-` (D27-5) ; validée
 * et émise comme une facture (L4), timbre compris.
 *
 * Seulement pour un contrat restitué ou clôturé dont toute la location est déjà
 * facturée. Reprend les frais du retour encore non facturés, sans code fiscal proposé
 * (D54) ; le reste se saisit en lignes libres, chacune avec son code choisi et, s'il le
 * faut, son justificatif (dossier de dommage complet, N2). Sous verrou du contrat : un
 * frais n'est jamais facturé deux fois.
 */
final class DraftSupplementaryInvoice
{
    public function __construct(private readonly ContractBillingPlan $plan) {}

    public function handle(Contract $contract, User $actor): Invoice
    {
        if (! $actor->can('createForContract', [Invoice::class, $contract])) {
            throw InvoiceDraftException::notAllowed();
        }

        return DB::transaction(function () use ($contract, $actor): Invoice {
            $locked = Contract::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($contract->id);

            if ($locked->status !== ContractStatus::Returned && $locked->status !== ContractStatus::Closed) {
                throw InvoiceDraftException::supplementaryNeedsReturn($locked->status->label());
            }

            $rentals = DraftContractInvoice::billedInvoices($locked);

            foreach ($this->plan->periods($locked) as $period) {
                if (! $rentals->contains(fn (Invoice $invoice): bool => $invoice->period_start?->equalTo($period->startsAt) === true)) {
                    throw InvoiceDraftException::rentalNotBilled(DraftContractInvoice::label($period));
                }
            }

            $invoice = Invoice::query()->create([
                'agency_id' => $locked->agency_id,
                'type' => InvoiceType::Supplementary,
                'contract_id' => $locked->id,
                'customer_id' => $locked->customer_id,
                'created_by' => $actor->id,
            ]);

            $already = DraftContractInvoice::billedAmounts(
                DraftContractInvoice::billedInvoices($locked, InvoiceType::Supplementary)->merge($rentals),
            );
            $position = 0;

            foreach ($this->plan->items($locked) as $item) {
                if (! $item->finalOnly) {
                    continue;
                }

                $remaining = $item->gross->minus(($already[$item->key()] ?? [Money::zero()])[0]);

                if (! $remaining->isPositive()) {
                    continue;
                }

                $whole = $remaining->equals($item->gross) && $item->unitAmount !== null;

                InvoiceLine::query()->create([
                    'invoice_id' => $invoice->id,
                    'position' => ++$position,
                    'nature' => $item->nature,
                    'source_type' => $item->sourceType,
                    'source_id' => $item->sourceId,
                    'source_key' => $item->sourceKey,
                    'tax_code_id' => null,
                    'label' => $item->label,
                    'quantity' => $whole ? (string) BigDecimal::of($item->quantityPerDay)->toScale(3) : '1.000',
                    'unit_amount_excl_tax' => $whole ? $item->unitAmount : $remaining,
                    'discount_amount' => Money::zero(),
                    'total_excl_tax_amount' => $remaining,
                ]);
            }

            InvoiceDraftTotals::refresh($invoice);

            return $invoice->refresh();
        });
    }
}
