<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Réglages des états des lieux (D-06, D44), modifiables par le super admin.
 */
class InspectionSettings extends Settings
{
    /** Nombre maximal de photos par état des lieux (D44 : 12, espace disque, Q68). */
    public int $max_photos;

    public static function group(): string
    {
        return 'inspections';
    }
}
