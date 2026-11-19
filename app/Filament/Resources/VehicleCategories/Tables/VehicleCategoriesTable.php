<?php

namespace App\Filament\Resources\VehicleCategories\Tables;

use App\Support\Money\Money;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Liste des catégories : aucune suppression, ni à l'unité ni en masse.
 */
class VehicleCategoriesTable
{
    public static function configure(Table $table): Table
    {
        $amount = fn (?Money $state): string => $state?->format() ?? 'Non fixé';

        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('code')->label('Code')->sortable(),
                TextColumn::make('name')->label('Nom')->searchable()->sortable(),
                TextColumn::make('deposit_amount')->label('Caution')->formatStateUsing($amount)->placeholder('Non fixé'),
                TextColumn::make('insurance_excess_amount')->label('Franchise')->formatStateUsing($amount)->placeholder('Non fixé'),
                TextColumn::make('included_km_per_day')->label('Km inclus par jour')->placeholder('Illimité'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}
