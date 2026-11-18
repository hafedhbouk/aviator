<?php

namespace App\Filament\Resources\ActivityLogs\Schemas;

use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Resources\ActivityLogs\Tables\ActivityLogsTable;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Spatie\Activitylog\Models\Activity;

/**
 * Détail d'une entrée : qui, quand, quoi, avant et après (J-05). Les valeurs sont
 * affichées en texte échappé, jamais interprétées.
 */
class ActivityLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Entrée')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('created_at')->label('Date')->dateTime('d/m/Y H:i:s'),
                        TextEntry::make('log_name')
                            ->label('Journal')
                            ->formatStateUsing(fn (?string $state): string => ActivityLogResource::LOG_NAMES[$state ?? ''] ?? (string) $state),
                        TextEntry::make('event')->label('Événement')->placeholder('—'),
                        TextEntry::make('description')->label('Description'),
                        TextEntry::make('causer.name')->label('Par')->placeholder('Système ou console'),
                        TextEntry::make('subject')
                            ->label('Objet')
                            ->state(fn (Activity $record): ?string => ActivityLogsTable::subjectLabel($record))
                            ->placeholder('—'),
                    ]),
                Section::make('Détail (avant, après, contexte)')
                    ->schema([
                        TextEntry::make('properties')
                            ->hiddenLabel()
                            ->state(fn (Activity $record): string => (string) json_encode(
                                $record->properties,
                                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                            ))
                            ->fontFamily(FontFamily::Mono)
                            ->extraAttributes(['style' => 'white-space: pre-wrap']),
                    ]),
            ]);
    }
}
