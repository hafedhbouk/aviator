<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use RuntimeException;

/**
 * Opération de caution refusée (L10, module F, RG-08, Q54, D58). Le message s'affiche
 * tel quel.
 */
final class DepositException extends RuntimeException
{
    public static function notAllowed(): self
    {
        return new self('Action non autorisée sur cette caution ou ce contrat.');
    }

    public static function contractStatus(string $status): self
    {
        return new self("Une caution se reçoit au départ : le contrat est au statut « {$status} ».");
    }

    public static function alreadyRecorded(): self
    {
        return new self('Ce contrat a déjà sa caution : une seule caution par contrat.');
    }

    public static function differenceReasonRequired(string $expected, int $length): self
    {
        return new self("Le montant reçu diffère de la caution prévue ({$expected}) : un motif d'au moins {$length} caractères est obligatoire.");
    }

    public static function cardData(): self
    {
        return new self('Aucune donnée de carte n\'est enregistrée : saisissez la référence d\'autorisation de l\'empreinte, jamais le numéro de la carte (Q54).');
    }

    public static function invalidMethod(string $reason): self
    {
        return new self("Mode refusé : {$reason}");
    }

    public static function notReturned(): self
    {
        return new self('La caution se restitue au retour du véhicule (contrat restitué ou clôturé).');
    }

    public static function exceeded(string $remaining): self
    {
        return new self("Il reste {$remaining} sur cette caution : restitué + retenu ne dépasse jamais le reçu (RG-08).");
    }

    public static function pendingRetention(string $pending): self
    {
        return new self("Une retenue de {$pending} attend la décision du super admin : elle reste réservée sur la caution.");
    }

    public static function reasonRequired(int $length): self
    {
        return new self("Le motif de la retenue est obligatoire ({$length} caractères au moins).");
    }

    public static function justificationRequired(): self
    {
        return new self('Le justificatif de la retenue est obligatoire (constat, devis, photos, facture de réparation).');
    }

    public static function invalidInvoice(string $reason): self
    {
        return new self("Facture de frais refusée : {$reason}");
    }

    public static function notApproved(): self
    {
        return new self('Cette retenue n\'est pas approuvée par le super admin : elle ne s\'applique pas.');
    }

    public static function alreadyApplied(): self
    {
        return new self('Cette retenue est déjà appliquée.');
    }

    public static function invalidAmount(string $reason): self
    {
        return new self("Montant refusé : {$reason}");
    }
}
