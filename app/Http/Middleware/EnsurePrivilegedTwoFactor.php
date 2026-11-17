<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

/**
 * 2FA obligatoire pour le super admin et l'admin (J-03, D9), facultative pour
 * les utilisateurs d'agence. Remplace le middleware de Filament, qui l'impose
 * à tous dès que la 2FA est déclarée obligatoire sur le panneau.
 */
class EnsurePrivilegedTwoFactor
{
    public function __construct(private readonly EnsureMultiFactorAuthenticationIsEnabled $filamentMiddleware) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User || ! ($user->profile()?->requiresTwoFactor() ?? false)) {
            return $next($request);
        }

        // Super admin ou admin sans 2FA active : redirection vers l'écran d'activation.
        return $this->filamentMiddleware->handle($request, $next);
    }
}
