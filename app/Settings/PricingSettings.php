<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Réglages de tarification, modifiables par le super admin. Appliqués au calcul du
 * prix et des retards (phases 4 et 5).
 */
class PricingSettings extends Settings
{
    public const DAY_MODES = ['rolling_24h' => 'Tranches de 24 h depuis l\'heure de départ', 'calendar' => 'Jours calendaires'];

    public const LATE_UNITS = ['day' => 'Une journée au tarif du contrat', 'hour' => 'À l\'heure'];

    /** Décompte des jours facturés (Q32 : tranches de 24 h glissantes). */
    public string $day_mode;

    /** Tolérance de retard au retour, en minutes (Q14 : 60). */
    public int $late_tolerance_minutes;

    /** Facturation du retard au-delà de la tolérance (Q14 : une journée). */
    public string $late_unit;

    /** Prix du litre de carburant manquant, HT (Q44 : vide = aucun frais de carburant). */
    public ?string $fuel_liter_amount;

    /** Frais de service du carburant manquant, HT, par retour concerné (Q44 : vide = aucun). */
    public ?string $fuel_service_amount;

    public static function group(): string
    {
        return 'pricing';
    }
}
