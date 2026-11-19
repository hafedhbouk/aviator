<?php

namespace App\Filament\Resources\VehicleCategories\Schemas;

use App\Filament\Forms\AmountField;
use App\Settings\ReservationSettings;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Formulaire d'une catégorie. Les surcharges vides reprennent la valeur générale
 * (indiquée dans l'aide) ou restent « non fixées ».
 */
class VehicleCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        $settings = app(ReservationSettings::class);

        return $schema
            ->components([
                Section::make('Catégorie')
                    ->columns(2)
                    ->schema([
                        TextInput::make('code')->label('Code')->required()->maxLength(16)
                            ->regex('/^[A-Za-z0-9_-]{2,16}$/')
                            ->helperText('2 à 16 lettres, chiffres, « _ » ou « - ».'),
                        TextInput::make('name')->label('Nom')->required()->maxLength(100)
                            ->placeholder('Citadine, berline, SUV, utilitaire…'),
                        Textarea::make('description')->label('Description')->rows(2)->maxLength(2000)->columnSpanFull(),
                        TextInput::make('sort_order')->label("Ordre d'affichage")->integer()->default(0),
                        Toggle::make('is_active')->label('Active')->default(true)
                            ->helperText('Une catégorie ne se supprime pas : elle se désactive.'),
                    ]),
                Section::make('Conducteur et préparation')
                    ->description('Vide = valeur générale des règles de réservation.')
                    ->columns(3)
                    ->schema([
                        TextInput::make('min_driver_age')->label('Âge minimum (ans)')->integer()
                            ->minValue(18)->maxValue(99)
                            ->placeholder((string) $settings->min_driver_age)
                            ->helperText("Général : {$settings->min_driver_age} ans (Q42)."),
                        TextInput::make('min_license_years')->label('Ancienneté du permis (ans)')->integer()
                            ->minValue(0)->maxValue(60)
                            ->placeholder((string) $settings->min_license_years)
                            ->helperText("Général : {$settings->min_license_years} ans (Q42)."),
                        TextInput::make('preparation_buffer_minutes')->label('Délai de préparation (min)')->integer()
                            ->minValue(0)->maxValue(1440)
                            ->placeholder((string) $settings->preparation_buffer_minutes)
                            ->helperText("Général : {$settings->preparation_buffer_minutes} min (Q41)."),
                    ]),
                Section::make('Caution, kilométrage et franchise')
                    ->description('Vide = non fixé : caution saisie à chaque contrat (Q14), kilométrage illimité (Q43), '
                        .'franchise sans plafond (Q45).')
                    ->columns(2)
                    ->schema([
                        AmountField::make('deposit_amount')->label('Caution'),
                        AmountField::make('insurance_excess_amount')->label('Franchise'),
                        TextInput::make('included_km_per_day')->label('Kilométrage inclus (km par jour)')->integer()
                            ->minValue(1)->placeholder('Illimité'),
                        AmountField::make('extra_km_amount')->label('Prix du km supplémentaire'),
                    ]),
            ]);
    }
}
