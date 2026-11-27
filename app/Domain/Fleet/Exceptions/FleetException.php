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

    public static function reasonRequired(int $min): self
    {
        return new self("Motif obligatoire ({$min} caractères au moins).");
    }

    public static function notInMaintenance(string $label): self
    {
        return new self("Le véhicule {$label} n'est pas en maintenance.");
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

    public static function financingModeMismatch(string $vehicleMode, string $type): self
    {
        return new self("Le véhicule est financé « {$vehicleMode} » sur sa fiche : un financement « {$type} » ne lui correspond pas. Corrigez d'abord la fiche.");
    }

    public static function financingAlreadyOpen(): self
    {
        return new self('Ce véhicule a déjà un financement en cours : clôturez-le d\'abord.');
    }

    public static function financingClosed(): self
    {
        return new self('Ce financement est clôturé : il ne se modifie plus.');
    }

    public static function financingHasPaidInstallments(): self
    {
        return new self('Des échéances sont déjà pointées : le financement ne se modifie plus.');
    }

    public static function invalidOutcome(string $outcome, string $type): self
    {
        return new self("Issue « {$outcome} » impossible pour un financement « {$type} ».");
    }

    public static function invalidPaymentDate(): self
    {
        return new self('Date de paiement invalide : elle ne peut pas être dans le futur.');
    }

    private static function km(int $value): string
    {
        return number_format($value, 0, ',', ' ');
    }
}
