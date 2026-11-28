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

    /**
     * Conditions de paiement (A-07) : délai accordé, en jours, après la date de la
     * facture. 0 = paiement comptant (valeur par défaut, à confirmer : Q89).
     */
    public int $payment_terms_days;

    /**
     * Location longue (Q50, D52) : au-delà de ce nombre de jours facturés, le contrat
     * se facture par période de cette durée, jamais deux fois la même. 30 par défaut,
     * à confirmer par la direction et l'expert-comptable (Q50).
     */
    public int $long_rental_period_days;

    public static function group(): string
    {
        return 'billing';
    }
}
