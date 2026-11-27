<?php

namespace App\Filament\Resources\Reservations\Pages;

use App\Domain\Availability\Actions\AssignVehicle;
use App\Domain\Availability\Actions\CloseReservation;
use App\Domain\Availability\Actions\SaveReservation;
use App\Domain\Rental\Actions\OpenContract;
use App\Enums\ReservationStatus;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Filament\Resources\Reservations\ReservationScreens;
use App\Models\Contract;
use App\Models\Reservation;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/**
 * Fiche d'une réservation et ses actions, selon le statut et les droits (RG-13) :
 * modifier les dates (nouveau devis), affecter un véhicule (devis inchangé), annuler
 * avec motif, constater la non-présentation (Q39).
 */
class ViewReservation extends ViewRecord
{
    protected static string $resource = ReservationResource::class;

    protected function getHeaderActions(): array
    {
        $open = fn (Reservation $record): bool => in_array($record->status, ReservationStatus::open(), true)
            && (auth()->user()?->can('update', $record) ?? false);
        $confirmed = fn (Reservation $record): bool => $record->status === ReservationStatus::Confirmed
            && (auth()->user()?->can('update', $record) ?? false);

        return [
            Action::make('openContract')
                ->label('Créer le contrat')
                ->icon(Heroicon::OutlinedDocumentText)
                ->color('primary')
                ->visible($confirmed)
                ->schema(fn (Reservation $record): array => $record->vehicle_id !== null ? [] : [
                    Select::make('vehicle_id')->label('Véhicule disponible')->required()
                        ->options(fn (): array => ReservationScreens::vehicleOptions(
                            $record->agency_id,
                            $record->vehicle_category_id,
                            $record->starts_at->timezone((string) config('app.timezone'))->format('Y-m-d H:i'),
                            $record->ends_at->timezone((string) config('app.timezone'))->format('Y-m-d H:i'),
                        )),
                ])
                ->requiresConfirmation(fn (Reservation $record): bool => $record->vehicle_id !== null)
                ->modalDescription('Devis, options et remise sont repris tels quels ; la réservation devient « convertie ».')
                ->action(function (Reservation $record, array $data): void {
                    /** @var User $actor */
                    $actor = auth()->user();

                    /** @var Contract $contract */
                    $contract = ReservationScreens::run(fn () => app(OpenContract::class)->fromReservation(
                        $record,
                        is_numeric($data['vehicle_id'] ?? null) ? (int) $data['vehicle_id'] : null,
                        $actor,
                    ));

                    Notification::make()->success()->title("Contrat {$contract->number} créé.")->send();
                    $this->redirect(ContractResource::getUrl('view', ['record' => $contract]));
                }),
            Action::make('confirm')
                ->label('Confirmer')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->visible(fn (Reservation $record): bool => $record->status === ReservationStatus::Draft
                    && (auth()->user()?->can('update', $record) ?? false))
                ->requiresConfirmation()
                ->modalDescription('La disponibilité est contrôlée de nouveau et le devis recalculé. Un client sur liste noire exige la dérogation approuvée du super admin.')
                ->action(fn (Reservation $record) => $this->perform(
                    fn (User $actor) => app(SaveReservation::class)->handle($record, ['status' => ReservationStatus::Confirmed->value], $actor),
                    'Réservation confirmée.',
                )),
            Action::make('reschedule')
                ->label('Modifier les dates')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->visible($open)
                ->fillForm(fn (Reservation $record): array => [
                    'starts_at' => $record->starts_at->timezone((string) config('app.timezone'))->format('Y-m-d H:i'),
                    'ends_at' => $record->ends_at->timezone((string) config('app.timezone'))->format('Y-m-d H:i'),
                ])
                ->schema([
                    DateTimePicker::make('starts_at')->label('Départ')->seconds(false)->required(),
                    DateTimePicker::make('ends_at')->label('Retour')->seconds(false)->required()->after('starts_at'),
                ])
                ->modalDescription('Le devis est recalculé ; le véhicule affecté est conservé s\'il reste libre.')
                ->action(fn (Reservation $record, array $data) => $this->perform(
                    fn (User $actor) => app(SaveReservation::class)->handle($record, ['starts_at' => $data['starts_at'], 'ends_at' => $data['ends_at']], $actor),
                    'Dates modifiées.',
                )),
            Action::make('assignVehicle')
                ->label('Affecter un véhicule')
                ->icon(Heroicon::OutlinedTruck)
                ->visible($confirmed)
                ->schema(fn (Reservation $record): array => [
                    Select::make('vehicle_id')->label('Véhicule disponible')->required()
                        ->options(fn (): array => ReservationScreens::vehicleOptions(
                            $record->agency_id,
                            $record->vehicle_category_id,
                            $record->starts_at->timezone((string) config('app.timezone'))->format('Y-m-d H:i'),
                            $record->ends_at->timezone((string) config('app.timezone'))->format('Y-m-d H:i'),
                        )),
                ])
                ->action(fn (Reservation $record, array $data) => $this->perform(
                    fn (User $actor) => app(AssignVehicle::class)->handle($record, (int) $data['vehicle_id'], $actor),
                    'Véhicule affecté.',
                )),
            Action::make('cancel')
                ->label('Annuler')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->visible($open)
                ->schema([
                    Textarea::make('reason')->label('Motif (obligatoire)')->required()->minLength(CloseReservation::MIN_REASON_LENGTH)->maxLength(255),
                ])
                ->modalDescription('La réservation est conservée ; le véhicule et la période sont libérés.')
                ->action(fn (Reservation $record, array $data) => $this->perform(
                    fn (User $actor) => app(CloseReservation::class)->cancel($record, (string) $data['reason'], $actor),
                    'Réservation annulée.',
                )),
            Action::make('noShow')
                ->label('Client non présenté')
                ->icon(Heroicon::OutlinedUserMinus)
                ->color('warning')
                ->visible($confirmed)
                ->requiresConfirmation()
                ->action(fn (Reservation $record) => $this->perform(
                    fn (User $actor) => app(CloseReservation::class)->markNoShow($record, $actor),
                    'Non-présentation enregistrée.',
                )),
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
