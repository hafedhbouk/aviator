<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Exceptions;

use RuntimeException;

/**
 * Départ en location refusé : document obligatoire non valide à la date de départ
 * (B-05, scénario 10). Message explicite pour l'agent (parcours P3, étape 3).
 */
final class VehicleNotRentable extends RuntimeException
{
    /**
     * @param  list<string>  $missing  libellés des documents manquants ou expirés
     */
    public static function missingDocuments(string $vehicle, array $missing, string $date): self
    {
        return new self(sprintf(
            'Départ impossible pour %s le %s : %s non valide%s à cette date.',
            $vehicle,
            $date,
            implode(' et ', $missing),
            count($missing) > 1 ? 's' : '',
        ));
    }
}
