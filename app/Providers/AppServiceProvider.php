<?php

namespace App\Providers;

use App\Support\Privacy\BlindIndex;
use Illuminate\Support\ServiceProvider;

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
        //
    }
}
