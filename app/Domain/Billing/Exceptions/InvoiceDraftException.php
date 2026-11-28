<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use RuntimeException;

/**
 * Brouillon de facture refusé (L3). Le message s'affiche tel quel.
 */
final class InvoiceDraftException extends RuntimeException
{
    public static function notAllowed(): self
    {
        return new self('Action non autorisée sur cette facture ou ce contrat.');
    }

    public static function contractNotBillable(string $status): self
    {
        return new self("Un contrat au statut « {$status} » ne se facture pas : il faut un contrat en cours ou restitué.");
    }

    public static function notReturned(): self
    {
        return new self('La dernière période se facture au retour du véhicule (contrat « Restitué »), avec les frais figés.');
    }

    public static function fullyBilled(): self
    {
        return new self('Toute la durée de ce contrat est déjà facturée : un frais constaté après coup passe par une facture complémentaire.');
    }

    public static function periodOverlap(string $period): self
    {
        return new self("La période du {$period} est déjà facturée : une période ne se facture jamais deux fois (RG-05).");
    }

    public static function notDraft(): self
    {
        return new self('Seul un brouillon se modifie : une facture validée se corrige par un avoir.');
    }

    public static function contractLine(): self
    {
        return new self('Une ligne reprise du contrat ne se supprime pas et garde son montant : seuls son libellé et son code fiscal se modifient.');
    }

    public static function unknownTaxCode(): self
    {
        return new self('Code fiscal inconnu.');
    }
}
