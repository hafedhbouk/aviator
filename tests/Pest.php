<?php

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Suite de tests Aviator
|--------------------------------------------------------------------------
|
| Tous les tests tournent sur MariaDB 10.11 (base aviator_test), jamais sur
| SQLite : les règles anti-chevauchement reposent sur des verrous de ligne.
|
*/

pest()->extend(TestCase::class)->in('Feature', 'Unit', 'Arch');
