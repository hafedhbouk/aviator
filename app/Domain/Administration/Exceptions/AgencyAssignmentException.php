<?php

declare(strict_types=1);

namespace App\Domain\Administration\Exceptions;

use DomainException;

final class AgencyAssignmentException extends DomainException
{
    public static function onlyAgencyUsers(): self
    {
        return new self('Seul un utilisateur d\'agence peut être affecté en remplacement.');
    }

    public static function defaultAgency(): self
    {
        return new self('L\'agence choisie est déjà l\'agence par défaut de cet utilisateur.');
    }

    public static function inactiveAgency(): self
    {
        return new self('L\'agence choisie n\'est pas active.');
    }

    public static function invalidPeriod(): self
    {
        return new self('La date de fin doit être postérieure ou égale à la date de début.');
    }

    public static function endsInThePast(): self
    {
        return new self('La période de remplacement est déjà terminée.');
    }

    public static function overlaps(): self
    {
        return new self('Une affectation à cette agence existe déjà sur une partie de cette période.');
    }

    public static function alreadyEnded(): self
    {
        return new self('Cette affectation est déjà terminée : elle reste dans l\'historique.');
    }
}
