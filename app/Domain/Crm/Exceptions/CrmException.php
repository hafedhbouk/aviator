<?php

declare(strict_types=1);

namespace App\Domain\Crm\Exceptions;

use RuntimeException;

/**
 * Refus métier du module clients, avec un message affichable en français, sans
 * aucune donnée client (RG-14).
 */
final class CrmException extends RuntimeException
{
    public static function notAllowed(string $what): self
    {
        return new self("Action non autorisée sur {$what}.");
    }

    public static function identifierRequired(): self
    {
        return new self('Création refusée : saisissez au moins le numéro de la pièce d\'identité ou celui du permis (D40).');
    }

    public static function searchLimitReached(int $limit): self
    {
        return new self("Limite atteinte : {$limit} recherches de clients par heure. Réessayez plus tard ; le super admin est prévenu.");
    }

    public static function emptySearch(): self
    {
        return new self('Saisissez une valeur à rechercher.');
    }
}
