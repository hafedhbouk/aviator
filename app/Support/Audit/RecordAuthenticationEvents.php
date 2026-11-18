<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Journal de sécurité des connexions (J-04) : réussites, échecs, blocages,
 * déconnexions, réinitialisations de mot de passe. Jamais de mot de passe.
 */
final class RecordAuthenticationEvents
{
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Login::class, [$this, 'login']);
        $events->listen(Logout::class, [$this, 'logout']);
        $events->listen(Failed::class, [$this, 'failed']);
        $events->listen(Lockout::class, [$this, 'lockout']);
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

    public function lockout(Lockout $event): void
    {
        $email = $event->request->input('email');

        $this->record('login_lockout', 'Connexions bloquées après trop d\'échecs', null, [
            'email' => is_string($email) ? mb_strtolower($email) : null,
        ]);
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
