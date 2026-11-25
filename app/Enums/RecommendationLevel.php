<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Niveau de recommandation d'un client (K-08 à K-11).
 */
enum RecommendationLevel: string
{
    case Recommended = 'recommended';
    case Standard = 'standard';
    case Watch = 'watch';
    case Blacklist = 'blacklist';

    public function label(): string
    {
        return match ($this) {
            self::Recommended => 'Recommandé',
            self::Standard => 'Standard',
            self::Watch => 'Vigilance',
            self::Blacklist => 'Liste noire',
        };
    }
}
