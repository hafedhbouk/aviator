<?php

declare(strict_types=1);

namespace App\Domain\Billing\ContractBilling;

use App\Domain\Availability\Period;
use App\Domain\Pricing\BillableDurationCalculator;
use App\Enums\InvoiceLineNature;
use App\Models\Contract;
use App\Models\ContractExtension;
use App\Models\ContractOption;
use App\Models\InvoiceLine;
use App\Models\Option;
use App\Models\TaxCode;
use App\Settings\BillingSettings;
use App\Support\Money\Money;
use Brick\Math\RoundingMode;

/**
 * Ce qu'un contrat donne à facturer (E-01, E-02, RG-05, Q50, D52, D54), lu uniquement
 * dans les données figées du contrat : devis, options, prolongations, frais du retour.
 *
 * - Jours facturés : ceux du devis à la création, puis ceux ajoutés par chaque
 *   prolongation, dans l'ordre. Au-delà de `billing.long_rental_period_days`, le contrat
 *   se découpe en périodes de cette durée ; la dernière va jusqu'à la date de retour.
 * - Remise : celle du contrat est répartie entre la location et ses options, celle d'une
 *   prolongation entre ses lignes, au prorata de leur montant (Q95, a).
 * - Part d'une période : jours exacts du devis pour la location, prorata des jours pour
 *   le reste (Q95, b) ; la période où un élément se termine reçoit son reste, si bien
 *   que la somme des factures égale toujours le contrat, au millime.
 * - Code fiscal proposé (D54) : `RENTAL` pour la location, le code de l'option,
 *   `EXTRA_RENTAL_PERIOD` pour une prolongation ; **aucun** pour un frais du retour.
 */
final class ContractBillingPlan
{
    public const RENTAL_TAX_CODE = 'RENTAL';

    public const EXTENSION_TAX_CODE = 'EXTRA_RENTAL_PERIOD';

    public function __construct(
        private readonly BillableDurationCalculator $duration,
        private readonly BillingSettings $settings,
    ) {}

    /**
     * @return list<BillingPeriod>
     */
    public function periods(Contract $contract): array
    {
        $total = $this->originalDays($contract) + (int) $contract->extensions()->sum('added_days');
        $length = max(1, $this->settings->long_rental_period_days);

        if ($total <= $length) {
            return [new BillingPeriod(0, 0, $total, $contract->starts_at, $contract->ends_at, true)];
        }

        $periods = [];

        for ($index = 0, $from = 0; $from < $total; $index++, $from += $length) {
            $to = min($total, $from + $length);
            $periods[] = new BillingPeriod(
                $index,
                $from,
                $to,
                $from === 0 ? $contract->starts_at : $contract->starts_at->addDays($from),
                $to === $total ? $contract->ends_at : $contract->starts_at->addDays($to),
                $to === $total,
            );
        }

        return $periods;
    }

    /**
     * @return list<BillableItem>
     */
    public function items(Contract $contract): array
    {
        $originalDays = $this->originalDays($contract);
        /** @var list<ContractExtension> $extensions */
        $extensions = $contract->extensions()->orderBy('id')->get()->all();

        $items = $this->originalItems($contract, $originalDays, $extensions);
        $from = $originalDays;

        foreach ($extensions as $extension) {
            $items = [...$items, ...$this->extensionItems($extension, $from)];
            $from += $extension->added_days;
        }

        foreach ($contract->charges()->orderBy('id')->get() as $charge) {
            if (! $charge->amount->isPositive()) {
                continue;
            }

            $unit = $charge->unit_amount->multipliedBy($charge->quantity, RoundingMode::HalfUp)->equals($charge->amount) ? $charge->unit_amount : null;

            $items[] = new BillableItem(
                InvoiceLineNature::Charge, InvoiceLine::SOURCE_CHARGE, $charge->id, null, $charge->label,
                $from, $from, $charge->amount, Money::zero(), $unit === null ? '1' : $charge->quantity, false, $unit ?? $charge->amount,
                null, finalOnly: true,
            );
        }

        return $items;
    }

    /**
     * Jours facturés à la création du contrat : ceux du devis figé, sinon recalculés
     * sur la période d'origine.
     */
    public function originalDays(Contract $contract): int
    {
        $days = $contract->quote['days'] ?? null;

        if (is_array($days) && $days !== []) {
            return count($days);
        }

        $firstExtension = $contract->extensions()->orderBy('id')->first();
        $originalEnd = $firstExtension instanceof ContractExtension ? $firstExtension->previous_ends_at : $contract->ends_at;

        return count($this->duration->days(new Period($contract->starts_at, $originalEnd)));
    }

    /**
     * Location et options d'origine, remise du contrat répartie.
     *
     * @param  list<ContractExtension>  $extensions
     * @return list<BillableItem>
     */
    private function originalItems(Contract $contract, int $days, array $extensions): array
    {
        $rental = $contract->rental_amount;
        $discount = $contract->discount_amount;

        foreach ($extensions as $extension) {
            $rental = $rental->minus($extension->added_rental_amount);
            $discount = $discount->minus($extension->discount_amount);
        }

        $lines = [[
            'nature' => InvoiceLineNature::Rental, 'type' => InvoiceLine::SOURCE_CONTRACT, 'id' => $contract->id, 'key' => 'rental',
            'label' => 'Location '.($contract->category->name ?? '').', contrat '.$contract->number,
            'to' => $days, 'gross' => $rental, 'quantity' => '1', 'per_day' => true, 'unit' => $contract->daily_rate_amount,
            'tax_code' => $this->taxCodeId(self::RENTAL_TAX_CODE), 'daily' => $this->dailyAmounts($contract, $days, $rental),
        ]];

        /** @var ContractOption $option */
        foreach ($contract->options()->with('option')->orderBy('id')->get() as $option) {
            $perDay = $option->option->pricing_unit === Option::PER_DAY;
            $lines[] = [
                'nature' => InvoiceLineNature::Option, 'type' => InvoiceLine::SOURCE_CONTRACT_OPTION, 'id' => $option->id, 'key' => null,
                'label' => $option->option->name, 'to' => $perDay ? $days : min(1, $days), 'gross' => $option->total_amount,
                'quantity' => (string) $option->quantity, 'per_day' => $perDay, 'unit' => $option->unit_amount,
                'tax_code' => $option->option->tax_code_id, 'daily' => null,
            ];
        }

        return $this->withDiscount($lines, $discount, 0);
    }

