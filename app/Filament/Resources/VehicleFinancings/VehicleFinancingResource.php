<?php

namespace App\Filament\Resources\VehicleFinancings;

use App\Enums\FinancingOutcome;
use App\Enums\FinancingType;
use App\Filament\Resources\VehicleFinancings\Pages\CreateVehicleFinancing;
use App\Filament\Resources\VehicleFinancings\Pages\EditVehicleFinancing;
use App\Filament\Resources\VehicleFinancings\Pages\ListVehicleFinancings;
use App\Filament\Resources\VehicleFinancings\Pages\ViewVehicleFinancing;
use App\Filament\Resources\VehicleFinancings\RelationManagers\InstallmentsRelationManager;
use App\Filament\Resources\VehicleFinancings\Schemas\VehicleFinancingForm;
use App\Models\VehicleFinancing;
use App\Support\Money\Money;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Leasing et crédit des véhicules (B-11 à B-15) : super admin et admin seulement
 * (VehicleFinancingPolicy). Aucune suppression.
 */
class VehicleFinancingResource extends Resource
{
    protected static ?string $model = VehicleFinancing::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Parc';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'financement';

    protected static ?string $pluralModelLabel = 'financements';

    protected static ?string $navigationLabel = 'Financements';

    protected static ?string $recordTitleAttribute = 'reference';

    protected static ?string $slug = 'parc/financements';

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return VehicleFinancingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        $money = fn (?Money $state): string => $state?->format() ?? '—';

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['vehicle', 'supplier']))
            ->defaultSort('starts_on', 'desc')
            ->columns([
                TextColumn::make('vehicle.plate_number')->label('Véhicule')->searchable(),
                TextColumn::make('type')->label('Type')->formatStateUsing(fn (FinancingType $state): string => $state->label()),
                TextColumn::make('supplier.name')->label('Société'),
                TextColumn::make('reference')->label('Référence')->searchable(),
                TextColumn::make('starts_on')->label('Début')->date('d/m/Y'),
                TextColumn::make('ends_on')->label('Fin')->date('d/m/Y'),
                TextColumn::make('monthly_amount_excl_tax')->label('Mensualité HT')->formatStateUsing($money),
                TextColumn::make('outcome')->label('Issue')->badge()
                    ->formatStateUsing(fn (?FinancingOutcome $state): string => $state?->label() ?? 'En cours')
                    ->placeholder('En cours'),
            ])
            ->filters([
                SelectFilter::make('type')->label('Type')
                    ->options(collect(FinancingType::cases())->mapWithKeys(fn (FinancingType $t): array => [$t->value => $t->label()])->all()),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->toolbarActions([]);
    }

    public static function getRelations(): array
    {
        return [InstallmentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVehicleFinancings::route('/'),
            'create' => CreateVehicleFinancing::route('/create'),
            'view' => ViewVehicleFinancing::route('/{record}'),
            'edit' => EditVehicleFinancing::route('/{record}/edit'),
        ];
    }
}
