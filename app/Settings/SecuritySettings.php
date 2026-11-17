<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Réglages de sécurité, modifiables par le super admin (écran en phase 3).
 */
class SecuritySettings extends Settings
{
    /**
     * 2FA obligatoire aussi pour les utilisateurs d'agence. Désactivé par défaut ;
     * décision de la direction attendue (Q76, recommandation : l'activer).
     */
    public bool $require_2fa_for_agency_users;

    public static function group(): string
    {
        return 'security';
    }
}
