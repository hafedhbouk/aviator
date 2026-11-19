<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Réglages des notifications, modifiables par le super admin (écran en phase 3).
 */
class NotificationSettings extends Settings
{
    /**
     * Envoyer les notifications urgentes (demandes d'approbation, décisions) pendant
     * la requête, au lieu de passer par la file. À activer si le cron ne tourne que
     * toutes les 5 minutes (D20) : « aviator:check-environment » le conseille alors.
     */
    public bool $send_urgent_in_request;

    /** Événements d'alerte (A-09) et leur libellé. */
    public const ALERTS = [
        'insurance_expiry' => 'Fin d\'assurance d\'un véhicule',
        'technical_inspection' => 'Visite technique à faire',
        'maintenance_due' => 'Entretien à faire',
        'leasing_end' => 'Fin de leasing',
        'late_return' => 'Retard de restitution',
        'approval_request' => 'Demande d\'approbation',
    ];

    /** Alertes datées : le délai (jours avant l'échéance) est exigé pour les activer. */
    public const DATED_ALERTS = ['insurance_expiry', 'technical_inspection', 'maintenance_due', 'leasing_end'];

    /**
     * Alertes (A-09), envoyées en push à partir de la phase 9 :
     * événement => {enabled, days_before (alertes datées), recipients (profils)}.
     *
     * Type donné en @phpstan-var : spatie/laravel-settings lit @var pour choisir un
     * transtypage et ne comprend ni les formes de tableau ni « mixed ».
     *
     * @phpstan-var array<string, array{enabled: bool, days_before: int|null, recipients: list<string>}>
     */
    public array $alerts;

    public static function group(): string
    {
        return 'notifications';
    }
}
