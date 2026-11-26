<?php

namespace App\Filament\Resources\Reservations\Tables;

use App\Enums\ReservationStatus;
use App\Models\Agency;
use App\Models\Reservation;
use App\Support\Money\Money;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Liste des réservations des agences de l'utilisateur (RG-13). Nom du client seulement
 * (D13 : aucune coordonnée), aucune suppression, aucun export.
 */
class ReservationsTable
{
    public static function configure(Table $table): Table
    {
        $at = fn ($state): string => $state?->timezone((string) config('app.timezone'))->format('d/m/Y H:i') ?? '—';

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['customer', 'category', 'vehicle', 'agency']))
            ->defaultSort('starts_at')
            ->columns([
                TextColumn::make('number')->label('Numéro')->searchable()->sortable(),
                TextColumn::make('customer_name')->label('Client')
                    ->state(fn (Reservation $record): string => $record->customer->displayName()),
                TextColumn::make('starts_at')->label('Départ')->formatStateUsing($at)->sortable(),
                TextColumn::make('ends_at')->label('Retour')->formatStateUsing($at),
                TextColumn::make('category.name')->label('Catégorie'),
                TextColumn::make('vehicle.plate_number')->label('Véhicule')->placeholder('À affecter'),
                TextColumn::make('estimated_total_amount')->label('Total HT')
                    ->formatStateUsing(fn (?Money $state): string => $state?->format() ?? '—'),
                TextColumn::make('status')->label('Statut')->badge()
                    ->formatStateUsing(fn (ReservationStatus $state): string => $state->label())
                    ->color(fn (ReservationStatus $state): string => $state->color()),
                TextColumn::make('agency.name')->label('Agence'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Statut')
                    ->options(collect(ReservationStatus::cases())->mapWithKeys(fn (ReservationStatus $s): array => [$s->value => $s->label()])->all()),
                SelectFilter::make('agency_id')->label('Agence')
                    ->options(fn (): array => Agency::query()->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
