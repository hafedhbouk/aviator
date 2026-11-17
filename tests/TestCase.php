<?php

namespace Tests;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Ajoute les migrations des modèles de test (tests/Fixtures/migrations), qui
     * n'existent que dans la base de test : elles permettent d'éprouver les briques
     * du socle (portée par agence…) avant l'arrivée des documents métier.
     *
     * @return Application
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $app->afterResolving('migrator', function (Migrator $migrator): void {
            $migrator->path(base_path('tests/Fixtures/migrations'));
        });

        return $app;
    }
}
