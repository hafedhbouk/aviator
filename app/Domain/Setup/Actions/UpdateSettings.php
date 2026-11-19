<?php

declare(strict_types=1);

namespace App\Domain\Setup\Actions;

use App\Domain\Setup\Exceptions\SetupException;
use App\Domain\Setup\SettingsCatalog;
use App\Models\User;
use App\Settings\NotificationSettings;
use App\Support\Authorization\Permission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Spatie\LaravelSettings\Settings;

/**
 * Enregistre un groupe de réglages (A-06, A-09, approbations, facturation, sécurité).
 *
 * - Super admin seulement (permission settings.manage).
 * - Seules les clés du catalogue (SettingsCatalog) sont modifiables, validées et typées.
 * - Journal « audit » : entrée « settings_updated » avec le groupe, l'avant et l'après
 *   des seules clés modifiées ; rien n'est journalisé si rien ne change.
 */
final class UpdateSettings
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Settings $settings, array $data, User $actor): Settings
    {
        if (! $actor->can(Permission::SettingsManage->value)) {
            throw SetupException::notAllowed('les réglages');
        }

        $fields = SettingsCatalog::fields($settings::class);
        $input = array_intersect_key($data, $fields);
        // Seules les clés reçues sont validées : une page peut n'en modifier qu'une partie.
        $rules = array_map(fn (array $field): array => $field['rules'], array_intersect_key($fields, $input));

        if ($settings instanceof NotificationSettings && array_key_exists('alerts', $input)) {
            $rules += SettingsCatalog::alertRules();
        }

        $validated = Validator::make($input, $rules)->validate();

        $old = [];
        $new = [];

        foreach ($fields as $key => $field) {
            if (! array_key_exists($key, $input)) {
                continue;
            }

            $value = self::cast($field['cast'], $validated[$key] ?? null);

            if ($settings->{$key} !== $value) {
                $old[$key] = $settings->{$key};
                $new[$key] = $value;
                $settings->{$key} = $value;
            }
        }

        if ($new === []) {
            return $settings;
        }

        DB::transaction(function () use ($settings, $actor, $old, $new): void {
            $settings->save();

            activity('audit')
                ->causedBy($actor)
                ->event('settings_updated')
                ->withProperties(['group' => $settings::group(), 'old' => $old, 'attributes' => $new])
                ->log("Réglages « {$settings::group()} » modifiés");
        });

        return $settings;
    }

    private static function cast(string $cast, mixed $value): mixed
    {
        return match ($cast) {
            'int' => (int) $value,
            '?int' => $value === null || $value === '' ? null : (int) $value,
            'bool' => (bool) $value,
            'percent' => self::percent((string) $value),
            'alerts' => self::alerts(is_array($value) ? $value : []),
            default => (string) $value,
        };
    }

    /**
     * « 5 », « 5,5 » → « 5.00 », « 5.50 » (sans float).
     */
    private static function percent(string $value): string
    {
        [$integer, $decimals] = array_pad(explode('.', str_replace(',', '.', $value)), 2, '');

        return ((string) (int) $integer).'.'.str_pad($decimals, 2, '0');
    }

    /**
     * @param  array<mixed>  $value
     * @return array<string, array{enabled: bool, days_before: int|null, recipients: list<string>}>
     */
    private static function alerts(array $value): array
    {
        $alerts = [];

        foreach (array_keys(NotificationSettings::ALERTS) as $event) {
            $alert = is_array($value[$event] ?? null) ? $value[$event] : [];
            $days = $alert['days_before'] ?? null;
            $recipients = is_array($alert['recipients'] ?? null) ? $alert['recipients'] : [];

            $alerts[$event] = [
                'enabled' => (bool) ($alert['enabled'] ?? false),
                'days_before' => $days === null || $days === '' ? null : (int) $days,
                'recipients' => array_values(array_map(fn (mixed $r): string => (string) $r, $recipients)),
            ];
        }

        return $alerts;
    }
}
