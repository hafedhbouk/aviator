<?php

declare(strict_types=1);

namespace App\Domain\Crm\Exceptions;

use RuntimeException;

/**
 * Client sur liste noire (RG-15) : ni réservation ni contrat. Le message ne donne
 * jamais le motif ni l'auteur (K-11, Q38) ; la dérogation du super admin arrive en
 * phase 5 avec les approbations (D36).
 */
final class CustomerBlocked extends RuntimeException
{
    public static function blacklisted(): self
    {
        return new self('Réservation impossible pour ce client. Contactez le super admin.');
    }
}
