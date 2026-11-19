<?php

use Illuminate\Support\Facades\Route;

// Pas de page d'accueil publique : le back-office est sous /admin. La page par
// défaut de Laravel n'est plus servie (elle révélait le framework).
Route::redirect('/', '/admin');
