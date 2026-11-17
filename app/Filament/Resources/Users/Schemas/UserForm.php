<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\Agency;
use App\Support\Authorization\Role;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Formulaire d'un compte. Ni mot de passe ni secret de 2FA : le titulaire choisit
 * son mot de passe par le lien de réinitialisation, et active lui-même sa 2FA.
 */
class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        $isAgencyUser = fn (Get $get): bool => $get('role') === Role::AgencyUser->value;

        return $schema
            ->components([
                Section::make('Identité')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nom complet')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('Adresse e-mail')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                    ]),
                Section::make('Profil et agences')
                    ->schema([
                        Select::make('role')
                            ->label('Profil')
                            ->options(self::roleOptions())
                            ->required()
                            ->live(),
                        Select::make('agency_id')
                            ->label('Agence par défaut')
                            ->options(fn (): array => self::agencyOptions())
                            ->required($isAgencyUser)
                            ->visible($isAgencyUser),
                        Select::make('agency_ids')
                            ->label('Autres agences autorisées')
                            ->helperText('Pour remplacer un collègue absent (Q69). L\'agence par défaut est toujours incluse.')
                            ->multiple()
                            ->options(fn (): array => self::agencyOptions())
                            ->visible($isAgencyUser),
                        Toggle::make('is_active')
                            ->label('Compte actif')
                            ->default(true),
                    ]),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private static function roleOptions(): array
    {
        $options = [];

        foreach (Role::assignableFromInterface() as $role) {
            $options[$role->value] = $role->label();
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    private static function agencyOptions(): array
    {
        /** @var array<int, string> */
        return Agency::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }
}
