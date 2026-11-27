<?php

namespace Tests;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tables jamais vidées par DatabaseTruncation (tests de concurrence) : les réglages
     * sont posés par les migrations de spatie/laravel-settings, que RefreshDatabase ne
     * rejoue pas. Sans cette exclusion, tout test lancé après un test de concurrence
     * échouait sur MissingSettings, selon l'ordre d'exécution (constat du 19/11/2026).
     *
     * @var list<string>
     */
    protected array $exceptTables = ['settings'];

    /**
     * Les tests ne dépendent pas des assets construits : `composer check` lance les tests
     * avant `npm run build`, et la CI part d'un dépôt sans `public/build`. Sans cela, une
     * vue qui appelle `@vite` passait sur le poste (manifeste d'une construction
     * précédente) et échouait en CI (constat du 27/11/2026, lots L6 à L11).
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * Ajoute les migrations des modèles de test (tests/Fixtures/migrations), qui
     * n'existent que dans la base de test : elles permettent d'éprouver les briques
     * du socle (portée par agence…) avant l'arrivée des documents métier.
     *
     * Garde-fou : la suite refuse de démarrer hors de l'environnement « testing » ou
     * sur une base dont le nom ne finit pas par « _test ». Des variables APP_ENV ou
     * DB_DATABASE exportées par le shell priment sur .env.testing : sans ce contrôle,
     * RefreshDatabase viderait la base de développement (incident du 19/11/2026).
     *
     * @return Application
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $connection = (string) $app['config']->get('database.default');
        self::assertIsolatedTestDatabase(
            (string) $app->environment(),
            $app['config']->get("database.connections.{$connection}.database"),
        );

        $app->afterResolving('migrator', function (Migrator $migrator): void {
            $migrator->path(base_path('tests/Fixtures/migrations'));
        });

        return $app;
    }

    /**
     * @throws RuntimeException si l'environnement ou la base ne sont pas ceux des tests
     */
    public static function assertIsolatedTestDatabase(string $environment, mixed $database): void
    {
        if ($environment !== 'testing') {
            throw new RuntimeException("Tests arrêtés : environnement « {$environment} » au lieu de « testing ». Une variable APP_ENV exportée par le shell prime sur phpunit.xml ; relancez sans elle (unset APP_ENV).");
        }

        if (! is_string($database) || ! str_ends_with($database, '_test')) {
            $name = is_string($database) ? $database : 'inconnue';

            throw new RuntimeException("Tests arrêtés avant toute écriture : base « {$name} », dont le nom ne finit pas par « _test ». Une variable DB_DATABASE exportée par le shell prime sur .env.testing ; relancez sans elle (unset DB_DATABASE).");
        }
    }
}
