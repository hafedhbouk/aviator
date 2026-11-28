<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use RuntimeException;

/**
 * Avoir ou règlement d'avoir refusé (L6, E-04, E19, D27-4). Refusé avant le numéro :
 * aucun numéro n'est consommé. Le message s'affiche tel quel.
 */
final class CreditNoteException extends RuntimeException
{
    public static function notAllowed(): self
    {
        return new self('Action non autorisée sur cette facture ou cet avoir.');
    }

    public static function reasonRequired(int $length): self
    {
        return new self("Le motif de l'avoir est obligatoire ({$length} caractères au moins).");
    }

    public static function notOnCreditNote(): self
    {
        return new self('Un avoir ne se crédite pas : il se rattache toujours à une facture.');
    }

    public static function notIssued(): self
    {
        return new self('Cette facture n\'a pas été émise : elle s\'annule sans avoir (Q79).');
    }

    public static function fullyCredited(): self
    {
        return new self('Cette facture est déjà entièrement créditée.');
    }

    public static function invalidStatus(string $status): self
    {
        return new self("Une facture au statut « {$status} » ne se crédite pas.");
    }

    public static function unknownLine(): self
    {
        return new self('Ligne inconnue : un avoir ne crédite que des lignes de sa facture d\'origine.');
    }

    public static function invalidAmount(string $label, string $reason): self
    {
        return new self("Ligne « {$label} » : {$reason}");
    }

    public static function lineExceeded(string $label, string $remaining): self
    {
        return new self("Ligne « {$label} » : il reste {$remaining} HT à créditer, pas davantage.");
    }

    public static function nothingToCredit(): self
    {
        return new self('Indiquez au moins un montant à créditer.');
    }

    public static function capExceeded(string $remaining): self
    {
        return new self("Le total des avoirs dépasserait celui de la facture : il reste {$remaining} à créditer.");
    }

    public static function missingOriginalTax(string $code): self
    {
        return new self("La facture d'origine n'a pas de taxe enregistrée pour le code {$code}.");
    }

    public static function notACreditNote(): self
    {
        return new self('Seul un avoir validé se rembourse ou s\'impute.');
    }

    public static function settlementExceeded(string $remaining): self
    {
        return new self("Il reste {$remaining} à rembourser ou imputer sur cet avoir, pas davantage.");
    }

    public static function invalidTarget(string $reason): self
    {
        return new self("Imputation refusée : {$reason}");
    }

    public static function invalidRefund(string $reason): self
    {
        return new self("Remboursement refusé : {$reason}");
    }
}
