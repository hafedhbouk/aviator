<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Nature d'un frais constaté au retour (D-09). Ce n'est **pas** un code fiscal : la
 * qualification fiscale (code, taux, hors champ avec justificatif) est choisie à la
 * facture, en phase 6 (référentiel v1.0, N2).
 */
enum ChargeNature: string
{
    case LateReturn = 'late_return';
    case ExtraKm = 'extra_km';
    case Fuel = 'fuel';
    case Damage = 'damage';

    public function label(): string
    {
        return match ($this) {
            self::LateReturn => 'Retard de restitution',
            self::ExtraKm => 'Kilomètres supplémentaires',
            self::Fuel => 'Carburant manquant',
            self::Damage => 'Dommage',
        };
    }
}
