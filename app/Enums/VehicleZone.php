<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Zones du schéma du véhicule (D-06), vu de dessus : un dommage est placé dans une
 * zone, avec sa position en pourcentage du schéma (x, y de 0 à 100).
 */
enum VehicleZone: string
{
    case FrontBumper = 'front_bumper';
    case Hood = 'hood';
    case Windshield = 'windshield';
    case Roof = 'roof';
    case RearWindow = 'rear_window';
    case Trunk = 'trunk';
    case RearBumper = 'rear_bumper';
    case FrontLeft = 'front_left';
    case RearLeft = 'rear_left';
    case FrontRight = 'front_right';
    case RearRight = 'rear_right';
    case Wheels = 'wheels';
    case Interior = 'interior';

    public function label(): string
    {
        return match ($this) {
            self::FrontBumper => 'Pare-chocs avant',
            self::Hood => 'Capot',
            self::Windshield => 'Pare-brise',
            self::Roof => 'Toit',
            self::RearWindow => 'Lunette arrière',
            self::Trunk => 'Coffre',
            self::RearBumper => 'Pare-chocs arrière',
            self::FrontLeft => 'Avant gauche (aile, portière)',
            self::RearLeft => 'Arrière gauche (aile, portière)',
            self::FrontRight => 'Avant droit (aile, portière)',
            self::RearRight => 'Arrière droit (aile, portière)',
            self::Wheels => 'Jantes et pneus',
            self::Interior => 'Intérieur',
        };
    }
}
