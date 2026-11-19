<?php

namespace App\Filament\Resources\Vehicles\Schemas;

use App\Enums\FinancingMode;
use App\Enums\FuelType;
use App\Enums\Transmission;
use App\Filament\Forms\AmountField;
use App\Models\Agency;
use App\Models\Supplier;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Fiche véhicule (B-01, B-02, B-04). Le statut et le kilométrage ne se saisissent pas
 * ici (lots L4 et L5) ; ils sont affichés en lecture.
 */
class VehicleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identification')
                    ->columns(3)
                    ->schema([
                        TextInput::make('internal_code')->label('Code interne')->required()->maxLength(16)
                            ->helperText('2 à 16 lettres, chiffres, « _ » ou « - ».'),
                        TextInput::make('plate_number')->label('Immatriculation')->required()->maxLength(16)
                            ->placeholder('123 TU 4567'),
                        TextInput::make('vin')->label('Numéro de châssis')->required()->maxLength(32),
                        Select::make('vehicle_category_id')->label('Catégorie')->required()
                            ->options(fn (?Vehicle $record): array => VehicleCategory::query()
                                ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $record?->vehicle_category_id))
                                ->orderBy('sort_order')->pluck('name', 'id')->all()),
                        TextInput::make('brand')->label('Marque')->required()->maxLength(64),
                        TextInput::make('model')->label('Modèle')->required()->maxLength(64),
                        TextInput::make('year')->label('Année')->required()->integer()->minValue(1980)->maxValue(now()->year + 1),
                        TextInput::make('color')->label('Couleur')->maxLength(32),
                        Select::make('fuel')->label('Carburant')->required()->options(self::options(FuelType::cases())),
                        Select::make('transmission')->label('Boîte de vitesses')->required()->options(self::options(Transmission::cases())),
                    ]),
                Section::make('Agence')
                    ->columns(2)
                    ->schema([
                        Select::make('agency_id')->label('Agence de rattachement')->required()
                            ->options(fn (?Vehicle $record): array => Agency::query()
                                ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $record?->agency_id))
                                ->orderBy('name')->pluck('name', 'id')->all())
                            ->helperText('À la création, le véhicule se trouve dans cette agence. Sa localisation suit ensuite ses mouvements.'),
                        TextInput::make('current_agency')->label('Localisation actuelle')
                            ->formatStateUsing(fn (?Vehicle $record): string => $record?->currentAgency->name ?? '—')
                            ->disabled()->dehydrated(false)
                            ->visibleOn(['view', 'edit']),
                        TextInput::make('status_label')->label('Statut')
                            ->formatStateUsing(fn (?Vehicle $record): string => $record?->status->label() ?? '—')
                            ->disabled()->dehydrated(false)
                            ->visibleOn(['view', 'edit']),
                        TextInput::make('mileage_label')->label('Kilométrage')
                            ->formatStateUsing(fn (?Vehicle $record): string => $record === null ? '—' : number_format($record->mileage, 0, ',', ' ').' km')
                            ->disabled()->dehydrated(false)
                            ->visibleOn(['view', 'edit']),
                    ]),
                Section::make('Acquisition')
                    ->description('Données de gestion, sans calcul d\'amortissement (B-02). Le contrat de leasing ou de crédit se saisit à part.')
                    ->columns(3)
                    ->schema([
                        DatePicker::make('acquired_on')->label('Date d\'acquisition')->maxDate(now()),
                        Select::make('acquisition_supplier_id')->label('Fournisseur')
                            ->options(fn (): array => Supplier::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable(),
                        Select::make('financing_mode')->label('Mode de financement')->required()
                            ->options(self::options(FinancingMode::cases()))->default(FinancingMode::Cash->value),
                        AmountField::make('purchase_amount')->label('Prix d\'achat'),
                        AmountField::make('initial_contribution_amount')->label('Apport initial'),
                        TextInput::make('planned_holding_months')->label('Durée de détention prévue (mois)')->integer()
                            ->minValue(1)->maxValue(240)->helperText('Sert à la rentabilité (Q19).'),
                    ]),
            ]);
    }

    /**
     * @param  list<FuelType|Transmission|FinancingMode>  $cases
     * @return array<string, string>
     */
    private static function options(array $cases): array
    {
        $options = [];

        foreach ($cases as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
