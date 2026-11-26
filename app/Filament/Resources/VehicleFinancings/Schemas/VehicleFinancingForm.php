<?php

namespace App\Filament\Resources\VehicleFinancings\Schemas;

use App\Enums\FinancingType;
use App\Enums\VehicleStatus;
use App\Filament\Forms\AmountField;
use App\Models\Supplier;
use App\Models\Vehicle;
use App\Models\VehicleFinancing;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Formulaire d'un leasing ou d'un crédit. L'échéancier est généré par le domaine
 * (SaveFinancing) ; rien n'est calculé ici.
 */
class VehicleFinancingForm
{
    public static function configure(Schema $schema): Schema
    {
        $leasing = fn (Get $get): bool => $get('type') !== FinancingType::Credit->value;

        return $schema->components([
            Section::make('Financement')->columnSpanFull()->columns(3)->schema([
                Select::make('vehicle_id')->label('Véhicule')->required()
                    ->options(fn (?VehicleFinancing $record): array => Vehicle::query()->withoutGlobalScopes()
                        ->where(fn ($query) => $query->where('status', '!=', VehicleStatus::Retired->value)->orWhere('id', $record?->vehicle_id))
                        ->orderBy('plate_number')->get()
                        ->mapWithKeys(fn (Vehicle $vehicle): array => [$vehicle->id => $vehicle->label()])->all())
                    ->searchable()
                    ->disabledOn('edit'),
                Radio::make('type')->label('Type')->required()->live()->inline()
                    ->options(collect(FinancingType::cases())->mapWithKeys(fn (FinancingType $t): array => [$t->value => $t->label()])->all())
                    ->default(FinancingType::Leasing->value),
                Select::make('supplier_id')->label('Société de leasing ou banque')->required()
                    ->options(fn (): array => Supplier::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                TextInput::make('reference')->label('Référence du contrat')->required()->maxLength(64),
                DatePicker::make('starts_on')->label('Début')->required(),
                DatePicker::make('first_due_on')->label('Première échéance')->required()->afterOrEqual('starts_on'),
                AmountField::make('initial_payment_amount')->label('Apport initial (premier loyer majoré pour un leasing)'),
            ]),
            Section::make('Leasing')->columnSpanFull()->columns(3)->visible($leasing)->schema([
                DatePicker::make('ends_on')->label('Fin du contrat')->required($leasing)->afterOrEqual('first_due_on'),
                AmountField::make('monthly_amount_excl_tax')->label('Loyer mensuel HT')->required($leasing),
                AmountField::make('monthly_amount_incl_tax')->label('Loyer mensuel TTC')->required($leasing)
                    ->helperText('Tel que figurant au contrat : aucun taux de TVA n\'est déduit.'),
                AmountField::make('residual_value_amount')->label('Valeur résiduelle'),
                DatePicker::make('purchase_option_on')->label('Date de levée d\'option'),
            ]),
            Section::make('Crédit bancaire')->columnSpanFull()->columns(3)->visible(fn (Get $get): bool => ! $leasing($get))->schema([
                AmountField::make('principal_amount')->label('Capital emprunté')->required(fn (Get $get): bool => ! $leasing($get)),
                TextInput::make('interest_rate')->label('Taux annuel (%)')->required(fn (Get $get): bool => ! $leasing($get))
                    ->regex('/^\d{1,2}([.,]\d{1,4})?$/'),
                TextInput::make('installments_count')->label('Nombre de mensualités')->integer()->minValue(1)->maxValue(360)
                    ->required(fn (Get $get): bool => ! $leasing($get)),
            ]),
        ]);
    }
}
