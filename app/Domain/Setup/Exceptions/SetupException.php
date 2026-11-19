<?php

declare(strict_types=1);

namespace App\Domain\Setup\Exceptions;

use RuntimeException;

/**
 * Refus d'une modification du paramétrage (module A). Le message s'affiche tel quel.
 */
final class SetupException extends RuntimeException
{
    public static function notAllowed(string $what = 'la fiche société'): self
    {
        return new self("Vous n'avez pas le droit de modifier {$what}.");
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

    public static function invalidOpeningHours(string $day, string $reason): self
    {
        return new self("Horaires refusés ({$day}) : {$reason}.");
    }

    public static function agencyCodeInvalid(): self
    {
        return new self("Code d'agence refusé : 2 à 8 lettres majuscules ou chiffres.");
    }

    public static function agencyCodeTaken(string $code): self
    {
        return new self("Le code d'agence {$code} est déjà utilisé.");
    }

    public static function agencyCodeImmutable(): self
    {
        return new self("Le code d'une agence ne se modifie pas après sa création.");
    }

    public static function headOfficeCannotBeDeactivated(): self
    {
        return new self('Le siège ne peut pas être désactivé.');
    }

    public static function agencyHasActiveUsers(int $count): self
    {
        return new self("Agence non désactivée : {$count} utilisateur(s) actif(s) y sont rattachés par défaut. Changez d'abord leur agence.");
    }

    public static function locationWithoutUse(): self
    {
        return new self('Un lieu sert au moins à la prise en charge ou à la restitution.');
    }

    public static function locationNameTaken(string $name): self
    {
        return new self("L'agence a déjà un lieu nommé « {$name} ».");
    }
}
