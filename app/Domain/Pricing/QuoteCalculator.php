<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Availability\Period;
use App\Domain\Pricing\Exceptions\QuoteUnavailable;
use App\Domain\Setup\RateLines;
use App\Domain\Setup\RateResolver;
use App\Domain\Tax\Exceptions\TaxRuleUnavailable;
use App\Domain\Tax\TaxBreakdown;
use App\Domain\Tax\TaxCalculator;
use App\Domain\Tax\TaxLine;
use App\Models\Option;
use App\Models\VehicleCategory;
use App\Settings\ApprovalSettings;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Devis d'une location (C-06, Q32, Q33, D30, D36), calculé côté serveur uniquement
 * (RG-18 : l'espace client du lot 2 l'appellera tel quel).
 *
 * 1. Durée facturée (BillableDurationCalculator).
 * 2. Prix de chaque jour : grille de la catégorie pour **la saison de ce jour**, grille
 *    de l'agence de départ prioritaire sur la grille commune, tranche de la **durée
 *    totale** (tarif dégressif). Un jour sans grille : devis refusé, jamais à zéro.
 * 3. Options actives avec un prix : par jour × jours facturés, ou par location ;
 *    plafond par location et par unité ; × quantité.
 * 4. Remise HT : au plus le sous-total ; au-delà du seuil sans approbation
 *    (`approvals.discount_threshold_percent`, 0 % par défaut), refus (D36).
 * 5. Taxes du référentiel validé (TaxCalculator) : location en `RENTAL`, chaque option
 *    sous son code fiscal, remise répartie au prorata ; sans règle applicable, devis
 *    en HT seulement, avec la mention « règle fiscale non validée ».
 */
final class QuoteCalculator
{
    public const RENTAL_TAX_CODE = 'RENTAL';

    public const TAX_NOTICE = 'Taxes : règle fiscale non validée. Montant hors taxes seulement.';

    public function __construct(
        private readonly BillableDurationCalculator $duration,
        private readonly RateResolver $rates,
        private readonly TaxCalculator $taxes,
        private readonly ApprovalSettings $approvals,
    ) {}

    /**
     * @param  array<int, int>  $options  identifiant de l'option => quantité
     * @param  bool  $allowApproval  vrai pour un enregistrement qui sait ouvrir une demande
     *                               d'approbation (D46) : une remise au-delà du seuil est
     *                               alors acceptée et marquée `discountRequiresApproval` ;
     *                               sinon elle est refusée
     */
    public function quote(int $categoryId, int $agencyId, Period $period, array $options = [], ?Money $discount = null, bool $allowApproval = false): PriceQuote
    {
        $category = VehicleCategory::query()->findOrFail($categoryId);

        if (! $category->is_active) {
            throw QuoteUnavailable::inactiveCategory($category->name);
        }

        $billableDays = $this->duration->days($period);
        $count = count($billableDays);

        $days = [];
        $rental = Money::zero();

        foreach ($billableDays as $day) {
            $plan = $this->rates->planFor($category, $agencyId, $day);
            $daily = $plan === null ? null : RateLines::fromInput($plan->lines->map(fn ($line): array => [
                'min_days' => $line->min_days,
                'max_days' => $line->max_days,
                'daily_amount' => $line->daily_amount,
            ])->all())->dailyAmountFor($count);

            if ($daily === null) {
                throw QuoteUnavailable::noRate($category->name, $day->format('d/m/Y'));
            }

            $days[] = ['date' => $day->toDateString(), 'season' => $plan->season?->name, 'daily_amount' => $daily];
            $rental = $rental->plus($daily);
        }

        [$optionLines, $optionsAmount] = $this->options($options, $count);

        $subtotal = $rental->plus($optionsAmount);
        $discount ??= Money::zero();
        $requiresApproval = $this->assertDiscount($discount, $subtotal, $allowApproval);
        $total = $subtotal->minus($discount);

        [$taxes, $notice] = $this->taxes($rental, $optionLines, $subtotal, $discount, $period->startsAt);

        return new PriceQuote(
            vehicleCategoryId: $category->id,
            agencyId: $agencyId,
            startsAt: $period->startsAt,
            endsAt: $period->endsAt,
            days: $days,
            rentalAmount: $rental,
            options: $optionLines,
            optionsAmount: $optionsAmount,
            discountAmount: $discount,
            totalExcludingTax: $total,
            taxes: $taxes,
            taxNotice: $notice,
            computedAt: CarbonImmutable::now(),
            discountRequiresApproval: $requiresApproval,
        );
    }

