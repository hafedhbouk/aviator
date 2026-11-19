<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Settings\NotificationSettings;
use App\Support\Authorization\Role;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;

/**
 * Alertes (A-09) : événements, destinataires et délais. Push seulement, sans SMS ;
 * l'envoi arrive en phase 9. Les alertes datées restent désactivées tant que leur
 * délai n'est pas saisi (Q90).
 */
class NotificationSettingsPage extends SettingsPage
{
    protected static ?string $title = 'Notifications';

    protected static ?string $navigationLabel = 'Notifications';

    protected static ?string $slug = 'reglages/notifications';

    protected static ?int $navigationSort = 5;

    protected static function settingsClass(): string
    {
        return NotificationSettings::class;
    }

    protected function fields(): array
    {
        $roles = collect(Role::cases())->mapWithKeys(fn (Role $role): array => [$role->value => $role->label()])->all();
        $sections = [];

        foreach (NotificationSettings::ALERTS as $event => $label) {
            $dated = in_array($event, NotificationSettings::DATED_ALERTS, true);

            $sections[] = Section::make($label)->columns(3)->schema(array_values(array_filter([
                Toggle::make("alerts.{$event}.enabled")->label('Active')->inline(false),
                $dated ? TextInput::make("alerts.{$event}.days_before")->label('Jours avant l\'échéance')->integer()->minValue(1)
                    ->placeholder('Non fixé')->helperText('Exigé pour activer l\'alerte (Q90).') : null,
                CheckboxList::make("alerts.{$event}.recipients")->label('Destinataires')->options($roles),
            ])));
        }

        return [
            Section::make('Envoi')->schema([
                Toggle::make('send_urgent_in_request')->label('Envoyer les notifications urgentes pendant la requête')
                    ->helperText('À activer si le cron ne tourne que toutes les 5 minutes (D20).'),
            ]),
            ...$sections,
        ];
    }
}
