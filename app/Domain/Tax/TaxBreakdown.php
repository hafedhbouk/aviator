<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use App\Support\Money\Money;

/**
 * Résultat du calcul (référentiel v1.0, §5) : bases agrégées par taux (et, sans taux,
 * par nature : exonéré, hors champ), TVA de chaque base arrondie à 3 décimales, total
 * HT, total TVA, TTC, timbre ajouté après le TTC, net à payer.
 */
final readonly class TaxBreakdown
{
    /**
     * @param  list<array{nature: string, rate: string|null, base: Money, vat: Money}>  $bases
     */
    public function __construct(
        public array $bases,
        public Money $totalExcludingTax,
        public Money $totalVat,
        public Money $totalIncludingTax,
        public Money $stampDuty,
        public Money $netToPay,
    ) {}
}
