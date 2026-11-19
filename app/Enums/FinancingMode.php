<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Mode de financement à l'acquisition (B-02). Le contrat de leasing ou de crédit
 * lui-même est saisi au lot L6 (vehicle_financings).
 */
enum FinancingMode: string
{
    case Cash = 'cash';
    case Credit = 'credit';
    case Leasing = 'leasing';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Comptant',
            self::Credit => 'Crédit bancaire',
            self::Leasing => 'Leasing',
        };
    }
}
