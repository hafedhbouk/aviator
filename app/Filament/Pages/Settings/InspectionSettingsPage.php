<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Settings\InspectionSettings;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;

/**
 * États des lieux : nombre maximal de photos (D44).
 */
class InspectionSettingsPage extends SettingsPage
{
    protected static ?string $title = 'États des lieux';

    protected static ?string $navigationLabel = 'États des lieux';

    protected static ?string $slug = 'reglages/etats-des-lieux';

    protected static ?int $navigationSort = 3;

    protected static function settingsClass(): string
    {
        return InspectionSettings::class;
    }

    protected function fields(): array
    {
        return [
            Section::make('Photos')->schema([
                TextInput::make('max_photos')->label('Photos au plus par état des lieux')->integer()->required()
                    ->minValue(1)->maxValue(40)
                    ->helperText('D44 : 12. Photos réduites à 1 600 px et ré-encodées, environ 300 Ko chacune.'),
            ]),
        ];
    }
}
