<?php

declare(strict_types=1);

namespace App\Support\Privacy;

/**
 * Colonnes client qu'un export peut contenir (RG-14, D13).
 */
enum ExportLevel: string
{
    /** Toutes les colonnes, coordonnées comprises (super admin). */
    case Full = 'full';

    /** Noms des clients, sans coordonnées (admin, si la direction l'autorise : D17). */
    case Names = 'names';

    /** Nom et matricule fiscal seulement (exports comptables). */
    case Accounting = 'accounting';

    /** Aucune colonne client : agrégats seulement. */
    case Aggregates = 'aggregates';

    /** Export refusé. */
    case Denied = 'denied';

    public function includesNames(): bool
    {
        return in_array($this, [self::Full, self::Names, self::Accounting], true);
    }

    public function includesContactDetails(): bool
    {
        return $this === self::Full;
    }
}
