<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Settings\SecuritySettings;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;

/**
 * Sécurité : 2FA des utilisateurs d'agence (Q76). Elle est toujours exigée pour le
 * super admin et l'admin (J-03, D9).
 */
class SecuritySettingsPage extends SettingsPage
{
    protected static ?string $title = 'Sécurité';

    protected static ?string $navigationLabel = 'Sécurité';

    protected static ?string $slug = 'reglages/securite';

    protected static ?int $navigationSort = 6;

    protected static function settingsClass(): string
    {
        return SecuritySettings::class;
    }

    protected function fields(): array
    {
        return [
            Section::make('Double authentification')->schema([
                Toggle::make('require_2fa_for_agency_users')->label('Exiger la 2FA des utilisateurs d\'agence')
                    ->helperText('Recommandé (Q76). Toujours exigée pour le super admin et l\'admin du siège.'),
            ]),
        ];
    }
}
