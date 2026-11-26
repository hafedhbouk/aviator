<?php

namespace App\Filament\Resources\Contracts\Pages;

use App\Domain\Rental\Actions\CancelContract;
use App\Enums\ContractStatus;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Reservations\ReservationScreens;
use App\Models\Contract;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
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
        return [
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
                    /** @var User $actor */
                    $actor = auth()->user();

                    ReservationScreens::run(fn () => app(CancelContract::class)->handle($record, (string) $data['reason'], $actor));

                    Notification::make()->success()->title('Contrat annulé.')->send();
                    $this->record->refresh();
                }),
        ];
    }
}
