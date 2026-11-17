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

    public static function group(): string
    {
        return 'notifications';
    }
}
