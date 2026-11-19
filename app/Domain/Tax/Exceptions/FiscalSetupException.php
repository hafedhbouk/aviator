<?php

declare(strict_types=1);

namespace App\Domain\Tax\Exceptions;

use RuntimeException;

/**
 * Refus d'une modification des paramètres fiscaux. Le message s'affiche tel quel.
 */
final class FiscalSetupException extends RuntimeException
{
    public static function notAllowed(): self
    {
        return new self('Seul le super admin modifie les paramètres fiscaux.');
    }

    public static function invalidStatus(): self
    {
        return new self('Statut de validation inconnu.');
    }

    public static function rateRequired(): self
    {
        return new self('Taux refusé : un code taxable exige un taux entre 0 et 100 %, 4 décimales au plus.');
    }

    public static function rateForbidden(): self
    {
        return new self('Taux refusé : un code exonéré ou hors champ n\'a pas de taux de TVA.');
    }

    public static function referenceRequired(): self
    {
        return new self('Une règle CONFIRMED renvoie à une référence fiscale validée.');
    }

    public static function invalidDate(): self
    {
        return new self('Date de début invalide (AAAA-MM-JJ).');
    }

    public static function notAfterExisting(string $date): self
    {
        return new self("Nouvelle version refusée : elle doit commencer après la dernière version (du {$date}). Les versions ne se chevauchent pas.");
    }

    public static function invalidAmount(): self
    {
        return new self('Montant du timbre invalide : positif ou nul, au millime.');
    }

    public static function cannotActivatePending(): self
    {
        return new self('Une règle PENDING_DOCUMENTATION ne peut pas être activée : déposez la pièce, puis créez une version CONFIRMED ou CONDITIONAL.');
    }

    public static function unknownStampRule(): self
    {
        return new self('Règle de timbre inconnue.');
    }
}
