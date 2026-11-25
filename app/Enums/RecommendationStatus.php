<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Statut d'une recommandation : seule une recommandation « effective » compte
 * (une proposition de liste noire attend l'approbation du super admin, phase 7).
 */
enum RecommendationStatus: string
{
    case Effective = 'effective';
    case Proposed = 'proposed';
    case Rejected = 'rejected';
}
