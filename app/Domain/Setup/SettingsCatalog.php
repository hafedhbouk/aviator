<?php

declare(strict_types=1);

namespace App\Domain\Setup;

use App\Settings\ApprovalSettings;
use App\Settings\BillingSettings;
use App\Settings\ContractSettings;
use App\Settings\InspectionSettings;
use App\Settings\NotificationSettings;
use App\Settings\PricingSettings;
use App\Settings\ReservationSettings;
use App\Settings\SecuritySettings;
use App\Support\Authorization\Role;
use Spatie\LaravelSettings\Settings;

/**
 * Règles de validation et types des réglages modifiables depuis l'interface (A-06,
 * A-09, approbations, facturation, sécurité). Une valeur « ?int » vide = contrainte
 * ou délai désactivé. Les clés absentes d'un groupe ne sont pas modifiables ici.
 */
final class SettingsCatalog
{
    /**
     * @param  class-string<Settings>  $class
     * @return array<string, array{rules: list<mixed>, cast: string}>
     */
    public static function fields(string $class): array
    {
        return match ($class) {
            ReservationSettings::class => [
                'preparation_buffer_minutes' => ['rules' => ['required', 'integer', 'between:0,1440'], 'cast' => 'int'],
                'min_driver_age' => ['rules' => ['required', 'integer', 'between:18,99'], 'cast' => 'int'],
                'min_license_years' => ['rules' => ['required', 'integer', 'between:0,60'], 'cast' => 'int'],
                'min_rental_days' => ['rules' => ['nullable', 'integer', 'between:1,365'], 'cast' => '?int'],
                'min_notice_hours' => ['rules' => ['nullable', 'integer', 'between:0,720'], 'cast' => '?int'],
                'draft_expiry_hours' => ['rules' => ['nullable', 'integer', 'between:1,720'], 'cast' => '?int'],
                'no_show_after_minutes' => ['rules' => ['required', 'integer', 'between:0,1440'], 'cast' => 'int'],
                'allow_one_way' => ['rules' => ['boolean'], 'cast' => 'bool'],
                'reserved_status_window_hours' => ['rules' => ['required', 'integer', 'between:0,168'], 'cast' => 'int'],
            ],
            PricingSettings::class => [
                'day_mode' => ['rules' => ['required', 'in:'.implode(',', array_keys(PricingSettings::DAY_MODES))], 'cast' => 'string'],
                'late_tolerance_minutes' => ['rules' => ['required', 'integer', 'between:0,1440'], 'cast' => 'int'],
                'late_unit' => ['rules' => ['required', 'in:'.implode(',', array_keys(PricingSettings::LATE_UNITS))], 'cast' => 'string'],
                'fuel_liter_amount' => ['rules' => ['nullable', 'string', 'max:32'], 'cast' => '?money'],
                'fuel_service_amount' => ['rules' => ['nullable', 'string', 'max:32'], 'cast' => '?money'],
            ],
            BillingSettings::class => [
                'vat_rounding_mode' => ['rules' => ['required', 'in:'.implode(',', BillingSettings::ROUNDING_MODES)], 'cast' => 'string'],
                'payment_terms_days' => ['rules' => ['required', 'integer', 'between:0,365'], 'cast' => 'int'],
            ],
            ApprovalSettings::class => [
                'discount_threshold_percent' => ['rules' => ['required', 'regex:/^(100(\.0{1,2})?|\d{1,2}([.,]\d{1,2})?)$/'], 'cast' => 'percent'],
                'delegate_to_admin' => ['rules' => ['boolean'], 'cast' => 'bool'],
            ],
            NotificationSettings::class => [
                'send_urgent_in_request' => ['rules' => ['boolean'], 'cast' => 'bool'],
                'alerts' => ['rules' => ['required', 'array'], 'cast' => 'alerts'],
            ],
            ContractSettings::class => [
                'early_return_refund' => ['rules' => ['required', 'in:'.implode(',', array_keys(ContractSettings::EARLY_RETURN_REFUNDS))], 'cast' => 'string'],
                'template_validated' => ['rules' => ['boolean'], 'cast' => 'bool'],
                'terms_text' => ['rules' => ['nullable', 'string', 'max:30000'], 'cast' => '?text'],
            ],
            InspectionSettings::class => [
                'max_photos' => ['rules' => ['required', 'integer', 'between:1,40'], 'cast' => 'int'],
            ],
            SecuritySettings::class => [
                'require_2fa_for_agency_users' => ['rules' => ['boolean'], 'cast' => 'bool'],
            ],
            default => [],
        };
    }

    /**
     * Règles des alertes (A-09) : une alerte datée active exige son délai ; destinataires
     * parmi les profils du back-office.
     *
     * @return array<string, list<mixed>>
     */
    public static function alertRules(): array
    {
        $rules = [];
        $roles = implode(',', array_map(fn (Role $role): string => $role->value, Role::cases()));

        foreach (array_keys(NotificationSettings::ALERTS) as $event) {
            $dated = in_array($event, NotificationSettings::DATED_ALERTS, true);
            $rules["alerts.{$event}.enabled"] = ['boolean'];
            $rules["alerts.{$event}.days_before"] = $dated
                ? ["required_if_accepted:alerts.{$event}.enabled", 'nullable', 'integer', 'between:1,365']
                : ['prohibited'];
            $rules["alerts.{$event}.recipients"] = ["required_if_accepted:alerts.{$event}.enabled", 'array'];
            $rules["alerts.{$event}.recipients.*"] = ["in:{$roles}"];
        }

        return $rules;
    }
}
