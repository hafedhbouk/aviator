<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Settings\BillingSettings;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;

/**
 * Facturation : conditions de paiement (A-07, Q89) et mode d'arrondi de la TVA (Q86).
 * La numérotation des factures (D27) arrive en phases 6 et 9.
 */
class BillingSettingsPage extends SettingsPage
{
    protected static ?string $title = 'Règles de facturation';

    protected static ?string $navigationLabel = 'Facturation';

    protected static ?string $slug = 'reglages/facturation';

    protected static ?int $navigationSort = 3;

    protected static function settingsClass(): string
    {
        return BillingSettings::class;
    }

    protected function fields(): array
    {
        return [
            Section::make('Facturation')->columns(2)->schema([
                TextInput::make('payment_terms_days')->label('Délai de paiement (jours)')->integer()->required()->minValue(0)
                    ->helperText('0 = paiement comptant (Q89).'),
                Select::make('vat_rounding_mode')->label('Arrondi de la TVA (3 décimales)')->required()
                    ->options(['half_up' => 'Demi supérieur (0,0285 → 0,029)', 'half_even' => 'Demi pair (0,0285 → 0,028)'])
                    ->helperText('Référentiel §5 ; mode à confirmer par le cabinet (Q86).'),
                TextInput::make('long_rental_period_days')->label('Période de facturation des locations longues (jours)')->integer()->required()
                    ->minValue(7)->maxValue(365)
                    ->helperText('Au-delà, le contrat est facturé par période de cette durée (Q50).'),
            ]),
        ];
    }
}
