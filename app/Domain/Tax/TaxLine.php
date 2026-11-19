<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use App\Support\Money\Money;

/**
 * Ligne à taxer : un **code fiscal** et un montant HT au millime. Volontairement sans
 * libellé : le traitement fiscal ne dépend jamais de la désignation (référentiel
 * v1.0, §2.2 et §2.7).
 */
final readonly class TaxLine
{
    public function __construct(
        public string $taxCode,
        public Money $amountExcludingTax,
    ) {}
}
