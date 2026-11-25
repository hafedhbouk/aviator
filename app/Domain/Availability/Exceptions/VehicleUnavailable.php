<?php

declare(strict_types=1);

namespace App\Domain\Availability\Exceptions;

use RuntimeException;

/**
 * Le véhicule ne peut pas être occupé sur la période demandée (RG-01). Message
 * affichable à l'agent (parcours P1, écran E2), sans aucune donnée client.
 */
final class VehicleUnavailable extends RuntimeException
{
    public static function overlapping(string $vehicle): self
    {
        return new self("Ce véhicule vient d'être réservé sur cette période ({$vehicle}). Choisissez un autre véhicule ou d'autres dates.");
    }

    public static function retired(string $vehicle): self
    {
        return new self("Le véhicule {$vehicle} est sorti du parc.");
    }

    public static function outOfService(string $vehicle, string $status): self
    {
        return new self("Le véhicule {$vehicle} n'est pas louable ({$status}).");
    }
}
