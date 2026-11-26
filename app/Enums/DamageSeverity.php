<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Gravité d'un dommage relevé à l'état des lieux (D-06).
 */
enum DamageSeverity: string
{
    case Minor = 'minor';
    case Moderate = 'moderate';
    case Major = 'major';

    public function label(): string
    {
        return match ($this) {
            self::Minor => 'Léger (rayure, éclat)',
            self::Moderate => 'Moyen (bosse, rayure profonde)',
            self::Major => 'Important (pièce à remplacer)',
        };
    }
}
