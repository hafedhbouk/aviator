<?php

declare(strict_types=1);

namespace App\Enums;

enum FinancingType: string
{
    case Leasing = 'leasing';
    case Credit = 'credit';

    public function label(): string
    {
        return match ($this) {
            self::Leasing => 'Leasing',
            self::Credit => 'Crédit bancaire',
        };
    }
}
