<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Actions;

use App\Domain\Fleet\DocumentStatusSync;
use App\Domain\Fleet\Exceptions\FleetException;
use App\Domain\Fleet\PlateNumber;
use App\Domain\Fleet\VehicleStatusService;
use App\Enums\FinancingMode;
use App\Enums\FuelType;
use App\Enums\Transmission;
use App\Enums\VehicleStatus;
use App\Models\Agency;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Support\Money\AmountInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Crée ou modifie la fiche d'un véhicule (B-01, B-02, B-04), avec les règles suivantes.
 *
 * - Super admin et admin du siège seulement (VehiclePolicy, fleet.manage).
 * - Code interne, immatriculation et numéro de châssis uniques ; l'immatriculation
 *   est comparée sous sa forme canonique (PlateNumber), le châssis en majuscules
 *   sans espace.
 * - Catégorie et agence de rattachement actives. À la création, le véhicule se trouve
 *   dans son agence de rattachement ; sa localisation change ensuite avec les
 *   mouvements (retours, transferts : Q3), jamais par ce formulaire.
 * - Ni le statut (VehicleStatusService) ni le kilométrage (RecordMileage,
 *   CorrectMileage) ne sont modifiables ici ; à la création seulement, un relevé
 *   initial facultatif (`initial_mileage`) ouvre l'historique du kilométrage.
 *   Un véhicule sorti du parc n'est plus modifiable.
 * - Montants au millime, positifs ou nuls, jamais de float. Données de gestion,
 *   sans calcul d'amortissement (B-02).
 * - Aucune suppression. Modification journalisée (audit).
 */
final class SaveVehicle
{
    private const AMOUNTS = [
        'purchase_amount' => 'prix d\'achat',
        'initial_contribution_amount' => 'apport initial',
    ];

