<?php

namespace App\Filament\Resources\Contracts;

use App\Filament\Resources\Contracts\Pages\ListContracts;
use App\Filament\Resources\Contracts\Pages\ViewContract;
use App\Filament\Resources\Contracts\Schemas\ContractInfolist;
use App\Filament\Resources\Contracts\Tables\ContractsTable;
use App\Models\Contract;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Contrats (module D) : chaque profil voit et modifie les contrats des agences où il
 * agit (RG-13, BelongsToAgency, ContractPolicy). Aucune suppression (RG-11) : un
 * brouillon s'annule avec motif. Création depuis la fiche d'une réservation confirmée
 * ou par « Louer maintenant » dans l'assistant du comptoir ; toute écriture passe par
 * les actions de App\Domain\Rental.
 */
class ContractResource extends Resource
{
    protected static ?string $model = Contract::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Comptoir';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'contrat';

    protected static ?string $pluralModelLabel = 'contrats';

    protected static ?string $navigationLabel = 'Contrats';

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $slug = 'comptoir/contrats';

    protected static bool $isGloballySearchable = false;

    public static function infolist(Schema $schema): Schema
    {
        return ContractInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContractsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContracts::route('/'),
            'view' => ViewContract::route('/{record}'),
        ];
    }
}
