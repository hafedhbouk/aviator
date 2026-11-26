<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use RuntimeException;

/**
 * Devis impossible, avec un message affichable à l'agent. Jamais de prix nul à la
 * place d'un prix manquant (D30).
 */
final class QuoteUnavailable extends RuntimeException
{
    public static function noRate(string $category, string $date): self
    {
        return new self("Aucun tarif pour la catégorie {$category} le {$date} : saison ou grille tarifaire à saisir.");
    }

    public static function inactiveCategory(string $category): self
    {
        return new self("La catégorie {$category} est inactive.");
    }

    public static function optionUnavailable(string $option): self
    {
        return new self("L'option « {$option} » n'est pas proposée (inactive ou sans prix).");
    }

    public static function invalidQuantity(string $option): self
    {
        return new self("Quantité invalide pour l'option « {$option} ».");
    }

    public static function discountTooHigh(): self
    {
        return new self('La remise dépasse le montant de la location.');
    }

    public static function discountAboveThreshold(string $threshold): self
    {
        return new self("Remise refusée : elle dépasse le seuil de {$threshold} % accordé sans approbation du super admin.");
    }
}
