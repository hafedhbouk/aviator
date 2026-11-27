<?php

namespace App\Filament\Resources\ApprovalRequests\Tables;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Models\ApprovalRequest;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Liste des demandes (P5, étape 3) : type, agence, demandeur, ancienneté ; demandes en
 * attente par défaut, les plus anciennes en tête. Aucune action de masse.
 */
class ApprovalRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['agency', 'requester']))
            ->defaultSort('created_at', 'asc')
            ->columns([
                TextColumn::make('type')->label('Demande')
                    ->formatStateUsing(fn (ApprovalType $state): string => $state->label()),
                TextColumn::make('payload.document_number')->label('Document'),
                TextColumn::make('agency.name')->label('Agence'),
                TextColumn::make('requester.name')->label('Demandeur'),
                TextColumn::make('created_at')->label('Demandée')->since()->sortable()
                    ->tooltip(fn (ApprovalRequest $record): string => $record->created_at?->timezone((string) config('app.timezone'))->format('d/m/Y H:i') ?? ''),
                TextColumn::make('status')->label('État')->badge()
                    ->formatStateUsing(fn (ApprovalStatus $state): string => $state->label())
                    ->color(fn (ApprovalStatus $state): string => $state->color()),
            ])
            ->filters([
                SelectFilter::make('status')->label('État')
                    ->options(collect(ApprovalStatus::cases())->mapWithKeys(fn (ApprovalStatus $s): array => [$s->value => $s->label()])->all())
                    ->default(ApprovalStatus::Pending->value),
                SelectFilter::make('type')->label('Demande')
                    ->options(collect(ApprovalType::cases())->mapWithKeys(fn (ApprovalType $t): array => [$t->value => $t->label()])->all()),
            ])
            ->recordActions([
                ViewAction::make()->label('Ouvrir'),
            ])
            ->toolbarActions([]);
    }
}
