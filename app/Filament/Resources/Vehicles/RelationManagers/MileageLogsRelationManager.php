<?php

namespace App\Filament\Resources\Vehicles\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Relevés de kilométrage du véhicule (B-03) : lecture seule, en ajout seul. Les relevés
 * et corrections se font par les actions de la fiche (RecordMileage, CorrectMileage).
 */
class MileageLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'mileageLogs';

    protected static ?string $title = 'Kilométrage';

    protected static ?string $modelLabel = 'relevé';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $km = fn (int $value): string => number_format($value, 0, ',', ' ').' km';

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->defaultSort('recorded_at', 'desc')
            ->columns([
                TextColumn::make('recorded_at')->label('Date')->dateTime('d/m/Y H:i'),
                TextColumn::make('mileage')->label('Kilométrage')->formatStateUsing(fn (int $state): string => $km($state)),
                TextColumn::make('previous_mileage')->label('Précédent')->formatStateUsing(fn (int $state): string => $km($state)),
                IconColumn::make('is_correction')->label('Correction')->boolean(),
                TextColumn::make('reason')->label('Motif')->placeholder('—')->wrap(),
                TextColumn::make('user.name')->label('Par')->placeholder('Automatique'),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
