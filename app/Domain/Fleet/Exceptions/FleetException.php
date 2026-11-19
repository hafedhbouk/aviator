<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Exceptions;

use RuntimeException;

/**
 * Refus métier du parc, avec un message affichable en français.
 */
final class FleetException extends RuntimeException
{
    public static function notAllowed(string $what): self
    {
        return new self("Action non autorisée sur {$what}.");
    }

    public static function vehicleRetired(string $label): self
    {
        return new self("Le véhicule {$label} est sorti du parc : il ne peut plus être modifié.");
    }

    public static function inactiveCategory(): self
    {
        return new self('Catégorie inactive : choisissez une catégorie active.');
    }

    public static function inactiveAgency(): self
    {
        return new self('Agence inactive : choisissez une agence active.');
    }
}
