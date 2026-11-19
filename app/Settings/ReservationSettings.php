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

    /** Durée minimale d'une location, en jours ; null = aucune (Q90). */
    public ?int $min_rental_days;

    /** Préavis minimal avant le départ, en heures ; null = aucun (Q90). */
    public ?int $min_notice_hours;

    /** Expiration d'une réservation non confirmée, en heures ; null = jamais (Q90, C-09). */
    public ?int $draft_expiry_hours;

    /** Délai avant de proposer le statut « non présenté », en minutes (Q39 : 120). */
    public int $no_show_after_minutes;

    /** Restitution dans une autre agence (Q3 : non). */
    public bool $allow_one_way;

    public static function group(): string
    {
        return 'reservations';
    }
}
