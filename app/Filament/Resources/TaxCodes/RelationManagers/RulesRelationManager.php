<?php

namespace App\Filament\Resources\TaxCodes\RelationManagers;

use App\Domain\Tax\Actions\AddTaxRuleVersion;
use App\Domain\Tax\Actions\SetFiscalRuleActive;
use App\Domain\Tax\Exceptions\FiscalSetupException;
use App\Enums\FiscalValidationStatus;
use App\Models\FiscalReference;
use App\Models\TaxCode;
use App\Models\TaxRule;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Versions datées de la règle de TVA d'un code. Jamais modifiées : une nouvelle
 * version clôt la précédente (AddTaxRuleVersion) ; seule l'activation change
 * (SetFiscalRuleActive). Lecture seule pour l'admin.
 */
class RulesRelationManager extends RelationManager
{
    protected static string $relationship = 'rules';

    protected static ?string $title = 'Versions de la règle de TVA';

    /**
     * Visible sur la fiche (page de lecture) : les actions d'écriture y restent
     * possibles pour le super admin, sous le contrôle des policies et des actions.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('viewAny', TaxRule::class) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('valid_from', 'desc')
            ->columns([
                TextColumn::make('rate')->label('Taux')->placeholder('—')
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : rtrim(rtrim(str_replace('.', ',', $state), '0'), ',').' %'),
                TextColumn::make('valid_from')->label('Du')->date('d/m/Y'),
                TextColumn::make('valid_to')->label('Au')->date('d/m/Y')->placeholder('Sans fin'),
                TextColumn::make('validation_status')->label('Statut')->badge()
                    ->formatStateUsing(fn (FiscalValidationStatus $state): string => $state->value),
                TextColumn::make('fiscalReference.version')->label('Référence')->prefix('v')->placeholder('—'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->headerActions([
                Action::make('addVersion')
                    ->label('Nouvelle version')
                    ->icon(Heroicon::OutlinedPlus)
                    ->visible(fn (): bool => $this->actor()->can('create', TaxRule::class))
                    ->schema([
                        TextInput::make('rate')->label('Taux de TVA (%)')
                            ->visible(fn (): bool => $this->owner()->nature->hasRate())
                            ->required(fn (): bool => $this->owner()->nature->hasRate()),
                        DatePicker::make('valid_from')->label('À partir du')->required(),
                        Select::make('validation_status')->label('Statut')->required()
                            ->options(collect(FiscalValidationStatus::cases())->mapWithKeys(fn (FiscalValidationStatus $s): array => [$s->value => $s->value])->all()),
                        Select::make('fiscal_reference_id')->label('Référence validée')
                            ->options(fn (): array => FiscalReference::query()->get()->mapWithKeys(fn (FiscalReference $r): array => [$r->id => $r->label()])->all()),
                    ])
                    ->action(function (array $data): void {
                        try {
                            app(AddTaxRuleVersion::class)->handle($this->owner(), $data, $this->actor());
                        } catch (FiscalSetupException $exception) {
                            Notification::make()->danger()->title($exception->getMessage())->send();

                            throw new Halt;
                        }

                        Notification::make()->success()->title('Nouvelle version enregistrée')->send();
                    }),
            ])
            ->recordActions([
                Action::make('toggleActive')
                    ->label(fn (TaxRule $record): string => $record->is_active ? 'Désactiver' : 'Activer')
                    ->requiresConfirmation()
                    ->visible(fn (TaxRule $record): bool => $this->actor()->can('update', $record))
                    ->action(function (TaxRule $record): void {
                        try {
                            app(SetFiscalRuleActive::class)->handle($record, ! $record->is_active, $this->actor());
                        } catch (FiscalSetupException $exception) {
                            Notification::make()->danger()->title($exception->getMessage())->send();
                        }
                    }),
            ])
            ->toolbarActions([]);
    }

    private function owner(): TaxCode
    {
        /** @var TaxCode */
        return $this->getOwnerRecord();
    }

    private function actor(): User
    {
        /** @var User */
        return auth()->user();
    }
}
