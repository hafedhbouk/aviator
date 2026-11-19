<?php

namespace App\Filament\Resources\StampDutyRules;

use App\Domain\Tax\Actions\SetFiscalRuleActive;
use App\Domain\Tax\Exceptions\FiscalSetupException;
use App\Enums\FiscalValidationStatus;
use App\Filament\Resources\StampDutyRules\Pages\ManageStampDutyRules;
use App\Models\StampDutyRule;
use App\Models\User;
use App\Support\Money\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Règles de timbre fiscal (référentiel v1.0, §3.4) : versions datées, jamais modifiées.
 * Super admin en écriture (nouvelle version, activation), admin en lecture.
 */
class StampDutyRuleResource extends Resource
{
    protected static ?string $model = StampDutyRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|UnitEnum|null $navigationGroup = 'Paramètres fiscaux';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'règle de timbre';

    protected static ?string $pluralModelLabel = 'timbre fiscal';

    protected static ?string $slug = 'fiscal/timbre';

    protected static bool $isGloballySearchable = false;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('rule_code')
            ->columns([
                TextColumn::make('rule_code')->label('Règle')->fontFamily('mono'),
                TextColumn::make('amount')->label('Montant')->formatStateUsing(fn (Money $state): string => $state->format()),
                TextColumn::make('exemption_condition')->label('Condition')->placeholder('—')->wrap(),
                TextColumn::make('effective_from')->label('Du')->date('d/m/Y'),
                TextColumn::make('effective_to')->label('Au')->date('d/m/Y')->placeholder('Sans fin'),
                TextColumn::make('validation_status')->label('Statut')->badge()
                    ->formatStateUsing(fn (FiscalValidationStatus $state): string => $state->value),
                TextColumn::make('legal_reference')->label('Base')->wrap()->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->recordActions([
                Action::make('toggleActive')
                    ->label(fn (StampDutyRule $record): string => $record->is_active ? 'Désactiver' : 'Activer')
                    ->requiresConfirmation()
                    ->visible(fn (StampDutyRule $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->action(function (StampDutyRule $record): void {
                        /** @var User $actor */
                        $actor = auth()->user();

                        try {
                            app(SetFiscalRuleActive::class)->handle($record, ! $record->is_active, $actor);
                        } catch (FiscalSetupException $exception) {
                            Notification::make()->danger()->title($exception->getMessage())->send();
                        }
                    }),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageStampDutyRules::route('/'),
        ];
    }
}
