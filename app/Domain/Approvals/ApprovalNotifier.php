<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

use App\Enums\ApprovalStatus;
use App\Filament\Resources\ApprovalRequests\ApprovalRequestResource;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Models\ApprovalRequest;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Authorization\Permission;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Notifications des approbations (P5), canal `database` en phase 5 ; le push arrive en
 * phase 9 (scénario 19). Textes génériques : **aucun montant, nom, motif ni
 * immatriculation** (RG-20, J-14) ; le détail se lit après connexion.
 */
final class ApprovalNotifier
{
    public const REQUESTED_TITLE = 'Nouvelle demande d\'approbation à traiter';

    public function requested(ApprovalRequest $request): void
    {
        $deciders = User::query()->permission(Permission::ApprovalsDecide->value)->where('is_active', true)->get();

        foreach ($deciders as $decider) {
            Notification::make()
                ->title(self::REQUESTED_TITLE)
                ->icon('heroicon-o-check-badge')
                ->warning()
                ->actions([
                    Action::make('open')->label('Ouvrir')->url(ApprovalRequestResource::getUrl('view', ['record' => $request->id])),
                ])
                ->sendToDatabase($decider);
        }
    }

    public function decided(ApprovalRequest $request, ?string $warning = null): void
    {
        $requester = $request->requester;

        if ($requester === null || ! $requester->is_active) {
            return;
        }

        $approved = $request->status === ApprovalStatus::Approved;
        $notification = Notification::make()
            ->title($approved ? 'Votre demande d\'approbation est approuvée' : 'Votre demande d\'approbation est refusée')
            ->body($warning)
            ->icon('heroicon-o-check-badge');

        $approved ? $notification->success() : $notification->danger();

        if ($request->subject_type === (new Reservation)->getMorphClass()) {
            $notification->actions([
                Action::make('open')->label('Ouvrir la réservation')->url(ReservationResource::getUrl('view', ['record' => $request->subject_id])),
            ]);
        }

        $notification->sendToDatabase($requester);
    }
}
