<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Motif de sortie du parc (B-10, B-14).
 */
enum RetirementReason: string
{
    case Sale = 'sale';
    case Scrap = 'scrap';
    case LeaseReturn = 'lease_return';

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Vente',
            self::Scrap => 'Mise au rebut',
            self::LeaseReturn => 'Restitution en fin de leasing',
        };
    }
}
