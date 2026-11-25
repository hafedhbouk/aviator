<?php

namespace App\Filament\Resources\Vehicles;

use App\Filament\Resources\Vehicles\Pages\CreateVehicle;
use App\Filament\Resources\Vehicles\Pages\EditVehicle;
use App\Filament\Resources\Vehicles\Pages\ListVehicles;
use App\Filament\Resources\Vehicles\Pages\ViewVehicle;
use App\Filament\Resources\Vehicles\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Vehicles\RelationManagers\MileageLogsRelationManager;
use App\Filament\Resources\Vehicles\RelationManagers\StatusLogsRelationManager;
use App\Filament\Resources\Vehicles\Schemas\VehicleForm;
use App\Filament\Resources\Vehicles\Tables\VehiclesTable;
use App\Models\Vehicle;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Véhicules (module B) : super admin et admin en écriture ; l'utilisateur d'agence
 * lit les véhicules localisés dans ses agences (VehiclePolicy, AgencyScope).
 */
class VehicleResource extends Resource
{
    protected static ?string $model = Vehicle::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Parc';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'véhicule';

    protected static ?string $pluralModelLabel = 'véhicules';

    protected static ?string $navigationLabel = 'Véhicules';

    protected static ?string $recordTitleAttribute = 'plate_number';

    protected static ?string $slug = 'parc/vehicules';

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return VehicleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VehiclesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            DocumentsRelationManager::class,
            StatusLogsRelationManager::class,
            MileageLogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVehicles::route('/'),
            'create' => CreateVehicle::route('/create'),
            'view' => ViewVehicle::route('/{record}'),
            'edit' => EditVehicle::route('/{record}/edit'),
        ];
    }
}
