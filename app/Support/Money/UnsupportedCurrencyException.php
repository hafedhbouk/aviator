<?php

declare(strict_types=1);

namespace App\Support\Money;

use InvalidArgumentException;

/**
 * Devise autre que le dinar tunisien (D26) : refusée sur les factures, avoirs,
 * encaissements et cautions. Le message s'affiche tel quel.
 */
final class UnsupportedCurrencyException extends InvalidArgumentException
{
    public static function for(?string $currency): self
    {
        return new self(sprintf(
            'Devise refusée : « %s ». Aviator facture et encaisse uniquement en dinars tunisiens (%s).',
            $currency ?? '',
            Money::CURRENCY,
        ));
    }

    /**
     * Refuse toute devise autre que TND.
     */
    public static function assertTnd(?string $currency): void
    {
        if ($currency !== Money::CURRENCY) {
            throw self::for($currency);
        }
    }
}
