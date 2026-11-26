<?php

declare(strict_types=1);

namespace App\Domain\Setup\Actions;

use App\Domain\Setup\Exceptions\SetupException;
use App\Models\User;
use App\Models\VehicleCategory;
use App\Support\Money\AmountInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Crée ou modifie une catégorie de véhicules (A-03), avec les règles suivantes.
 *
 * - Super admin et admin du siège seulement (VehicleCategoryPolicy).
 * - Code de 2 à 16 caractères (majuscules, chiffres, « _ », « - »), unique.
 * - Surcharges facultatives : vide = valeur générale (âge, permis, délai de
 *   préparation : ReservationSettings) ou « non fixé » (caution, kilométrage,
 *   prix du km, franchise). Montants au millime, positifs ou nuls, jamais de float.
 * - Âge minimum de 18 à 99 ans ; ancienneté du permis de 0 à 60 ans ; délai de
 *   préparation de 0 à 1 440 minutes ; kilométrage inclus d'au moins 1 km par jour.
 * - Aucune catégorie supprimée (désactivation). Modification journalisée (audit).
 */
final class SaveVehicleCategory
{
    private const AMOUNTS = [
        'deposit_amount' => 'caution',
        'extra_km_amount' => 'prix du km supplémentaire',
        'insurance_excess_amount' => 'franchise',
        'late_hour_amount' => 'tarif horaire du retard',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(?VehicleCategory $category, array $data, User $actor): VehicleCategory
    {
        if (! $actor->can($category === null ? 'create' : 'update', $category ?? VehicleCategory::class)) {
            throw SetupException::notAllowed('les catégories de véhicules');
        }

        $values = $this->validated($data, $category);

        return DB::transaction(function () use ($category, $values): VehicleCategory {
            $category ??= new VehicleCategory;
            $category->fill($values);
            $category->save();

            return $category;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validated(array $data, ?VehicleCategory $category): array
    {
        $text = fn (string $key): ?string => self::text($data[$key] ?? null);

        $values = [
            'code' => ($code = $text('code')) === null ? null : mb_strtoupper($code),
            'name' => $text('name'),
            'description' => $text('description'),
            'min_driver_age' => $text('min_driver_age'),
            'min_license_years' => $text('min_license_years'),
            'included_km_per_day' => $text('included_km_per_day'),
            'preparation_buffer_minutes' => $text('preparation_buffer_minutes'),
            'sort_order' => $text('sort_order') ?? '0',
        ];

        $validated = Validator::make($values, [
            'code' => ['required', 'string', 'regex:/^[A-Z0-9_-]{2,16}$/',
                'unique:vehicle_categories,code'.($category !== null ? ','.$category->id : '')],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'min_driver_age' => ['nullable', 'integer', 'between:18,99'],
            'min_license_years' => ['nullable', 'integer', 'between:0,60'],
            'included_km_per_day' => ['nullable', 'integer', 'between:1,100000'],
            'preparation_buffer_minutes' => ['nullable', 'integer', 'between:0,1440'],
            'sort_order' => ['integer', 'between:-1000,1000'],
        ], attributes: [
            'code' => 'code',
            'name' => 'nom',
            'min_driver_age' => 'âge minimum du conducteur',
            'min_license_years' => 'ancienneté du permis',
            'included_km_per_day' => 'kilométrage inclus par jour',
            'preparation_buffer_minutes' => 'délai de préparation',
        ])->validate();

        foreach (['min_driver_age', 'min_license_years', 'included_km_per_day', 'preparation_buffer_minutes', 'sort_order'] as $key) {
            $validated[$key] = $validated[$key] === null ? null : (int) $validated[$key];
        }

        foreach (self::AMOUNTS as $key => $label) {
            try {
                $validated[$key] = AmountInput::parse($data[$key] ?? null);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([$key => "Le champ {$label} : {$exception->getMessage()}"]);
            }
        }

        $validated['is_active'] = (bool) ($data['is_active'] ?? true);

        return $validated;
    }

    private static function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
