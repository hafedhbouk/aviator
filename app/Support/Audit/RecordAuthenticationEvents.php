<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Cache;

/**
 * Journal de sécurité des connexions (J-04) : réussites, échecs, blocages,
 * déconnexions, réinitialisations de mot de passe. Jamais de mot de passe.
 *
 * Les blocages ne passent pas par un événement Laravel : Filament n'en déclenche
 * aucun quand il refuse une tentative. La page de connexion (App\Filament\Auth\Login)
 * appelle throttled() à chaque tentative refusée.
 */
final class RecordAuthenticationEvents
{
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Login::class, [$this, 'login']);
        $events->listen(Logout::class, [$this, 'logout']);
        $events->listen(Failed::class, [$this, 'failed']);
        $events->listen(PasswordReset::class, [$this, 'passwordReset']);
    }

    public function login(Login $event): void
    {
        $this->record('login', 'Connexion réussie', $event->user, ['remember' => $event->remember]);
    }

    public function logout(Logout $event): void
    {
        $this->record('logout', 'Déconnexion', $event->user);
    }

    public function failed(Failed $event): void
    {
        $email = $event->credentials['email'] ?? null;

        $this->record('login_failed', 'Échec de connexion', $event->user, [
            'email' => is_string($email) ? mb_strtolower($email) : null,
        ]);
    }

    /**
     * Tentative de connexion refusée par la limite de Filament. La première d'un
     * blocage est inscrite en login_lockout, les suivantes en login_throttled, tant
     * que le blocage dure (même adresse IP).
     */
    public function throttled(?string $email, int $secondsUntilAvailable): void
    {
        $startsLockout = Cache::add(
            'aviator:login-lockout:'.sha1((string) request()->ip()),
            true,
            max(1, $secondsUntilAvailable),
        );

        $this->record(
            $startsLockout ? 'login_lockout' : 'login_throttled',
            $startsLockout ? 'Connexions bloquées après trop de tentatives' : 'Tentative de connexion refusée pendant un blocage',
            null,
            [
                'email' => $email === null ? null : mb_strtolower($email),
                'retry_after_seconds' => $secondsUntilAvailable,
            ],
        );
    }

    public function passwordReset(PasswordReset $event): void
    {
        $this->record('password_reset', 'Mot de passe réinitialisé', $event->user);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function record(string $event, string $description, mixed $user, array $properties = []): void
    {
        $logger = activity('security')
            ->event($event)
            ->withProperties($properties + ['ip' => request()->ip()]);

        if ($user instanceof User) {
            $logger->causedBy($user)->performedOn($user);
        }

        $logger->log($description);
    }
}
