<?php

namespace App\Filament\Resources\Contracts\Pages;

use App\Domain\Rental\Actions\AmendContract;
use App\Domain\Rental\Actions\CancelContract;
use App\Domain\Rental\Actions\CloseContract;
use App\Domain\Rental\Actions\ExtendContract;
use App\Domain\Rental\Actions\ManageDamageFile;
use App\Domain\Rental\Actions\RemoveContractDriver;
use App\Domain\Rental\Actions\SaveContractDriver;
use App\Domain\Rental\Exceptions\ContractException;
use App\Enums\ContractStatus;
use App\Enums\DamageDocumentKind;
use App\Enums\InspectionType;
use App\Filament\Forms\AmountField;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Reservations\ReservationScreens;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\Driver;
use App\Models\InspectionDamage;
use App\Models\User;
use App\Support\Files\PrivateFiles;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;

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
            ActionGroup::make([
                Action::make('contractPdf')->label('Contrat (PDF)')->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->url(fn (Contract $record): string => route('filament.admin.documents.contract', ['contract' => $record->id])),
                Action::make('departurePdf')->label('État des lieux de départ (PDF)')->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->visible(fn (Contract $record): bool => $record->inspections()->where('type', InspectionType::Departure->value)->exists())
                    ->url(fn (Contract $record): string => route('filament.admin.documents.inspection', ['inspection' => (int) $record->inspections()->where('type', InspectionType::Departure->value)->value('id')])),
                Action::make('returnPdf')->label('État des lieux de retour (PDF)')->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->visible(fn (Contract $record): bool => $record->inspections()->where('type', InspectionType::Return->value)->exists())
                    ->url(fn (Contract $record): string => route('filament.admin.documents.inspection', ['inspection' => (int) $record->inspections()->where('type', InspectionType::Return->value)->value('id')])),
                Action::make('termsPdf')->label('Conditions générales (PDF)')->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->url(fn (): string => route('filament.admin.documents.terms')),
            ])->label('Imprimer')->icon(Heroicon::OutlinedPrinter)->button()->color('gray'),
            Action::make('departureInspection')
                ->label('État des lieux de départ')
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->color('primary')
                ->visible(fn (Contract $record): bool => $record->status === ContractStatus::Draft && (auth()->user()?->can('update', $record) ?? false)
                    || $record->inspections()->where('type', InspectionType::Departure->value)->exists())
                ->url(fn (Contract $record): string => ContractResource::getUrl('inspect', ['record' => $record, 'type' => InspectionType::Departure->value])),
            Action::make('returnInspection')
                ->label('État des lieux de retour')
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->color('primary')
                ->visible(fn (Contract $record): bool => $record->status === ContractStatus::Active && (auth()->user()?->can('update', $record) ?? false)
                    || $record->inspections()->where('type', InspectionType::Return->value)->exists())
                ->url(fn (Contract $record): string => ContractResource::getUrl('inspect', ['record' => $record, 'type' => InspectionType::Return->value])),
            Action::make('extend')
                ->label('Prolonger')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->visible(fn (Contract $record): bool => $record->status === ContractStatus::Active && (auth()->user()?->can('update', $record) ?? false))
                ->schema([
                    DateTimePicker::make('ends_at')->label('Nouvelle date de retour')->seconds(false)->required(),
                    AmountField::make('discount_amount')->label('Remise HT sur la prolongation'),
                    Textarea::make('discount_reason')->label('Motif de la remise (obligatoire au-delà du seuil)')->maxLength(1000),
                ])
                ->modalDescription('Disponibilité contrôlée sur la nouvelle période, délai de préparation compris ; jours ajoutés au tarif journalier du contrat.')
                ->action(function (Contract $record, array $data): void {
                    /** @var User $actor */
                    $actor = auth()->user();

                    $extension = ReservationScreens::run(fn () => app(ExtendContract::class)->handle($record, $data, $actor));

                    Notification::make()->success()->title(sprintf(
                        'Contrat prolongé jusqu\'au %s : %d jour(s) ajouté(s), %s HT.',
                        $extension->new_ends_at->timezone((string) config('app.timezone'))->format('d/m/Y H:i'),
                        $extension->added_days,
                        $extension->total_amount->format(),
                    ))->send();
                    $this->record->refresh();
                }),
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
            Action::make('openDamageFile')
                ->label('Dossier de dommage')
                ->icon(Heroicon::OutlinedFolderPlus)
                ->color('gray')
                ->visible(fn (Contract $record): bool => in_array($record->status, [ContractStatus::Returned, ContractStatus::Closed], true)
                    && ($this->newDamages($record) !== []) && (auth()->user()?->can('update', $record) ?? false))
                ->schema(fn (Contract $record): array => [
                    Select::make('damage_id')->label('Dommage nouveau du retour')->required()->options($this->newDamages($record)),
                    Select::make('kind')->label('Pièce')->required()
                        ->options(collect(DamageDocumentKind::cases())->mapWithKeys(fn (DamageDocumentKind $k): array => [$k->value => $k->label()])->all()),
                    FileUpload::make('file')->label('Fichier (PDF, JPEG, PNG, WebP)')->required()
                        ->disk(PrivateFiles::DISK)->directory(PrivateFiles::UPLOAD_DIRECTORY)->visibility('private')
                        ->acceptedFileTypes(array_keys(PrivateFiles::TYPES))->maxSize(intdiv(PrivateFiles::MAX_BYTES, 1024))
                        ->previewable(false)->downloadable(false)->openable(false),
                    Toggle::make('complete')->label('Marquer le dossier complet (constat et devis ou facture joints)'),
                ])
                ->modalDescription('Justificatif d\'une indemnité pour dégâts ou d\'une franchise (référentiel du cabinet, N2) : constat, devis ou facture de réparation. États des lieux et photos sont repris du contrat.')
                ->action(function (Contract $record, array $data): void {
                    $this->perform(function (User $actor) use ($record, $data): void {
                        $files = app(ManageDamageFile::class);
                        $file = $files->open($record, (int) $data['damage_id'], $actor);
                        $files->attach($file, DamageDocumentKind::from((string) $data['kind']), (string) $data['file'], $actor);

                        if ((bool) ($data['complete'] ?? false)) {
                            $files->complete($file->refresh(), $actor);
                        }
                    }, 'Pièce ajoutée au dossier de dommage.');
                }),
            Action::make('close')
                ->label('Clôturer')
                ->icon(Heroicon::OutlinedLockClosed)
                ->color('success')
                ->visible(fn (Contract $record): bool => $record->status === ContractStatus::Returned && (auth()->user()?->can('update', $record) ?? false))
                ->requiresConfirmation()
                ->modalDescription('Le contrat clôturé n\'est plus modifiable ; une correction passera par un avenant ou une facture complémentaire.')
                ->action(function (Contract $record): void {
                    $this->perform(fn (User $actor) => app(CloseContract::class)->handle($record, $actor), 'Contrat clôturé : il n\'est plus modifiable.');
                }),
            Action::make('amend')
                ->label('Avenant')
                ->icon(Heroicon::OutlinedDocumentPlus)
                ->color('gray')
                ->visible(fn (Contract $record): bool => $record->status === ContractStatus::Closed && (auth()->user()?->can('update', $record) ?? false))
                ->schema([
                    Radio::make('type')->label('Objet')->required()->live()->default(ContractAmendment::NOTE)
                        ->options([ContractAmendment::NOTE => 'Mention corrective', ContractAmendment::ADDITIONAL_DRIVER => 'Conducteur additionnel']),
                    Textarea::make('reason')->label('Motif (obligatoire)')->required()->minLength(AmendContract::MIN_REASON_LENGTH)->maxLength(1000),
                    Textarea::make('note')->label('Mention')->maxLength(2000)
                        ->visible(fn (Get $get): bool => $get('type') === ContractAmendment::NOTE),
                    Grid::make(2)->visible(fn (Get $get): bool => $get('type') === ContractAmendment::ADDITIONAL_DRIVER)->schema([
                        TextInput::make('first_name')->label('Prénom')->maxLength(100),
                        TextInput::make('last_name')->label('Nom')->maxLength(100),
                        TextInput::make('license_number')->label('Numéro de permis')->maxLength(64),
                        TextInput::make('license_country')->label('Pays du permis (code)')->maxLength(2)->placeholder('TN'),
                        DatePicker::make('birth_date')->label('Date de naissance'),
                        DatePicker::make('license_issued_on')->label('Permis obtenu le'),
                        DatePicker::make('license_expires_on')->label('Permis valable jusqu\'au'),
                    ]),
                ])
                ->modalDescription('Correction non financière d\'un contrat clôturé (D-11). Un écart de montant passe par une facture complémentaire ou un avoir.')
                ->action(function (Contract $record, array $data): void {
                    $this->perform(fn (User $actor) => app(AmendContract::class)->handle($record, $data, $actor), 'Avenant enregistré.');
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
     * Dommages nouveaux du retour validé, pour les dossiers de dommage.
     *
     * @return array<int, string>
     */
    private function newDamages(Contract $record): array
    {
        $return = $record->inspections()->where('type', InspectionType::Return->value)->whereNotNull('validated_at')->first();

        return $return === null ? [] : $return->damages()->where('is_new', true)->get()
            ->mapWithKeys(fn (InspectionDamage $damage): array => [$damage->id => "{$damage->zone->label()} : {$damage->description}"])->all();
    }

    /**
     * @param  callable(User): mixed  $write
     */
    private function perform(callable $write, string $success): void
    {
        /** @var User $actor */
        $actor = auth()->user();

        ReservationScreens::run(function () use ($write, $actor): mixed {
            try {
                return $write($actor);
            } catch (InvalidArgumentException $exception) {
                throw ContractException::invalidFile($exception->getMessage());
            }
        });

        Notification::make()->success()->title($success)->send();
        $this->record->refresh();
    }
}
