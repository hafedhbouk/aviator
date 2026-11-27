<?php

declare(strict_types=1);

namespace App\Domain\Crm\Exceptions;

use RuntimeException;

/**
 * Client sur liste noire (RG-15) : ni réservation ni contrat sans dérogation du
 * super admin (D46). Le message ne donne jamais le motif ni l'auteur (K-11, Q38).
 */
final class CustomerBlocked extends RuntimeException
{
    public static function blacklisted(): self
    {
        return new self('Réservation impossible pour ce client sans dérogation du super admin : enregistrez un brouillon avec une demande de dérogation motivée.');
    }

    public static function overridePending(): self
    {
        return new self('La dérogation demandée pour ce client n\'est pas encore approuvée par le super admin : la réservation reste en brouillon.');
    }
}