    public function __construct(
        private readonly VehicleStatusService $statuses,
        private readonly DocumentStatusSync $documents,
        private readonly RecordMileage $mileage,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(?Vehicle $vehicle, array $data, User $actor): Vehicle
    {
        if (! $actor->can($vehicle === null ? 'create' : 'update', $vehicle ?? Vehicle::class)) {
            throw FleetException::notAllowed('les véhicules');
        }

        if ($vehicle !== null && $vehicle->status === VehicleStatus::Retired) {
            throw FleetException::vehicleRetired($vehicle->label());
        }

        $values = $this->validated($data, $vehicle);
        $initialMileage = $vehicle === null ? $this->initialMileage($data['initial_mileage'] ?? null) : null;

        return DB::transaction(function () use ($vehicle, $values, $actor, $initialMileage): Vehicle {
            $category = VehicleCategory::query()->lockForUpdate()->findOrFail($values['vehicle_category_id']);
            if (! $category->is_active && $category->id !== $vehicle?->vehicle_category_id) {
                throw FleetException::inactiveCategory();
            }

            $agency = Agency::query()->findOrFail($values['agency_id']);
            if (! $agency->is_active && $agency->id !== $vehicle?->agency_id) {
                throw FleetException::inactiveAgency();
            }

            $creating = $vehicle === null;

            if ($vehicle === null) {
                $vehicle = new Vehicle;
                $vehicle->current_agency_id = $agency->id;
            }

            $vehicle->fill($values);
            $vehicle->save();

            // Valeurs par défaut posées par la base à la création (statut, kilométrage).
            $vehicle->refresh();

            if ($creating) {
                // Historique dès la création, puis statut selon les documents (D41) :
                // sans assurance ni visite technique valides, le véhicule est indisponible.
                $this->statuses->recordCreation($vehicle, $actor);
                $this->documents->sync($vehicle, $actor);

                // Relevé initial facultatif (B-03), premier relevé de l'historique.
                if ($initialMileage !== null) {
                    $this->mileage->handle($vehicle, $initialMileage, $actor, reason: 'Relevé initial');
                }
            }

            return $vehicle->refresh();
        });
    }

    private function initialMileage(mixed $value): ?int
    {
        $text = self::text($value);

        if ($text === null) {
            return null;
        }

        $digits = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $text) ?? '';

        if (preg_match('/^\d{1,7}$/', $digits) !== 1 || (int) $digits > RecordMileage::MAX_MILEAGE) {
            throw ValidationException::withMessages([
                'initial_mileage' => 'Kilométrage initial invalide : nombre entier de 0 à 2 000 000 km.',
            ]);
        }

        return (int) $digits;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validated(array $data, ?Vehicle $vehicle): array
    {
        $text = fn (string $key): ?string => self::text($data[$key] ?? null);
        $ignore = $vehicle?->id;

        $values = [
            'internal_code' => ($code = $text('internal_code')) === null ? null : mb_strtoupper($code),
            'plate_number' => ($plate = $text('plate_number')) === null ? null : PlateNumber::canonical($plate),
            'vin' => ($vin = $text('vin')) === null ? null : mb_strtoupper((string) preg_replace('/\s+/u', '', $vin)),
            'vehicle_category_id' => $text('vehicle_category_id'),
            'brand' => $text('brand'),
            'model' => $text('model'),
            'year' => $text('year'),
            'color' => $text('color'),
            'fuel' => $text('fuel'),
            'transmission' => $text('transmission'),
            'agency_id' => $text('agency_id'),
            'acquired_on' => $text('acquired_on'),
            'acquisition_supplier_id' => $text('acquisition_supplier_id'),
            'financing_mode' => $text('financing_mode'),
            'planned_holding_months' => $text('planned_holding_months'),
        ];

        $validated = Validator::make($values, [
            'internal_code' => ['required', 'string', 'regex:/^[A-Z0-9_-]{2,16}$/', Rule::unique('vehicles', 'internal_code')->ignore($ignore)],
            'plate_number' => ['required', 'string', 'min:2', 'max:16', Rule::unique('vehicles', 'plate_number')->ignore($ignore)],
            'vin' => ['required', 'string', 'regex:/^[A-Z0-9]{5,32}$/', Rule::unique('vehicles', 'vin')->ignore($ignore)],
            'vehicle_category_id' => ['required', 'integer', 'exists:vehicle_categories,id'],
            'brand' => ['required', 'string', 'max:64'],
            'model' => ['required', 'string', 'max:64'],
            'year' => ['required', 'integer', 'between:1980,'.(now()->year + 1)],
            'color' => ['nullable', 'string', 'max:32'],
            'fuel' => ['required', Rule::enum(FuelType::class)],
            'transmission' => ['required', Rule::enum(Transmission::class)],
            'agency_id' => ['required', 'integer', 'exists:agencies,id'],
            'acquired_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'acquisition_supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'financing_mode' => ['required', Rule::enum(FinancingMode::class)],
            'planned_holding_months' => ['nullable', 'integer', 'between:1,240'],
        ], [
            'internal_code.regex' => 'Le code interne compte 2 à 16 lettres, chiffres, « _ » ou « - ».',
            'vin.regex' => 'Le numéro de châssis compte 5 à 32 lettres ou chiffres.',
        ], [
            'internal_code' => 'code interne',
            'plate_number' => 'immatriculation',
            'vin' => 'numéro de châssis',
            'vehicle_category_id' => 'catégorie',
            'brand' => 'marque',
            'model' => 'modèle',
            'year' => 'année',
            'color' => 'couleur',
            'fuel' => 'carburant',
            'transmission' => 'boîte de vitesses',
            'agency_id' => 'agence de rattachement',
            'acquired_on' => 'date d\'acquisition',
            'acquisition_supplier_id' => 'fournisseur',
            'financing_mode' => 'mode de financement',
            'planned_holding_months' => 'durée de détention prévue',
        ])->validate();

        foreach (['vehicle_category_id', 'year', 'agency_id', 'acquisition_supplier_id', 'planned_holding_months'] as $key) {
            $validated[$key] = $validated[$key] === null ? null : (int) $validated[$key];
        }

        foreach (self::AMOUNTS as $key => $label) {
            try {
                $validated[$key] = AmountInput::parse($data[$key] ?? null);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([$key => "Le champ {$label} : {$exception->getMessage()}"]);
            }
        }

        return $validated;
    }

    private static function text(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
