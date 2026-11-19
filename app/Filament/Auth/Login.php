<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use App\Support\Audit\RecordAuthenticationEvents;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Notifications\Notification;

/**
 * Connexion au back-office. Filament limite les tentatives (5 par minute et par
 * adresse IP) mais, quand la limite est atteinte, il refuse la tentative sans
 * déclencher d'événement Laravel (ni Lockout, ni Failed) : le blocage resterait
 * invisible dans le journal de sécurité. Chaque tentative refusée y est donc
 * inscrite ici (login_lockout au début du blocage, login_throttled ensuite).
 */
class Login extends BaseLogin
{
    protected function getRateLimitedNotification(TooManyRequestsException $exception): ?Notification
    {
        $email = $this->data['email'] ?? null;

        app(RecordAuthenticationEvents::class)->throttled(
            is_string($email) ? $email : null,
            (int) $exception->secondsUntilAvailable,
        );

        return parent::getRateLimitedNotification($exception);
    }
}
