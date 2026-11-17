<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Coupe immédiatement tous les accès ouverts d'un compte : sessions en base
 * (SESSION_DRIVER=database) et jetons « se souvenir de moi ». Utilisé à la
 * désactivation d'un compte et à la réinitialisation de sa 2FA.
 */
final class SessionRevoker
{
    /**
     * @return int Nombre de sessions supprimées
     */
    public function revokeAll(User $user): int
    {
        $deleted = DB::table((string) config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->delete();

        // Un nouveau jeton invalide tous les cookies « se souvenir de moi » existants.
        $user->setRememberToken(Str::random(60));
        $user->saveQuietly();

        return $deleted;
    }
}