    /**
     * @param  array<int, int>  $requested
     * @return array{0: list<array{option_id: int, code: string, label: string, unit: string, quantity: int, unit_amount: Money, amount: Money, tax_code: string}>, 1: Money}
     */
    private function options(array $requested, int $days): array
    {
        $lines = [];
        $total = Money::zero();

        if ($requested === []) {
            return [$lines, $total];
        }

        $options = Option::query()->with('taxCode')->whereIn('id', array_keys($requested))->get()->keyBy('id');

        foreach ($requested as $optionId => $quantity) {
            $option = $options->get($optionId);

            if ($option === null || ! $option->is_active || $option->amount === null || $option->taxCode === null) {
                throw QuoteUnavailable::optionUnavailable($option->name ?? "n° {$optionId}");
            }

            if ($quantity < 1 || $quantity > 10) {
                throw QuoteUnavailable::invalidQuantity($option->name);
            }

            $perUnit = $option->pricing_unit === 'per_day'
                ? $option->amount->multipliedBy($days, RoundingMode::Unnecessary)
                : $option->amount;

            if ($option->max_amount_per_rental !== null && $perUnit->compareTo($option->max_amount_per_rental) > 0) {
                $perUnit = $option->max_amount_per_rental;
            }

            $amount = $perUnit->multipliedBy($quantity, RoundingMode::Unnecessary);

            $lines[] = [
                'option_id' => $option->id,
                'code' => $option->code,
                'label' => $option->name,
                'unit' => $option->pricing_unit,
                'quantity' => $quantity,
                'unit_amount' => $option->amount,
                'amount' => $amount,
                'tax_code' => $option->taxCode->code,
            ];
            $total = $total->plus($amount);
        }

        return [$lines, $total];
    }

    /**
     * @return bool vrai si la remise dépasse le seuil et attend l'approbation du super admin
     */
    private function assertDiscount(Money $discount, Money $subtotal, bool $allowApproval): bool
    {
        if ($discount->isNegative() || $discount->compareTo($subtotal) > 0) {
            throw QuoteUnavailable::discountTooHigh();
        }

        if ($discount->isZero()) {
            return false;
        }

        $threshold = BigDecimal::of($this->approvals->discount_threshold_percent);
        $allowed = $subtotal->multipliedBy($threshold->dividedBy(100, 6, RoundingMode::HalfUp), RoundingMode::HalfUp);

        if ($discount->compareTo($allowed) <= 0) {
            return false;
        }

        if ($allowApproval) {
            return true;
        }

        throw QuoteUnavailable::discountAboveThreshold($this->thresholdLabel());
    }

    /**
     * Remise au-delà du seuil accordé sans approbation (RG-10), sur une base donnée :
     * prolongation d'un contrat (Q93), par exemple.
     */
    public function discountNeedsApproval(Money $discount, Money $base): bool
    {
        return $this->assertDiscount($discount, $base, allowApproval: true);
    }

    /** Seuil affiché sans zéros inutiles (« 10 », « 7,5 »). */
    public function thresholdLabel(): string
    {
        $shown = (string) BigDecimal::of($this->approvals->discount_threshold_percent);

        return str_contains($shown, '.') ? str_replace('.', ',', rtrim(rtrim($shown, '0'), '.')) : $shown;
    }

    /**
     * @param  list<array{amount: Money, tax_code: string}>  $optionLines
     * @return array{0: TaxBreakdown|null, 1: string|null}
     */
    private function taxes(Money $rental, array $optionLines, Money $subtotal, Money $discount, CarbonImmutable $date): array
    {
        $bases = [['code' => self::RENTAL_TAX_CODE, 'amount' => $rental]];
        foreach ($optionLines as $line) {
            $bases[] = ['code' => $line['tax_code'], 'amount' => $line['amount']];
        }

        // Remise répartie au prorata des lignes ; l'arrondi va sur la dernière.
        if (! $discount->isZero() && ! $subtotal->isZero()) {
            $remaining = $discount;
            foreach ($bases as $i => $base) {
                $share = $i === array_key_last($bases)
                    ? $remaining
                    : $discount->multipliedBy(
                        BigDecimal::of($base['amount']->toDecimalString())->dividedBy($subtotal->toDecimalString(), 10, RoundingMode::HalfUp),
                        RoundingMode::HalfUp,
                    );
                $bases[$i]['amount'] = $base['amount']->minus($share);
                $remaining = $remaining->minus($share);
            }
        }

        $lines = array_map(fn (array $base): TaxLine => new TaxLine($base['code'], $base['amount']), $bases);

        try {
            return [$this->taxes->calculate($lines, $date), null];
        } catch (TaxRuleUnavailable) {
            return [null, self::TAX_NOTICE];
        }
    }
}