    /**
     * Location et options ajoutées par une prolongation, sa remise répartie.
     *
     * @return list<BillableItem>
     */
    private function extensionItems(ContractExtension $extension, int $from): array
    {
        $timezone = (string) config('app.timezone');
        $lines = [[
            'nature' => InvoiceLineNature::Extension, 'type' => InvoiceLine::SOURCE_EXTENSION, 'id' => $extension->id, 'key' => 'rental',
            'label' => 'Prolongation du '.$extension->previous_ends_at->timezone($timezone)->format('d/m/Y H:i')
                .' au '.$extension->new_ends_at->timezone($timezone)->format('d/m/Y H:i'),
            'to' => $extension->added_days, 'gross' => $extension->added_rental_amount, 'quantity' => '1', 'per_day' => true,
            'unit' => $extension->daily_rate_amount, 'tax_code' => $this->taxCodeId(self::EXTENSION_TAX_CODE), 'daily' => null,
        ]];

        foreach ($extension->options ?? [] as $option) {
            $lines[] = [
                'nature' => InvoiceLineNature::Extension, 'type' => InvoiceLine::SOURCE_EXTENSION, 'id' => $extension->id,
                'key' => 'option:'.$option['option_id'], 'label' => $option['label'].' (prolongation)', 'to' => $extension->added_days,
                'gross' => Money::of($option['amount']), 'quantity' => (string) $option['quantity'], 'per_day' => true,
                'unit' => Money::of($option['unit_amount']), 'tax_code' => Option::query()->whereKey($option['option_id'])->value('tax_code_id'),
                'daily' => null,
            ];
        }

        return $this->withDiscount($lines, $extension->discount_amount, $from);
    }

    /**
     * Remise répartie au prorata du montant de chaque ligne ; le millime restant va à
     * la ligne la plus élevée.
     *
     * @param  list<array{nature: InvoiceLineNature, type: string, id: int, key: string|null, label: string, to: int, gross: Money, quantity: string, per_day: bool, unit: Money, tax_code: mixed, daily: list<Money>|null}>  $lines
     * @return list<BillableItem>
     */
    private function withDiscount(array $lines, Money $discount, int $offset): array
    {
        $lines = array_values(array_filter($lines, fn (array $line): bool => $line['gross']->isPositive()));
        $total = array_reduce($lines, fn (Money $sum, array $line): Money => $sum->plus($line['gross']), Money::zero());
        $shares = array_fill(0, count($lines), Money::zero());

        if ($discount->isPositive() && $total->isPositive()) {
            $largest = 0;
            $allocated = Money::zero();

            foreach ($lines as $index => $line) {
                if ($line['gross']->isGreaterThan($lines[$largest]['gross'])) {
                    $largest = $index;
                }
            }

            foreach ($lines as $index => $line) {
                if ($index !== $largest) {
                    $shares[$index] = $discount->multipliedBy($line['gross']->toBigDecimal(), RoundingMode::Unnecessary)
                        ->dividedBy($total->toBigDecimal(), RoundingMode::HalfUp);
                    $allocated = $allocated->plus($shares[$index]);
                }
            }

            $shares[$largest] = $discount->minus($allocated);
        }

        $items = [];

        foreach ($lines as $index => $line) {
            $taxCode = $line['tax_code'];
            $items[] = new BillableItem(
                $line['nature'], $line['type'], $line['id'], $line['key'], $line['label'],
                $offset, $offset + $line['to'], $line['gross'], $shares[$index], $line['quantity'], $line['per_day'], $line['unit'],
                is_numeric($taxCode) ? (int) $taxCode : null, $line['daily'],
            );
        }

        return $items;
    }

    /**
     * Montant de chaque jour d'origine, repris du devis quand il correspond exactement à
     * la location (même nombre de jours, même total) ; sinon prorata.
     *
     * @return list<Money>|null
     */
    private function dailyAmounts(Contract $contract, int $days, Money $rental): ?array
    {
        $quoted = $contract->quote['days'] ?? null;

        if (! is_array($quoted) || count($quoted) !== $days) {
            return null;
        }

        $amounts = [];
        $sum = Money::zero();

        foreach ($quoted as $day) {
            if (! is_array($day) || ! isset($day['daily_amount']) || ! is_string($day['daily_amount'])) {
                return null;
            }

            $amounts[] = Money::of($day['daily_amount']);
            $sum = $sum->plus(end($amounts));
        }

        return $sum->equals($rental) ? $amounts : null;
    }

    private function taxCodeId(string $code): ?int
    {
        $id = TaxCode::query()->where('code', $code)->value('id');

        return is_numeric($id) ? (int) $id : null;
    }
}
