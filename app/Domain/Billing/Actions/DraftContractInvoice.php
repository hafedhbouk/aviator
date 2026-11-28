<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\ContractBilling\BillableItem;
use App\Domain\Billing\ContractBilling\BillingPeriod;
use App\Domain\Billing\ContractBilling\ContractBillingPlan;
use App\Domain\Billing\Exceptions\InvoiceDraftException;
use App\Domain\Billing\InvoiceDraftTotals;
use App\Enums\ContractStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Brouillon de facture de location depuis un contrat (E-01, E-02, RG-05, Q50, D52, D54) :
 * la prochaine période non facturée du contrat, avec ses lignes reprises du contrat
 * (location, options, prolongations, et frais du retour sur la dernière période).
 *
 * Sous verrou du contrat : deux brouillons simultanés ne facturent jamais la même
 * période. Une facture annulée (Q79) libère sa période ; un brouillon la retient.
 * Les périodes se facturent dans l'ordre ; la dernière seulement au retour du véhicule.
 * Aucun numéro au brouillon (RG-07) : il vient à la validation (L4).
 */
final class DraftContractInvoice
{
    public function __construct(private readonly ContractBillingPlan $plan) {}

    public function handle(Contract $contract, User $actor): Invoice
    {
        if (! $actor->can('createForContract', [Invoice::class, $contract])) {
            throw InvoiceDraftException::notAllowed();
        }

        return DB::transaction(function () use ($contract, $actor): Invoice {
            $locked = Contract::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($contract->id);

            if ($locked->status !== ContractStatus::Active && $locked->status !== ContractStatus::Returned) {
                throw InvoiceDraftException::contractNotBillable($locked->status->label());
            }

            $billed = self::billedInvoices($locked);
            $period = $this->nextPeriod($locked, $billed);

            if ($period->isLast && $locked->status !== ContractStatus::Returned) {
                throw InvoiceDraftException::notReturned();
            }

            $invoice = Invoice::query()->create([
                'agency_id' => $locked->agency_id,
                'type' => InvoiceType::Invoice,
                'contract_id' => $locked->id,
                'customer_id' => $locked->customer_id,
                'period_start' => $period->startsAt,
                'period_end' => $period->endsAt,
                'created_by' => $actor->id,
            ]);

            $already = self::billedAmounts($billed);
            $position = 0;

            foreach ($this->plan->items($locked) as $item) {
                $share = $this->share($item, $period, $already[$item->key()] ?? null);

                if ($share === null) {
                    continue;
                }

                [$gross, $discount] = $share;
                [$quantity, $unit] = $this->quantity($item, $gross, $period);

                InvoiceLine::query()->create([
                    'invoice_id' => $invoice->id,
                    'position' => ++$position,
                    'nature' => $item->nature,
                    'source_type' => $item->sourceType,
                    'source_id' => $item->sourceId,
                    'source_key' => $item->sourceKey,
                    'tax_code_id' => $item->proposedTaxCodeId,
                    'label' => $item->label,
                    'quantity' => $quantity,
                    'unit_amount_excl_tax' => $unit,
                    'discount_amount' => $discount,
                    'total_excl_tax_amount' => $gross->minus($discount),
                ]);
            }

            InvoiceDraftTotals::refresh($invoice);

            return $invoice->refresh();
        });
    }

    /**
     * Factures de location du contrat qui retiennent leur période : toutes sauf les
     * annulées (Q79). Les factures complémentaires et les avoirs n'en retiennent pas.
     *
     * @return Collection<int, Invoice>
     */
    public static function billedInvoices(Contract $contract): Collection
    {
        return Invoice::query()->withoutGlobalScopes()
            ->where('contract_id', $contract->id)
            ->where('type', InvoiceType::Invoice)
            ->where('status', '!=', InvoiceStatus::Cancelled)
            ->get();
    }

    /**
     * Première période sans facture ; refus si elle chevauche une période facturée.
     *
     * @param  Collection<int, Invoice>  $billed
     */
    private function nextPeriod(Contract $contract, Collection $billed): BillingPeriod
    {
        foreach ($this->plan->periods($contract) as $period) {
            if ($billed->contains(fn (Invoice $invoice): bool => $invoice->period_start?->equalTo($period->startsAt) === true)) {
                continue;
            }

            foreach ($billed as $invoice) {
                if ($invoice->period_start !== null && $invoice->period_end !== null
                    && $invoice->period_start->lessThan($period->endsAt) && $invoice->period_end->greaterThan($period->startsAt)) {
                    throw InvoiceDraftException::periodOverlap(self::label($period));
                }
            }

            return $period;
        }

        throw InvoiceDraftException::fullyBilled();
    }

