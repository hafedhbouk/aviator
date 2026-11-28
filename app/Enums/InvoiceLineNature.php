<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Origine d'une ligne de facture (L3). La nature ne fixe jamais le code fiscal : il
 * est choisi pour chaque ligne (N2, D54).
 */
enum InvoiceLineNature: string
{
    case Rental = 'rental';
    case Option = 'option';
    case Extension = 'extension';
    case Charge = 'charge';
    case Free = 'free';

    public function label(): string
    {
        return match ($this) {
            self::Rental => 'Location',
            self::Option => 'Option',
            self::Extension => 'Prolongation',
            self::Charge => 'Frais',
            self::Free => 'Ligne libre',
        };
    }
}
