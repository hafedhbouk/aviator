<?php

namespace App\Filament\Resources\Reservations;

use App\Filament\Resources\Reservations\Pages\CreateReservation;
use App\Filament\Resources\Reservations\Pages\ListReservations;
use App\Filament\Resources\Reservations\Pages\ViewReservation;
use App\Filament\Resources\Reservations\Schemas\ReservationInfolist;
use App\Filament\Resources\Reservations\Tables\ReservationsTable;
use App\Models\Reservation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Réservations (module C) : chaque profil voit et modifie les réservations des agences
 * où il agit (RG-13, BelongsToAgency, ReservationPolicy). Aucune suppression (RG-11) :
 * annulation motivée, non-présentation, expiration. Toute écriture passe par les
 * actions de App\Domain\Availability\Actions.
 */
class ReservationResource extends Resource
{
    protected static ?string $model = Reservation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Comptoir';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'réservation';

    protected static ?string $pluralModelLabel = 'réservations';

    protected static ?string $navigationLabel = 'Réservations';

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $slug = 'comptoir/reservations';

    protected static bool $isGloballySearchable = false;

    public static function infolist(Schema $schema): Schema
    {
        return ReservationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ReservationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReservations::route('/'),
            'create' => CreateReservation::route('/nouvelle'),
            'view' => ViewReservation::route('/{record}'),
        ];
    }
}
