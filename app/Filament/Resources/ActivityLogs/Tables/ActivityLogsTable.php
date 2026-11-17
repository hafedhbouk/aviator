<?php

namespace App\Filament\Resources\ActivityLogs\Tables;

use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Spatie\Activitylog\Models\Activity;

/**
 * Liste du journal : lecture seule, aucune action de masse ni d'export.
 */
class ActivityLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),
                TextColumn::make('log_name')
                    ->label('Journal')
                    ->formatStateUsing(fn (?string $state): string => ActivityLogResource::LOG_NAMES[$state ?? ''] ?? (string) $state)
                    ->badge(),
                TextColumn::make('event')
                    ->label('Événement'),
                TextColumn::make('description')
                    ->label('Description')
                    ->limit(60),
                TextColumn::make('causer.name')
                    ->label('Par')
                    ->placeholder('Système ou console'),
                TextColumn::make('subject')
                    ->label('Objet')
                    ->state(fn (Activity $record): ?string => self::subjectLabel($record))
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('log_name')
                    ->label('Journal')
                    ->options(ActivityLogResource::LOG_NAMES),
                SelectFilter::make('event')
                    ->label('Événement')
                    ->options(fn (): array => self::eventOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }

    public static function subjectLabel(Activity $record): ?string
    {
        if ($record->subject_type === null) {
            return null;
        }

        return class_basename($record->subject_type).' #'.$record->subject_id;
    }

    /**
     * @return array<string, string>
     */
    private static function eventOptions(): array
    {
        /** @var array<string, string> */
        return Activity::query()
            ->whereNotNull('event')
            ->distinct()
            ->orderBy('event')
            ->pluck('event', 'event')
            ->all();
    }
}
