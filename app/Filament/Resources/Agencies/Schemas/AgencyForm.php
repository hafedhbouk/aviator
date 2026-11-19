<?php

namespace App\Filament\Resources\Agencies\Schemas;

use App\Models\Agency;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Formulaire d'une agence. Le code se saisit à la création seulement ; le siège ne
 * se change pas ici (SaveAgency).
 */
class AgencyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Agence')
                    ->columns(2)
                    ->schema([
                        TextInput::make('code')
                            ->label('Code')
                            ->required()
                            ->regex('/^[A-Za-z0-9]{2,8}$/')
                            ->maxLength(8)
                            ->disabledOn('edit')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create')
                            ->helperText('2 à 8 lettres ou chiffres ; fixé à la création.'),
                        TextInput::make('name')->label('Nom')->required()->maxLength(100),
                        TextInput::make('city')->label('Ville')->required()->maxLength(100),
                        TextInput::make('address')->label('Adresse')->maxLength(255),
                        TextInput::make('phone')->label('Téléphone')->tel()->maxLength(32),
                        TextInput::make('email')->label('Adresse e-mail')->email()->maxLength(255),
                        Toggle::make('is_head_office')
                            ->label('Siège')
                            ->disabled()
                            ->dehydrated(false)
                            ->hiddenOn('create'),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->disabled(fn (?Agency $record): bool => $record !== null && $record->is_head_office)
                            ->helperText('Une agence ne se supprime pas : elle se désactive.'),
                    ]),
            ]);
    }
}
