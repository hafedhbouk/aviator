<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Nature fiscale d'une opération (référentiel v1.0, §2.7) : taxable, exonérée ou hors
 * champ. Fixée par le code fiscal, jamais déduite d'un libellé.
 */
enum TaxNature: string
{
    case Taxable = 'taxable';
    case Exempt = 'exempt';
    case OutOfScope = 'out_of_scope';

    public function label(): string
    {
        return match ($this) {
            self::Taxable => 'Taxable',
            self::Exempt => 'Exonérée',
            self::OutOfScope => 'Hors champ',
        };
    }

    public function hasRate(): bool
    {
        return $this === self::Taxable;
    }
}
