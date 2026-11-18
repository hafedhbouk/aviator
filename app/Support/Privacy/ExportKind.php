<?php

declare(strict_types=1);

namespace App\Support\Privacy;

/**
 * Nature d'un export, pour décider des colonnes client autorisées (RG-14, D13).
 */
enum ExportKind: string
{
    /** Carnet d'adresses : fichier clients complet (coordonnées comprises). */
    case CustomerFile = 'customer_file';

    /** Listes métier : meilleurs clients, segments, impayés… */
    case BusinessList = 'business_list';

    /** Journaux pour l'expert-comptable (module H). */
    case Accounting = 'accounting';

    /** Statistiques (module I). */
    case Statistics = 'statistics';
}
