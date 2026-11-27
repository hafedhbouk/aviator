<?php

namespace App\Filament\Resources\ApprovalRequests\Pages;

use App\Domain\Approvals\Actions\DecideApproval;
use App\Domain\Approvals\Exceptions\ApprovalException;
use App\Filament\Resources\ApprovalRequests\ApprovalRequestResource;
use App\Models\ApprovalRequest;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;

/**
 * Décision du super admin (P5, étape 5) : approuver, commentaire facultatif ; refuser,
 * commentaire obligatoire. Le contrôle du droit et de l'état est fait par le domaine
 * (DecideApproval), même si le bouton est affiché par erreur ou la requête falsifiée.
 */
class ViewApprovalRequest extends ViewRecord
{
    protected static string $resource = ApprovalRequestResource::class;

    protected function getHeaderActions(): array
    {
        $canDecide = fn (ApprovalRequest $record): bool => auth()->user()?->can('decide', $record) ?? false;

        return [
            Action::make('approve')
                ->label('Approuver')
                ->icon(Heroicon::OutlinedCheck)
                ->color('success')
                ->visible($canDecide)
                ->schema([
                    Textarea::make('comment')->label('Commentaire (facultatif)')->maxLength(1000),
                ])
                ->action(fn (ApprovalRequest $record, array $data) => $this->decide(
                    fn (User $actor) => app(DecideApproval::class)->approve($record, $actor, $data['comment'] ?? null),
                    'Demande approuvée.',
                )),
            Action::make('reject')
                ->label('Refuser')
                ->icon(Heroicon::OutlinedXMark)
                ->color('danger')
                ->visible($canDecide)
                ->schema([
                    Textarea::make('comment')->label('Motif du refus (obligatoire)')->required()
                        ->minLength(DecideApproval::MIN_COMMENT_LENGTH)->maxLength(1000),
                ])
                ->action(fn (ApprovalRequest $record, array $data) => $this->decide(
                    fn (User $actor) => app(DecideApproval::class)->reject($record, $actor, $data['comment'] ?? null),
                    'Demande refusée.',
                )),
        ];
    }

    /**
     * @param  callable(User): mixed  $decision
     */
    private function decide(callable $decision, string $success): void
    {
        /** @var User $actor */
        $actor = auth()->user();

        try {
            $decision($actor);
        } catch (ApprovalException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            throw new Halt;
        }

        Notification::make()->success()->title($success)->send();
        $this->record->refresh();
    }
}
