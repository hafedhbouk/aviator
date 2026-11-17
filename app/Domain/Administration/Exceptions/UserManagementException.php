<?php

declare(strict_types=1);

namespace App\Domain\Administration\Exceptions;

use DomainException;

final class UserManagementException extends DomainException
{
    public static function roleNotAssignable(): self
    {
        return new self('Ce profil ne peut pas être attribué depuis l\'interface.');
    }

    public static function superAdminManagedFromConsole(): self
    {
        return new self('Un compte super admin se gère uniquement en ligne de commande (aviator:create-super-admin).');
    }

    public static function cannotDeactivateSelf(): self
    {
        return new self('Vous ne pouvez pas désactiver votre propre compte.');
    }

    public static function agencyRequired(): self
    {
        return new self('Un utilisateur d\'agence doit être rattaché à une agence.');
    }
}
