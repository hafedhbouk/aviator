<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use App\Domain\Tax\Exceptions\TaxRuleUnavailable;
use App\Enums\TaxNature;
use App\Settings\BillingSettings;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeInterface;

/**
 * Calcul des taxes d'un document, selon la méthode du référentiel v1.0 (§5) :
 *
 * 1. montants HT des lignes au millime ;
 * 2. regroupement par taux de TVA (les lignes exonérées et hors champ forment chacune
 *    une base sans TVA) ;
 * 3. TVA calculée sur chaque base agrégée, arrondie à 3 décimales selon le réglage
 *    billing.vat_rounding_mode (Q86) ;
 * 4. total TTC ;
 * 5. timbre fiscal ajouté après le TTC (facture ordinaire) ;
 * 6. net à payer.
 *
 * Les règles viennent de TaxRuleResolver (code exact, jamais un libellé). Ce service
 * sert au rapport de configuration fiscale et sera réutilisé tel quel par les
 * factures (phase 6). Il ne vérifie pas les justificatifs des règles CONDITIONAL :
 * c'est le rôle de la validation de la facture (phase 6).
 */
final class TaxCalculator
{
    public function __construct(
        private readonly TaxRuleResolver $rules,
        private readonly StampDutyResolver $stamps,
        private readonly BillingSettings $settings,
    ) {}

    /**
     * @param  list<TaxLine>  $lines
     */
    public function calculate(array $lines, DateTimeInterface $date, bool $withStampDuty = true): TaxBreakdown
    {
        if ($lines === []) {
            throw TaxRuleUnavailable::noLines();
        }

        /** @var array<string, array{nature: string, rate: string|null, base: Money}> $groups */
        $groups = [];

        foreach ($lines as $line) {
            $rule = $this->rules->resolve($line->taxCode, $date);
            $nature = $rule->taxCode->nature;
            // « 19.0000 » → « 19 » : clé de regroupement et affichage, sans float.
            $rate = $nature === TaxNature::Taxable && $rule->rate !== null
                ? rtrim(rtrim($rule->rate, '0'), '.')
                : null;
            $key = $rate !== null ? "rate:{$rate}" : "nature:{$nature->value}";

            $groups[$key] ??= ['nature' => $nature->value, 'rate' => $rate, 'base' => Money::zero()];
            $groups[$key]['base'] = $groups[$key]['base']->plus($line->amountExcludingTax);
        }

        return $this->fromBases(array_values($groups), $withStampDuty ? $this->stamps->standardAmount($date) : Money::zero());
    }

    /**
     * Étapes 3 à 6 de la méthode (§5) sur des bases déjà regroupées : TVA de chaque base
     * arrondie selon le réglage, TTC, timbre, net à payer. Sert aussi aux avoirs, calculés
     * aux taux figés de la facture d'origine.
     *
     * @param  list<array{nature: string, rate: string|null, base: Money}>  $groups
     */
    public function fromBases(array $groups, Money $stamp): TaxBreakdown
    {
        $rounding = $this->roundingMode();
        $bases = [];
        $totalHt = Money::zero();
        $totalVat = Money::zero();

        foreach ($groups as $group) {
            $vat = $group['rate'] === null
                ? Money::zero()
                : $group['base']->multipliedBy(BigDecimal::of($group['rate'])->dividedByExact(100), $rounding);

            $bases[] = $group + ['vat' => $vat];
            $totalHt = $totalHt->plus($group['base']);
            $totalVat = $totalVat->plus($vat);
        }

        $totalTtc = $totalHt->plus($totalVat);

        return new TaxBreakdown(
            bases: $bases,
            totalExcludingTax: $totalHt,
            totalVat: $totalVat,
            totalIncludingTax: $totalTtc,
            stampDuty: $stamp,
            netToPay: $totalTtc->plus($stamp),
        );
    }

    private function roundingMode(): RoundingMode
    {
        return match ($this->settings->vat_rounding_mode) {
            'half_even' => RoundingMode::HalfEven,
            default => RoundingMode::HalfUp,
        };
    }
}
