<?php

declare(strict_types=1);

namespace App\Support\Authorization;

/**
 * Les trois profils du back-office (D3, D16). Le profil « customer » du lot 2
 * passera par une garde séparée, hors de cette énumération.
 */
enum Role: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case AgencyUser = 'agency_user';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super admin',
            self::Admin => 'Admin du siège',
            self::AgencyUser => 'Utilisateur d\'agence',
        };
    }

    /**
     * 2FA obligatoire : toujours pour le super admin et l'admin (J-03, D9) ; pour
     * l'utilisateur d'agence selon le réglage security.require_2fa_for_agency_users (Q76).
     */
    public function requiresTwoFactor(bool $requiredForAgencyUsers = false): bool
    {
        return match ($this) {
            self::SuperAdmin, self::Admin => true,
            self::AgencyUser => $requiredForAgencyUsers,
        };
    }

    /**
     * Profils attribuables depuis l'interface : un super admin ne se crée qu'avec
     * « php artisan aviator:create-super-admin » (Q2).
     *
     * @return list<self>
     */
    public static function assignableFromInterface(): array
    {
        return [self::Admin, self::AgencyUser];
    }
}
