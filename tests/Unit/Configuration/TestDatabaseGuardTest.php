<?php

use Tests\TestCase;

/*
 * Garde-fou de la suite de tests (incident du 19/11/2026) : des variables APP_ENV ou
 * DB_DATABASE exportées par le shell primaient sur .env.testing, et RefreshDatabase
 * a vidé la base de développement. TestCase::createApplication() refuse désormais
 * de démarrer hors de l'environnement « testing » ou sur une base non « _test ».
 */

it('accepte l\'environnement testing sur une base _test', function (string $database) {
    TestCase::assertIsolatedTestDatabase('testing', $database);

    expect(true)->toBeTrue();
})->with(['aviator_test', 'build_test']);

it('refuse une base de développement ou de production', function (mixed $database) {
    expect(fn () => TestCase::assertIsolatedTestDatabase('testing', $database))
        ->toThrow(RuntimeException::class, 'Tests arrêtés avant toute écriture');
})->with(['aviator_dev', 'aviator_prod', 'aviator_test_old', '', null]);

it('refuse un autre environnement que testing', function (string $environment) {
    expect(fn () => TestCase::assertIsolatedTestDatabase($environment, 'aviator_test'))
        ->toThrow(RuntimeException::class, 'au lieu de « testing »');
})->with(['local', 'production']);

it('tourne lui-même sur la base de test isolée', function () {
    expect(config('database.connections.'.config('database.default').'.database'))->toEndWith('_test')
        ->and(app()->environment())->toBe('testing');
})->uses(TestCase::class);
