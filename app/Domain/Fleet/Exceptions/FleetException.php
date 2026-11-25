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

    public static function mileageDecrease(int $current, int $new): self
    {
        return new self(sprintf(
            'Kilométrage refusé : %s km est inférieur au dernier relevé (%s km). Une baisse passe par une correction motivée.',
            self::km($new),
            self::km($current),
        ));
    }

    public static function invalidMileage(int $max): self
    {
        return new self('Kilométrage invalide : nombre entier de 0 à '.self::km($max).' km.');
    }

    public static function mileageInFuture(): self
    {
        return new self('Un relevé de kilométrage ne peut pas être daté dans le futur.');
    }

    public static function mileageBeforeLastReading(string $last): self
    {
        return new self("Relevé refusé : il est antérieur au dernier relevé ({$last}).");
    }

    public static function correctionReasonRequired(int $min): self
    {
        return new self("Le motif de la correction est obligatoire ({$min} caractères au moins).");
    }

    public static function correctionUnchanged(int $mileage): self
    {
        return new self('Correction inutile : le kilométrage est déjà de '.self::km($mileage).' km.');
    }

    private static function km(int $value): string
    {
        return number_format($value, 0, ',', ' ');
    }
}
