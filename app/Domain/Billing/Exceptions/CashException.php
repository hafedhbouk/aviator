<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use RuntimeException;

/**
 * Opération de caisse refusée (L8, E-09, Q55). Le message s'affiche tel quel.
 */
final class CashException extends RuntimeException
{
    public static function notAllowed(): self
    {
        return new self('Action non autorisée sur la caisse de cette agence.');
    }

    public static function previousOpen(string $date): self
    {
        return new self("La caisse du {$date} n'est pas clôturée : clôturez-la avant d'ouvrir celle du jour.");
    }

    public static function alreadyOpenedToday(): self
    {
        return new self('La caisse de cette agence a déjà été ouverte aujourd\'hui : une seule caisse par agence et par jour.');
    }

    public static function noOpenSession(): self
    {
        return new self('Aucune caisse ouverte aujourd\'hui dans cette agence : ouvrez la caisse avant tout mouvement en espèces.');
    }

    public static function alreadyClosed(): self
    {
        return new self('Cette caisse est déjà clôturée.');
    }

    public static function differenceReasonRequired(string $difference, int $length): self
    {
        return new self("Écart de {$difference} entre le montant compté et le montant attendu : un motif d'au moins {$length} caractères est obligatoire.");
    }

    public static function invalidAmount(string $label, string $reason): self
    {
        return new self("Le champ {$label} : {$reason}");
    }

    public static function closedSession(): self
    {
        return new self('La caisse de cet encaissement en espèces est clôturée : il ne s\'annule plus ici (écart à traiter par l\'admin).');
    }
}
