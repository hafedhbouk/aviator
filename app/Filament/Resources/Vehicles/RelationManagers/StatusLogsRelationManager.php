<?php

namespace App\Filament\Resources\Vehicles\RelationManagers;

use App\Enums\VehicleStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Historique des statuts du véhicule (B-06, B-09) : lecture seule, en ajout seul.
 */
class StatusLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'statusLogs';

    protected static ?string $title = 'Historique des statuts';

    protected static ?string $modelLabel = 'changement de statut';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->defaultSort('occurred_at', 'desc')
            ->columns([
                TextColumn::make('occurred_at')->label('Date')->dateTime('d/m/Y H:i'),
                TextColumn::make('from_status')->label('Avant')
                    ->formatStateUsing(fn (?VehicleStatus $state): string => $state?->label() ?? '—')->placeholder('—'),
                TextColumn::make('to_status')->label('Après')->badge()
                    ->formatStateUsing(fn (VehicleStatus $state): string => $state->label())
                    ->color(fn (VehicleStatus $state): string => $state->color()),
                TextColumn::make('reason')->label('Motif')->placeholder('—')->wrap(),
                TextColumn::make('user.name')->label('Par')->placeholder('Automatique'),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
