<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use RuntimeException;

/**
 * Validation ou émission d'une facture refusée (L4, RG-07, N2, N3, N5, E6, D53, D55) :
 * refusée avant l'attribution du numéro, aucun numéro n'est consommé. Le message
 * s'affiche tel quel.
 */
final class InvoiceValidationException extends RuntimeException
{
    public static function notAllowed(): self
    {
        return new self('Action non autorisée sur cette facture.');
    }

    public static function invalidStatus(string $status, string $operation): self
    {
        return new self("Une facture au statut « {$status} » ne permet pas : {$operation}.");
    }

    public static function noLines(): self
    {
        return new self('Une facture sans ligne ou d\'un montant nul ne se valide pas.');
    }

    public static function missingTaxCode(string $label): self
    {
        return new self("Choisissez le code fiscal de la ligne « {$label} » : il n'est jamais déduit du libellé.");
    }

    public static function ruleUnavailable(string $label, string $reason): self
    {
        return new self("Ligne « {$label} » : {$reason}");
    }

    public static function missingJustification(string $label, string $code): self
    {
        return new self("Ligne « {$label} » : le code {$code} exige un justificatif rattaché (dossier de dommage complet, contrat, clause ou constat).");
    }

    public static function fiscalGuard(string $problems): self
    {
        return new self("Validation refusée en production : {$problems}.");
    }

    public static function companyMissing(): self
    {
        return new self('La fiche société est vide : renseignez-la avant de valider une facture.');
    }

    public static function stampExemption(string $reason): self
    {
        return new self("Exonération du timbre refusée : {$reason}");
    }

    public static function cancellationReason(int $length): self
    {
        return new self("Un motif d'au moins {$length} caractères est obligatoire pour annuler une facture.");
    }

    public static function issuedNeedsCreditNote(): self
    {
        return new self('Une facture émise ne s\'annule pas : elle se corrige par un avoir total ou partiel (Q79).');
    }

    public static function closedPeriod(string $operation, string $period): self
    {
        return new self("La période {$period} est clôturée : {$operation} refusée (RG-12).");
    }
}
