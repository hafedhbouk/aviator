<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Tax\TaxBreakdown;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Devis figé d'une location (C-06) : enregistré tel quel avec la réservation
 * (`reservations.quote`, lot L11), pour que la facture (phase 6) reprenne le prix
 * convenu même si la grille change ensuite. Montants HT au millime, jamais de float.
 *
 * Les taxes ne sont calculées que si les règles fiscales applicables existent ; sinon
 * `taxes` est null et `taxNotice` l'explique (« règle fiscale non validée »).
 */
final readonly class PriceQuote
{
    /**
     * @param  list<array{date: string, season: string|null, daily_amount: Money}>  $days
     * @param  list<array{option_id: int, code: string, label: string, unit: string, quantity: int, unit_amount: Money, amount: Money, tax_code: string}>  $options
     */
    public function __construct(
        public int $vehicleCategoryId,
        public int $agencyId,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public array $days,
        public Money $rentalAmount,
        public array $options,
        public Money $optionsAmount,
        public Money $discountAmount,
        public Money $totalExcludingTax,
        public ?TaxBreakdown $taxes,
        public ?string $taxNotice,
        public CarbonImmutable $computedAt,
    ) {}

    public function billableDays(): int
    {
        return count($this->days);
    }

    /**
     * Forme figée pour la colonne JSON : montants en chaînes décimales, dates ISO.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => 1,
            'vehicle_category_id' => $this->vehicleCategoryId,
            'agency_id' => $this->agencyId,
            'starts_at' => $this->startsAt->toIso8601String(),
            'ends_at' => $this->endsAt->toIso8601String(),
            'billable_days' => $this->billableDays(),
            'days' => array_map(fn (array $day): array => [
                'date' => $day['date'],
                'season' => $day['season'],
                'daily_amount' => $day['daily_amount']->toDecimalString(),
            ], $this->days),
            'rental_amount' => $this->rentalAmount->toDecimalString(),
            'options' => array_map(fn (array $option): array => [
                'option_id' => $option['option_id'],
                'code' => $option['code'],
                'label' => $option['label'],
                'unit' => $option['unit'],
                'quantity' => $option['quantity'],
                'unit_amount' => $option['unit_amount']->toDecimalString(),
                'amount' => $option['amount']->toDecimalString(),
                'tax_code' => $option['tax_code'],
            ], $this->options),
            'options_amount' => $this->optionsAmount->toDecimalString(),
            'discount_amount' => $this->discountAmount->toDecimalString(),
            'total_excluding_tax' => $this->totalExcludingTax->toDecimalString(),
            'taxes' => $this->taxes === null ? null : [
                'total_vat' => $this->taxes->totalVat->toDecimalString(),
                'total_including_tax' => $this->taxes->totalIncludingTax->toDecimalString(),
                'stamp_duty' => $this->taxes->stampDuty->toDecimalString(),
                'net_to_pay' => $this->taxes->netToPay->toDecimalString(),
            ],
            'tax_notice' => $this->taxNotice,
            'computed_at' => $this->computedAt->toIso8601String(),
        ];
    }
}
