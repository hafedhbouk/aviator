<?php

use App\Http\Controllers\PublicFileController;
use Illuminate\Support\Facades\Route;

/*
 * Routes publiques sans le groupe « web » (ni session, ni cookie, ni CSRF), chargées
 * par bootstrap/app.php. Seuls les en-têtes globaux (SecurityHeaders) s'appliquent.
 */

// Fichiers publics (logo) servis depuis le disque privé, sans lien symbolique (D21).
Route::get('/fichiers/public/{path}', PublicFileController::class)
    ->where('path', '[A-Za-z0-9/_.\-]+')
    ->name('public-files.show');
