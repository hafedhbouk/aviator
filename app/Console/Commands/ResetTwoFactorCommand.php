<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\GuardsProduction;
use App\Models\User;
use App\Support\Auth\SessionRevoker;
use Illuminate\Console\Command;

/**
 * Accès de secours (docs/gestion_des_cles.md) : réinitialise la 2FA d'un compte
 * qui a perdu son application d'authentification et ses codes de récupération.
 * Réservée au terminal (aucun équivalent dans l'interface). Le secret et les codes
 * sont effacés, toutes les sessions sont coupées : à la prochaine connexion, le
 * titulaire doit réactiver sa 2FA (obligatoire pour le super admin et l'admin).
 */
class ResetTwoFactorCommand extends Command
{
    use GuardsProduction;

    /** @var string */
    protected $signature = 'aviator:reset-2fa {email : Adresse e-mail du compte} {--force : Exécuter en production}';

    /** @var string */
    protected $description = 'Réinitialise la 2FA d\'un compte (accès de secours, terminal uniquement)';

    public function handle(SessionRevoker $sessions): int
    {
        if ($this->refusesInProduction()) {
            return self::FAILURE;
        }

        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            $this->components->error("Aucun compte avec l'adresse {$email}.");

            return self::FAILURE;
        }

        $profile = $user->profile()?->label() ?? 'sans profil';

        if (! $this->confirm("Réinitialiser la 2FA de {$user->name} ({$email}, {$profile}) ? Ses sessions seront coupées.")) {
            $this->components->warn('Annulé : rien n\'a été modifié.');

            return self::FAILURE;
        }

        $user->saveAppAuthenticationSecret(null);
        $user->saveAppAuthenticationRecoveryCodes(null);
        $revoked = $sessions->revokeAll($user);

        activity('security')
            ->performedOn($user)
            ->event('two_factor_reset')
            ->withProperties(['via' => 'console', 'revoked_sessions' => $revoked])
            ->log('2FA réinitialisée (accès de secours)');

        $this->components->info("2FA réinitialisée pour {$email}. Elle devra être réactivée à la prochaine connexion.");

        return self::SUCCESS;
    }
}
