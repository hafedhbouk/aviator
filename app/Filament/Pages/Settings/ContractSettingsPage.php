<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Settings\ContractSettings;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;

/**
 * Contrats : restitution anticipée (D-08), modèle et conditions générales (D-10, Q15).
 */
class ContractSettingsPage extends SettingsPage
{
    protected static ?string $title = 'Contrats';

    protected static ?string $navigationLabel = 'Contrats';

    protected static ?string $slug = 'reglages/contrats';

    protected static ?int $navigationSort = 3;

    protected static function settingsClass(): string
    {
        return ContractSettings::class;
    }

    protected function fields(): array
    {
        return [
            Section::make('Contrat et conditions générales')->schema([
                Toggle::make('template_validated')->label('Modèle de contrat et conditions générales validés par le conseil juridique')
                    ->helperText('Q15 : tant que ce n\'est pas le cas, les PDF portent la mention « Modèle non validé ».'),
                Textarea::make('terms_text')->label('Conditions générales de location')->rows(16)->maxLength(30000)
                    ->helperText('Texte imprimé avec le contrat. Une ligne vide sépare deux paragraphes ; une ligne commençant par « # » est un titre.'),
            ]),
            Section::make('Restitution anticipée')->schema([
                Select::make('early_return_refund')->label('Remboursement')->options(ContractSettings::EARLY_RETURN_REFUNDS)->required()
                    ->helperText('D-08, Q14 : aucun remboursement tant que la direction n\'a pas fixé de règle.'),
            ]),
        ];
    }
}
