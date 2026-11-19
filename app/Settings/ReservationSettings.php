<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Règles de réservation (A-06), modifiables par le super admin (écran au lot L8).
 * Les valeurs par défaut viennent des questions ouvertes ; une catégorie peut
 * surcharger le délai de préparation et les conditions du conducteur (A-03).
 */
class ReservationSettings extends Settings
{
    /** Délai de préparation entre deux locations, en minutes (Q41 : 60). */
    public int $preparation_buffer_minutes;

    /** Âge minimum du conducteur, en années (Q42 : 21). */
    public int $min_driver_age;

    /** Ancienneté minimale du permis, en années (Q42 : 2). */
    public int $min_license_years;

    public static function group(): string
    {
        return 'reservations';
    }
}
