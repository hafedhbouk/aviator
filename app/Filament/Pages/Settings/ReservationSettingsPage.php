<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Settings\ReservationSettings;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;

/**
 * Règles de réservation (A-06). Une valeur vide = aucune contrainte (Q90).
 */
class ReservationSettingsPage extends SettingsPage
{
    protected static ?string $title = 'Règles de réservation';

    protected static ?string $navigationLabel = 'Réservation';

    protected static ?string $slug = 'reglages/reservation';

    protected static ?int $navigationSort = 1;

    protected static function settingsClass(): string
    {
        return ReservationSettings::class;
    }

    protected function fields(): array
    {
        return [
            Section::make('Réservation')->columns(2)->schema([
                TextInput::make('min_rental_days')->label('Durée minimale (jours)')->integer()->minValue(1)->placeholder('Aucune')
                    ->helperText('Vide : aucune durée minimale (Q90).'),
                TextInput::make('min_notice_hours')->label('Préavis minimal (heures)')->integer()->minValue(0)->placeholder('Aucun')
                    ->helperText('Vide : réservation possible jusqu\'au départ (Q90).'),
                TextInput::make('draft_expiry_hours')->label('Expiration d\'une réservation non confirmée (heures)')->integer()->minValue(1)->placeholder('Jamais')
                    ->helperText('Vide : pas d\'expiration automatique (Q90, C-09).'),
                TextInput::make('no_show_after_minutes')->label('Non-présentation après (minutes)')->integer()->required()->minValue(0)
                    ->helperText('Q39 : 120 min, statut proposé puis confirmé par l\'agence.'),
                TextInput::make('preparation_buffer_minutes')->label('Délai de préparation (minutes)')->integer()->required()->minValue(0)
                    ->helperText('Q41 : 60 min, surchargeable par catégorie.'),
                Toggle::make('allow_one_way')->label('Restitution dans une autre agence')->inline(false)
                    ->helperText('Q3 : non par défaut.'),
            ]),
            Section::make('Conducteur')->columns(2)->schema([
                TextInput::make('min_driver_age')->label('Âge minimum (ans)')->integer()->required()->minValue(18)
                    ->helperText('Q42 : 21 ans, surchargeable par catégorie.'),
                TextInput::make('min_license_years')->label('Ancienneté du permis (ans)')->integer()->required()->minValue(0)
                    ->helperText('Q42 : 2 ans, surchargeable par catégorie.'),
            ]),
        ];
    }
}
