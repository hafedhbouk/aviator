<?php

namespace App\Filament\Resources\Contracts\Tables;

use App\Enums\ContractStatus;
use App\Models\Agency;
use App\Models\Contract;
use App\Support\Money\Money;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Liste des contrats des agences de l'utilisateur (RG-13). Nom du client seulement
 * (D13 : aucune coordonnée), aucune suppression, aucun export.
 */
class ContractsTable
{
    public static function configure(Table $table): Table
    {
        $at = fn ($state): string => $state?->timezone((string) config('app.timezone'))->format('d/m/Y H:i') ?? '—';

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['customer', 'vehicle', 'agency']))
            ->defaultSort('starts_at', 'desc')
            ->columns([
                TextColumn::make('number')->label('Numéro')->searchable()->sortable(),
                TextColumn::make('customer_name')->label('Client')
                    ->state(fn (Contract $record): string => $record->customer->displayName()),
                TextColumn::make('vehicle.plate_number')->label('Véhicule'),
                TextColumn::make('starts_at')->label('Départ prévu')->formatStateUsing($at)->sortable(),
                TextColumn::make('ends_at')->label('Retour prévu')->formatStateUsing($at),
                TextColumn::make('total_amount')->label('Total HT')
                    ->formatStateUsing(fn (?Money $state): string => $state?->format() ?? '—'),
                TextColumn::make('status')->label('Statut')->badge()
                    ->formatStateUsing(fn (ContractStatus $state): string => $state->label())
                    ->color(fn (ContractStatus $state): string => $state->color()),
                TextColumn::make('agency.name')->label('Agence'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Statut')
                    ->options(collect(ContractStatus::cases())->mapWithKeys(fn (ContractStatus $s): array => [$s->value => $s->label()])->all()),
                SelectFilter::make('agency_id')->label('Agence')
                    ->options(fn (): array => Agency::query()->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
