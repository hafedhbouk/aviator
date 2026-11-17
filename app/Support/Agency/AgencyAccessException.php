<?php

declare(strict_types=1);

namespace App\Support\Agency;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * Tentative d'agir sur une agence non autorisée (RG-13). Hérite de
 * AuthorizationException : une requête HTTP reçoit une réponse 403.
 */
final class AgencyAccessException extends AuthorizationException
{
    public static function notAuthorized(?int $agencyId): self
    {
        return new self(sprintf(
            'Vous n\'êtes pas autorisé à agir sur l\'agence %s.',
            $agencyId === null ? '(aucune)' : '#'.$agencyId,
        ));
    }

    public static function noAgency(): self
    {
        return new self('Aucune agence n\'est rattachée à votre compte : contactez le super admin.');
    }
}