    /**
     * Montant brut et remise déjà facturés, par élément du contrat.
     *
     * @param  Collection<int, Invoice>  $billed
     * @return array<string, array{0: Money, 1: Money}>
     */
    private static function billedAmounts(Collection $billed): array
    {
        $amounts = [];

        if ($billed->isEmpty()) {
            return $amounts;
        }

        $lines = InvoiceLine::query()->whereIn('invoice_id', $billed->modelKeys())->whereNotNull('source_type')->get();

        foreach ($lines as $line) {
            $key = BillableItem::keyOf((string) $line->source_type, (int) $line->source_id, $line->source_key);
            [$gross, $discount] = $amounts[$key] ?? [Money::zero(), Money::zero()];
            $amounts[$key] = [
                $gross->plus($line->total_excl_tax_amount)->plus($line->discount_amount),
                $discount->plus($line->discount_amount),
            ];
        }

        return $amounts;
    }

    /**
     * Part de l'élément pour la période : son reste s'il s'y termine (la somme des
     * factures égale alors le contrat), sinon les jours exacts du devis ou le prorata
     * des jours. Un frais va sur la dernière période.
     *
     * @param  array{0: Money, 1: Money}|null  $already
     * @return array{0: Money, 1: Money}|null brut, remise
     */
    private function share(BillableItem $item, BillingPeriod $period, ?array $already): ?array
    {
        [$billedGross, $billedDiscount] = $already ?? [Money::zero(), Money::zero()];

        if ($item->finalOnly) {
            $gross = $period->isLast ? $item->gross->minus($billedGross) : Money::zero();
            $discount = $item->discount->minus($billedDiscount);
        } elseif ($item->overlap($period->fromDay, $period->toDay) === 0) {
            return null;
        } elseif ($item->toDay <= $period->toDay) {
            $gross = $item->gross->minus($billedGross);
            $discount = $item->discount->minus($billedDiscount);
        } else {
            $days = $item->overlap($period->fromDay, $period->toDay);
            $gross = $this->dailyShare($item, $period) ?? $this->prorata($item->gross, $days, $item->days());
            $discount = $this->prorata($item->discount, $days, $item->days());
        }

        return $gross->isPositive() ? [$gross, $discount] : null;
    }

    private function dailyShare(BillableItem $item, BillingPeriod $period): ?Money
    {
        if ($item->dailyAmounts === null) {
            return null;
        }

        $from = max($item->fromDay, $period->fromDay) - $item->fromDay;
        $to = min($item->toDay, $period->toDay) - $item->fromDay;

        return array_reduce(array_slice($item->dailyAmounts, $from, $to - $from), fn (Money $sum, Money $day): Money => $sum->plus($day), Money::zero());
    }

    private function prorata(Money $amount, int $days, int $total): Money
    {
        return $amount->multipliedBy($days, RoundingMode::Unnecessary)->dividedBy(max(1, $total), RoundingMode::HalfUp);
    }

    /**
     * Quantité et prix unitaire affichés : quantité × prix = montant brut, toujours. Au
     * prix du contrat quand il tombe juste, sinon au prix moyen exact, sinon 1 × montant.
     *
     * @return array{0: string, 1: Money}
     */
    private function quantity(BillableItem $item, Money $gross, BillingPeriod $period): array
    {
        $quantity = BigDecimal::of($item->quantityPerDay);

        if ($item->perDay) {
            $quantity = $quantity->multipliedBy($item->overlap($period->fromDay, $period->toDay));
        }

        if ($quantity->isPositive()) {
            if ($item->unitAmount !== null && $item->unitAmount->multipliedBy($quantity, RoundingMode::HalfUp)->equals($gross)) {
                return [(string) $quantity->toScale(3), $item->unitAmount];
            }

            $unit = $gross->toBigDecimal()->dividedBy($quantity, 3, RoundingMode::Down);

            if ($unit->multipliedBy($quantity)->isEqualTo($gross->toBigDecimal())) {
                return [(string) $quantity->toScale(3), Money::of($unit)];
            }
        }

        return ['1.000', $gross];
    }

    public static function label(BillingPeriod $period): string
    {
        $timezone = (string) config('app.timezone');

        return $period->startsAt->timezone($timezone)->format('d/m/Y H:i').' au '.$period->endsAt->timezone($timezone)->format('d/m/Y H:i');
    }
}
