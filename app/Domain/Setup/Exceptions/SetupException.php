<?php

declare(strict_types=1);

namespace App\Domain\Setup\Exceptions;

use RuntimeException;

/**
 * Refus d'une modification du paramétrage (module A). Le message s'affiche tel quel.
 */
final class SetupException extends RuntimeException
{
    public static function notAllowed(): self
    {
        return new self('Vous n\'avez pas le droit de modifier la fiche société.');
    }

    public static function legalMentionsNotAllowed(): self
    {
        return new self('Seul le super admin peut modifier les mentions des factures.');
    }

    public static function currencyNotAllowed(string $currency): self
    {
        return new self("Devise refusée ({$currency}) : l'application fonctionne uniquement en dinar tunisien (TND).");
    }

    public static function invalidLogo(): self
    {
        return new self('Logo refusé : seules les images PNG, JPEG ou WebP déposées depuis la fiche société sont acceptées.');
    }
}
