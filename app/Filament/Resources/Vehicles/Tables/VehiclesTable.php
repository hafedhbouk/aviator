<?php

namespace App\Filament\Resources\Vehicles\Tables;

use App\Enums\VehicleStatus;
use App\Models\Agency;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Liste des véhicules : aucune suppression, ni à l'unité ni en masse ; aucun export.
 */
class VehiclesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['category', 'agency', 'currentAgency']))
            ->defaultSort('plate_number')
            ->columns([
                TextColumn::make('plate_number')->label('Immatriculation')->searchable()->sortable(),
                TextColumn::make('internal_code')->label('Code')->searchable()->sortable(),
                TextColumn::make('model')->label('Véhicule')
                    ->state(fn (Vehicle $record): string => "{$record->brand} {$record->model}"),
                TextColumn::make('category.name')->label('Catégorie'),
                TextColumn::make('agency.name')->label('Rattachement'),
                TextColumn::make('currentAgency.name')->label('Localisation'),
                TextColumn::make('status')->label('Statut')->badge()
                    ->formatStateUsing(fn (VehicleStatus $state): string => $state->label())
                    ->color(fn (VehicleStatus $state): string => $state->color()),
                TextColumn::make('mileage')->label('Kilométrage')->numeric(thousandsSeparator: ' ')->suffix(' km')->sortable(),
            ])
            ->filters([
                SelectFilter::make('vehicle_category_id')->label('Catégorie')
                    ->options(fn (): array => VehicleCategory::query()->orderBy('sort_order')->pluck('name', 'id')->all()),
                SelectFilter::make('agency_id')->label('Rattachement')
                    ->options(fn (): array => Agency::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('status')->label('Statut')
                    ->options(collect(VehicleStatus::cases())->mapWithKeys(fn (VehicleStatus $s): array => [$s->value => $s->label()])->all()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}
