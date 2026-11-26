<?php

namespace App\Filament\Resources\Contracts\Pages;

use App\Domain\Rental\Actions\CancelContract;
use App\Domain\Rental\Actions\RemoveContractDriver;
use App\Domain\Rental\Actions\SaveContractDriver;
use App\Enums\ContractStatus;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Reservations\ReservationScreens;
use App\Models\Contract;
use App\Models\Driver;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * Fiche d'un contrat et ses actions selon le statut et les droits (RG-13). Lot L2 :
 * annulation d'un brouillon ; départ, prolongation, retour et clôture arrivent avec les
 * lots suivants.
 */
class ViewContract extends ViewRecord
{
    protected static string $resource = ContractResource::class;

    protected function getHeaderActions(): array
    {
        $draft = fn (Contract $record): bool => $record->status === ContractStatus::Draft
            && (auth()->user()?->can('update', $record) ?? false);

        return [
            Action::make('addDriver')
                ->label('Ajouter un conducteur')
                ->icon(Heroicon::OutlinedIdentification)
                ->visible($draft)
                ->schema(fn (Contract $record): array => [
                    Select::make('driver_id')->label('Conducteur déjà connu du client')->live()
                        ->placeholder('Nouveau conducteur')
                        ->options(fn (): array => Driver::query()->where('customer_id', $record->customer_id)->orderBy('last_name')->get()
                            ->mapWithKeys(fn (Driver $driver): array => [$driver->id => "{$driver->first_name} {$driver->last_name}"])->all()),
                    Grid::make(2)->visible(fn (Get $get): bool => blank($get('driver_id')))->schema([
                        TextInput::make('first_name')->label('Prénom')->required()->maxLength(100),
                        TextInput::make('last_name')->label('Nom')->required()->maxLength(100),
                        TextInput::make('license_number')->label('Numéro de permis')->required()->maxLength(64),
                        TextInput::make('id_document_number')->label('Numéro de pièce d\'identité')->maxLength(64),
                    ]),
                    Grid::make(2)->schema([
                        DatePicker::make('birth_date')->label('Date de naissance')
                            ->required(fn (Get $get): bool => blank($get('driver_id')))
                            ->helperText('Conducteur connu : à saisir seulement pour compléter sa fiche.'),
                        DatePicker::make('license_issued_on')->label('Permis obtenu le')->required(fn (Get $get): bool => blank($get('driver_id'))),
                        DatePicker::make('license_expires_on')->label('Permis valable jusqu\'au'),
                        TextInput::make('license_country')->label('Pays du permis (code)')->maxLength(2)->placeholder('TN'),
                    ]),
                    Toggle::make('is_primary')->label('Conducteur principal')
                        ->default(fn (): bool => $record->drivers()->count() === 0),
                ])
                ->modalDescription('Âge et ancienneté du permis contrôlés aux dates du contrat ; un permis expiré avant le retour est refusé.')
                ->action(function (Contract $record, array $data): void {
                    $this->perform(fn (User $actor) => app(SaveContractDriver::class)->handle($record, $data, $actor), 'Conducteur enregistré.');
                }),
            Action::make('removeDriver')
                ->label('Retirer un conducteur')
                ->icon(Heroicon::OutlinedUserMinus)
                ->color('gray')
                ->visible(fn (Contract $record): bool => $draft($record) && $record->drivers()->exists())
                ->schema(fn (Contract $record): array => [
                    Select::make('driver_id')->label('Conducteur')->required()
                        ->options(fn (): array => $record->drivers()->get()
                            ->mapWithKeys(fn (Driver $driver): array => [$driver->id => "{$driver->first_name} {$driver->last_name}"])->all()),
                ])
                ->action(function (Contract $record, array $data): void {
                    $this->perform(
                        fn (User $actor) => app(RemoveContractDriver::class)->handle($record, Driver::query()->findOrFail((int) $data['driver_id']), $actor),
                        'Conducteur retiré.',
                    );
                }),
            Action::make('cancel')
                ->label('Annuler')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->visible(fn (Contract $record): bool => $record->status === ContractStatus::Draft
                    && (auth()->user()?->can('update', $record) ?? false))
                ->schema([
                    Textarea::make('reason')->label('Motif (obligatoire)')->required()->minLength(CancelContract::MIN_REASON_LENGTH)->maxLength(255),
                ])
                ->modalDescription('Le contrat numéroté est conservé ; le véhicule et la période sont libérés.')
                ->action(function (Contract $record, array $data): void {
                    $this->perform(fn (User $actor) => app(CancelContract::class)->handle($record, (string) $data['reason'], $actor), 'Contrat annulé.');
                }),
        ];
    }

    /**
     * @param  callable(User): mixed  $write
     */
    private function perform(callable $write, string $success): void
    {
        /** @var User $actor */
        $actor = auth()->user();

        ReservationScreens::run(fn () => $write($actor));

        Notification::make()->success()->title($success)->send();
        $this->record->refresh();
    }
}
