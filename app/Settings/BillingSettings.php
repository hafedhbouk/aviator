<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Réglages de facturation, modifiables par le super admin (écran au lot L8).
 */
class BillingSettings extends Settings
{
    /** Valeurs admises de vat_rounding_mode. */
    public const ROUNDING_MODES = ['half_up', 'half_even'];

    /**
     * Mode d'arrondi de la TVA de chaque base agrégée, à 3 décimales (référentiel v1.0,
     * §5). « half_up » (demi supérieur : 0,0285 → 0,029) par défaut, à confirmer par le
     * cabinet (Q86) ; « half_even » (demi pair : 0,0285 → 0,028).
     */
    public string $vat_rounding_mode;

    public static function group(): string
    {
        return 'billing';
    }
}
