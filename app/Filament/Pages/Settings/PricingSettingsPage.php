<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Filament\Forms\AmountField;
use App\Settings\PricingSettings;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;

/**
 * Tarification : décompte des jours (Q32) et retards (Q14).
 */
class PricingSettingsPage extends SettingsPage
{
    protected static ?string $title = 'Règles de tarification';

    protected static ?string $navigationLabel = 'Tarification';

    protected static ?string $slug = 'reglages/tarification';

    protected static ?int $navigationSort = 2;

    protected static function settingsClass(): string
    {
        return PricingSettings::class;
    }

    protected function fields(): array
    {
        return [
            Section::make('Tarification')->columns(2)->schema([
                Select::make('day_mode')->label('Décompte des jours')->options(PricingSettings::DAY_MODES)->required()
                    ->helperText('Q32 : tranches de 24 h glissantes.'),
                TextInput::make('late_tolerance_minutes')->label('Tolérance de retard (minutes)')->integer()->required()->minValue(0)
                    ->helperText('Q14 : 60 min.'),
                Select::make('late_unit')->label('Retard au-delà de la tolérance')->options(PricingSettings::LATE_UNITS)->required()
                    ->helperText('Q14 : une journée au tarif du contrat. À l\'heure : tarif horaire de chaque catégorie obligatoire (Q91).'),
            ]),
            Section::make('Carburant manquant au retour')->columns(2)
                ->description('Vide = aucun frais de carburant calculé (Q44). Frais = huitièmes manquants × capacité du réservoir ÷ 8 × prix du litre + frais de service.')
                ->schema([
                    AmountField::make('fuel_liter_amount')->label('Prix du litre HT'),
                    AmountField::make('fuel_service_amount')->label('Frais de service HT'),
                ]),
        ];
    }
}
