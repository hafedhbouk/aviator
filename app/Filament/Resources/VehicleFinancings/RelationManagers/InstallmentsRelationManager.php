<?php

namespace App\Filament\Resources\VehicleFinancings\RelationManagers;

use App\Domain\Fleet\Exceptions\FleetException;
use App\Domain\Fleet\Financing\Actions\MarkInstallmentPaid;
use App\Models\FinancingInstallment;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Échéancier (B-12) : généré par le domaine, jamais saisi ; pointage des paiements.
 */
class InstallmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'installments';

    protected static ?string $title = 'Échéancier';

    protected static ?string $modelLabel = 'échéance';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        $money = fn (?Money $state): string => $state?->format() ?? '—';

        return $table
            ->defaultSort('sequence')
            ->paginated([12, 24, 60])
            ->columns([
                TextColumn::make('sequence')->label('N°'),
                TextColumn::make('due_date')->label('Échéance')->date('d/m/Y'),
                TextColumn::make('amount_excl_tax')->label('Montant HT')->formatStateUsing($money),
                TextColumn::make('amount_incl_tax')->label('Montant TTC')->formatStateUsing($money),
                TextColumn::make('interest_amount')->label('Dont intérêts')->formatStateUsing($money)->placeholder('—'),
                TextColumn::make('charge_month')->label('Mois d\'imputation')->date('m/Y'),
                TextColumn::make('paid_on')->label('Payée le')->date('d/m/Y')->placeholder('Non payée'),
            ])
            ->filters([
                TernaryFilter::make('paid_on')->label('Payée')->nullable(),
            ])
            ->headerActions([])
            ->recordActions([
                Action::make('markPaid')
                    ->label(fn (FinancingInstallment $record): string => $record->paid_on === null ? 'Pointer' : 'Corriger')
                    ->schema([DatePicker::make('paid_on')->label('Date de paiement (vide : non payée)')->maxDate(now())])
                    ->fillForm(fn (FinancingInstallment $record): array => ['paid_on' => $record->paid_on?->toDateString() ?? now()->toDateString()])
                    ->action(function (FinancingInstallment $record, array $data): void {
                        /** @var User $actor */
                        $actor = auth()->user();

                        try {
                            app(MarkInstallmentPaid::class)->handle($record, empty($data['paid_on']) ? null : CarbonImmutable::parse((string) $data['paid_on']), $actor);
                        } catch (FleetException $exception) {
                            Notification::make()->danger()->title($exception->getMessage())->send();

                            throw new Halt;
                        }
                    }),
            ])
            ->toolbarActions([]);
    }
}
