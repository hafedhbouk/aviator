<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\User;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Password;
use SensitiveParameter;

/**
 * Envoie le lien de réinitialisation du mot de passe du back-office. L'e-mail ne
 * sert qu'à la sécurité des comptes (CLAUDE.md). Renvoie le statut du broker
 * (Password::RESET_LINK_SENT, Password::RESET_THROTTLED…).
 */
final class PasswordResetLinkSender
{
    public function send(User $user): string
    {
        return Password::broker(Filament::getAuthPasswordBroker())->sendResetLink(
            ['email' => $user->email],
            function (CanResetPassword $user, #[SensitiveParameter] string $token): void {
                $notification = app(ResetPasswordNotification::class, ['token' => $token]);
                $notification->url = Filament::getResetPasswordUrl($token, $user);

                /** @var User $user */
                $user->notify($notification);
            },
        );
    }
}
