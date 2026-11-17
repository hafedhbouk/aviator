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
     * Profils pour lesquels la 2FA est obligatoire (J-03, D9).
     */
    public function requiresTwoFactor(): bool
    {
        return $this === self::SuperAdmin || $this === self::Admin;
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
