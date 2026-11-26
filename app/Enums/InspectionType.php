<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Type d'état des lieux (D-06) : au départ et au retour du véhicule.
 */
enum InspectionType: string
{
    case Departure = 'departure';
    case Return = 'return';

    public function label(): string
    {
        return match ($this) {
            self::Departure => 'Départ',
            self::Return => 'Retour',
        };
    }
}
