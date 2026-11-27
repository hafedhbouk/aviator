<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use App\Support\Sequences\Series;
use RuntimeException;

/**
 * Refus d'un changement de numérotation (D27). Le message s'affiche tel quel.
 */
final class NumberingException extends RuntimeException
{
    public static function decisionRequired(): self
    {
        return new self('Le nom de la personne qui a décidé du changement est obligatoire.');
    }

    public static function invalidFormat(string $reason): self
    {
        return new self("Format refusé : {$reason}");
    }

    public static function yearFormatLocked(Series $series): self
    {
        return new self("Format avec l'année refusé pour la série « {$series->label()} » : il exige une confirmation écrite du cabinet, enregistrée comme nouvelle version du référentiel fiscal (option --confirmation=<version>). Le référentiel v1.0 retient une numérotation continue sans l'année (§4.1).");
    }

    public static function yearlyResetLocked(Series $series): self
    {
        return new self("Remise à zéro annuelle refusée pour la série « {$series->label()} » : elle n'est pas CONFIRMED par le référentiel v1.0 (§4.1, compteur jamais remis à zéro) ; il faut une version ultérieure du référentiel, confirmée par écrit par le cabinet (option --confirmation=<version>).");
    }

    public static function unknownConfirmation(string $version): self
    {
        return new self("Confirmation refusée : aucune version « {$version} » du référentiel fiscal n'est enregistrée. Déposez d'abord la nouvelle version, avec la confirmation écrite du cabinet.");
    }

    public static function confirmationNotLater(string $version): self
    {
        return new self("Confirmation refusée : la version « {$version} » du référentiel n'est pas postérieure au référentiel v1.0, qui ne prévoit ni l'année dans le numéro ni la remise à zéro.");
    }

    public static function baselineMissing(): self
    {
        return new self('Le référentiel fiscal v1.0 n\'est pas enregistré : lancez les données de référence (php artisan db:seed) avant de régler la numérotation.');
    }

    public static function collidesWith(Series $other, string $template): self
    {
        return new self("Format refusé : il peut reproduire un numéro de la série « {$other->label()} » (format « {$template} »).");
    }

    public static function collidesWithOwnHistory(string $template): self
    {
        return new self("Format refusé : il peut reproduire un numéro déjà attribué dans cette série avec un ancien format (« {$template} »).");
    }

    public static function tooShort(int $next, int $padding): self
    {
        return new self("Longueur refusée : le prochain numéro ({$next}) ne tient pas sur {$padding} chiffres.");
    }

    public static function changedMeanwhile(): self
    {
        return new self('Le réglage de la série vient de changer : relisez-le et recommencez.');
    }
}
