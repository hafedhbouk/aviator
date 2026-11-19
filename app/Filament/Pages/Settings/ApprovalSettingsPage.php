<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Settings\ApprovalSettings;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;

/**
 * Approbations du super admin : seuil de remise (Q10, Q52) et délégation (Q52).
 */
class ApprovalSettingsPage extends SettingsPage
{
    protected static ?string $title = 'Approbations';

    protected static ?string $navigationLabel = 'Approbations';

    protected static ?string $slug = 'reglages/approbations';

    protected static ?int $navigationSort = 4;

    protected static function settingsClass(): string
    {
        return ApprovalSettings::class;
    }

    protected function fields(): array
    {
        return [
            Section::make('Approbations')->columns(2)->schema([
                TextInput::make('discount_threshold_percent')->label('Remise sans approbation jusqu\'à (%)')->required()->suffix('%')
                    ->helperText('Q10, Q52 : 0 %, toute remise passe par le super admin.'),
                Toggle::make('delegate_to_admin')->label('L\'admin du siège peut approuver')->inline(false)
                    ->helperText('Q52 : non par défaut.'),
            ]),
        ];
    }
}
