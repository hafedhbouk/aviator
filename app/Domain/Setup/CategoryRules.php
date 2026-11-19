<?php

declare(strict_types=1);

namespace App\Domain\Setup;

use App\Models\VehicleCategory;
use App\Settings\ReservationSettings;
use App\Support\Money\Money;

/**
 * Règles effectives d'une catégorie (A-03) : la surcharge de la catégorie si elle
 * est saisie, sinon la valeur générale (ReservationSettings). Servira à la
 * réservation (phase 4) et au contrat (phase 5).
 *
 * Valeurs sans valeur générale (vides = « non fixé ») : caution (Q14, saisie à
 * chaque contrat), kilométrage inclus (Q43, illimité), prix du km supplémentaire
 * (Q43), franchise (Q45, pas de plafond).
 */
final readonly class CategoryRules
{
    private function __construct(
        public int $minDriverAge,
        public int $minLicenseYears,
        public int $preparationBufferMinutes,
        public ?Money $depositAmount,
        public ?int $includedKmPerDay,
        public ?Money $extraKmAmount,
        public ?Money $insuranceExcessAmount,
    ) {}

    public static function for(VehicleCategory $category, ?ReservationSettings $settings = null): self
    {
        $settings ??= app(ReservationSettings::class);

        return new self(
            minDriverAge: $category->min_driver_age ?? $settings->min_driver_age,
            minLicenseYears: $category->min_license_years ?? $settings->min_license_years,
            preparationBufferMinutes: $category->preparation_buffer_minutes ?? $settings->preparation_buffer_minutes,
            depositAmount: $category->deposit_amount,
            includedKmPerDay: $category->included_km_per_day,
            extraKmAmount: $category->extra_km_amount,
            insuranceExcessAmount: $category->insurance_excess_amount,
        );
    }

    public function hasUnlimitedMileage(): bool
    {
        return $this->includedKmPerDay === null;
    }
}
