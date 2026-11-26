<?php

declare(strict_types=1);

namespace App\Domain\Availability\Exceptions;

use RuntimeException;

/**
 * Refus métier d'une réservation, message affichable à l'agent, sans donnée client.
 */
final class ReservationException extends RuntimeException
{
    public static function notAllowed(string $what): self
    {
        return new self("Action non autorisée sur {$what}.");
    }

    public static function categoryFull(string $category): self
    {
        return new self("Plus aucun véhicule {$category} disponible sur cette période dans cette agence.");
    }

    public static function vehicleNotAvailable(string $vehicle): self
    {
        return new self("Le véhicule {$vehicle} n'est pas disponible sur cette période dans l'agence de départ.");
    }

    public static function vehicleCategoryMismatch(string $vehicle, string $category): self
    {
        return new self("Le véhicule {$vehicle} n'appartient pas à la catégorie {$category}.");
    }

    public static function invalidStatus(string $status, string $action): self
    {
        return new self("Réservation « {$status} » : {$action} impossible.");
    }

    public static function reasonRequired(int $min): self
    {
        return new self("Le motif d'annulation est obligatoire ({$min} caractères au moins).");
    }

    public static function noShowTooEarly(string $from): self
    {
        return new self("Non-présentation possible à partir du {$from}.");
    }

    public static function minRentalDays(int $days): self
    {
        return new self("Durée minimale de location : {$days} jour(s).");
    }

    public static function minNotice(int $hours): self
    {
        return new self("Préavis minimal : départ au moins {$hours} h après la réservation.");
    }

    public static function startInPast(): self
    {
        return new self('Le départ ne peut pas être dans le passé.');
    }
}
