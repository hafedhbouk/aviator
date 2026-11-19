<?php

namespace App\Filament\Resources\VehicleCategories;

use App\Filament\Resources\VehicleCategories\Pages\CreateVehicleCategory;
use App\Filament\Resources\VehicleCategories\Pages\EditVehicleCategory;
use App\Filament\Resources\VehicleCategories\Pages\ListVehicleCategories;
use App\Filament\Resources\VehicleCategories\Pages\ViewVehicleCategory;
use App\Filament\Resources\VehicleCategories\Schemas\VehicleCategoryForm;
use App\Filament\Resources\VehicleCategories\Tables\VehicleCategoriesTable;
use App\Models\VehicleCategory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Catégories de véhicules (A-03) : super admin et admin en écriture ; lecture pour
 * l'utilisateur d'agence (VehicleCategoryPolicy).
 */
class VehicleCategoryResource extends Resource
{
    protected static ?string $model = VehicleCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Paramétrage';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'catégorie de véhicules';

    protected static ?string $pluralModelLabel = 'catégories de véhicules';

    protected static ?string $navigationLabel = 'Catégories';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $slug = 'parametrage/categories';

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return VehicleCategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VehicleCategoriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVehicleCategories::route('/'),
            'create' => CreateVehicleCategory::route('/create'),
            'view' => ViewVehicleCategory::route('/{record}'),
            'edit' => EditVehicleCategory::route('/{record}/edit'),
        ];
    }
}
