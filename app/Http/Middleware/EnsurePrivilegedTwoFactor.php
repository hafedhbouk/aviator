<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Settings\SecuritySettings;
use Closure;
use Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

/**
 * 2FA obligatoire pour le super admin et l'admin (J-03, D9) ; pour les utilisateurs
 * d'agence seulement si le réglage security.require_2fa_for_agency_users est actif
 * (Q76). Remplace le middleware de Filament, qui l'impose à tous dès que la 2FA est
 * déclarée obligatoire sur le panneau.
 */
class EnsurePrivilegedTwoFactor
{
    public function __construct(
        private readonly EnsureMultiFactorAuthenticationIsEnabled $filamentMiddleware,
        private readonly SecuritySettings $settings,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $user = Filament::auth()->user();
        $required = $user instanceof User
            && ($user->profile()?->requiresTwoFactor($this->settings->require_2fa_for_agency_users) ?? false);

        if (! $required) {
            return $next($request);
        }

        // 2FA obligatoire mais inactive : redirection vers l'écran d'activation.
        return $this->filamentMiddleware->handle($request, $next);
    }
}
