<?php

namespace App\Filament\Resources\Agencies;

use App\Filament\Resources\Agencies\Pages\CreateAgency;
use App\Filament\Resources\Agencies\Pages\EditAgency;
use App\Filament\Resources\Agencies\Pages\ListAgencies;
use App\Filament\Resources\Agencies\Pages\ViewAgency;
use App\Filament\Resources\Agencies\RelationManagers\LocationsRelationManager;
use App\Filament\Resources\Agencies\Schemas\AgencyForm;
use App\Filament\Resources\Agencies\Tables\AgenciesTable;
use App\Models\Agency;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Agences et leurs lieux (A-02) : super admin et admin en écriture ; l'utilisateur
 * d'agence consulte ses agences autorisées seulement (AgencyPolicy).
 */
class AgencyResource extends Resource
{
    protected static ?string $model = Agency::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'Paramétrage';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'agence';

    protected static ?string $pluralModelLabel = 'agences';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $slug = 'parametrage/agences';

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return AgencyForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AgenciesTable::configure($table);
    }

    /**
     * L'utilisateur d'agence ne voit que ses agences autorisées : une autre agence
     * est introuvable (404) par l'adresse.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user instanceof User && ! $user->canActOnAllAgencies()) {
            $query->whereKey($user->authorizedAgencyIds());
        }

        return $query;
    }

    public static function getRelations(): array
    {
        return [
            LocationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgencies::route('/'),
            'create' => CreateAgency::route('/create'),
            'view' => ViewAgency::route('/{record}'),
            'edit' => EditAgency::route('/{record}/edit'),
        ];
    }
}
