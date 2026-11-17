<?php

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Resources\Users\Actions\SendPasswordResetLinkAction;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Liste des comptes : aucune suppression, ni à l'unité ni en masse (désactivation à la place).
 */
class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nom')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Adresse e-mail')
                    ->searchable(),
                TextColumn::make('profile')
                    ->label('Profil')
                    ->state(fn (User $record): string => $record->profile()?->label() ?? 'Aucun')
                    ->badge(),
                TextColumn::make('agency.name')
                    ->label('Agence par défaut')
                    ->placeholder('Toutes'),
                IconColumn::make('is_active')
                    ->label('Actif')
                    ->boolean(),
                TextColumn::make('last_login_at')
                    ->label('Dernière connexion')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Jamais')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Actif'),
            ])
            ->recordActions([
                EditAction::make(),
                SendPasswordResetLinkAction::make(),
            ])
            ->toolbarActions([]);
    }
}
