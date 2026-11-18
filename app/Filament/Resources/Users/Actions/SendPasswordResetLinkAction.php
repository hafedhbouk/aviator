<?php

namespace App\Filament\Resources\Users\Actions;

use App\Models\User;
use App\Support\Auth\PasswordResetLinkSender;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Password;

/**
 * Envoie au titulaire du compte un lien pour choisir un nouveau mot de passe (J-01).
 */
class SendPasswordResetLinkAction
{
    public static function make(): Action
    {
        return Action::make('sendPasswordResetLink')
            ->label('Envoyer un lien de réinitialisation')
            ->icon(Heroicon::OutlinedKey)
            ->requiresConfirmation()
            ->modalDescription('Le titulaire recevra par e-mail un lien pour choisir un nouveau mot de passe.')
            ->authorize(fn (User $record): bool => auth()->user()?->can('sendPasswordResetLink', $record) ?? false)
            ->action(function (User $record): void {
                $status = app(PasswordResetLinkSender::class)->send($record);

                activity('security')
                    ->causedBy(auth()->user())
                    ->performedOn($record)
                    ->event('password_reset_link_sent')
                    ->withProperties(['status' => $status])
                    ->log('Lien de réinitialisation du mot de passe envoyé');

                $status === Password::RESET_LINK_SENT
                    ? Notification::make()->success()->title('Lien de réinitialisation envoyé.')->send()
                    : Notification::make()->warning()->title('Lien non envoyé : un lien a déjà été demandé il y a moins d\'une minute.')->send();
            });
    }
}
