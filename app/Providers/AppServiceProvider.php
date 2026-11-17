<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\ActivityPolicy;
use App\Support\Audit\RecordAuthenticationEvents;
use App\Support\Privacy\BlindIndex;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\Activitylog\Models\Activity;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            BlindIndex::class,
            fn (): BlindIndex => new BlindIndex((string) config('aviator.blind_index_key'))
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // D19 : règles locales de mot de passe, sans vérification en ligne (pas d'uncompromised()).
        Password::defaults(fn (): Password => Password::min(12)
            ->max(128)
            ->letters()
            ->mixedCase()
            ->numbers()
            ->symbols());

        // HTTPS obligatoire en recette et en production (§6.2 du cahier).
        if (! $this->app->environment(['local', 'testing'])) {
            URL::forceScheme('https');
        }

        Event::subscribe(RecordAuthenticationEvents::class);

        // Journal d'audit : consultation réservée au super admin, aucune modification.
        Gate::policy(Activity::class, ActivityPolicy::class);

        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User) {
                $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            }
        });
    }
}
