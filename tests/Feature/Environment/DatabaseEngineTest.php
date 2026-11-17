<?php

use Illuminate\Support\Facades\DB;

it('utilise la connexion mariadb', function () {
    expect(config('database.default'))->toBe('mariadb')
        ->and(DB::connection()->getDriverName())->toBe('mariadb');
});

it('tourne sur MariaDB 10.11, jamais sur SQLite ni MySQL', function () {
    $version = (string) DB::scalar('SELECT VERSION()');

    expect($version)->toContain('MariaDB')
        ->and($version)->toStartWith('10.11.');
});

it('utilise la base de test dédiée', function () {
    expect(DB::connection()->getDatabaseName())->toBe('aviator_test');
});

it('crée les tables en utf8mb4_unicode_ci', function () {
    expect(config('database.connections.mariadb.charset'))->toBe('utf8mb4')
        ->and(config('database.connections.mariadb.collation'))->toBe('utf8mb4_unicode_ci')
        ->and(config('database.connections.mariadb.engine'))->toBe('InnoDB')
        ->and(DB::scalar('SELECT @@collation_connection'))->toBe('utf8mb4_unicode_ci');
});

it('a une base de test créée en utf8mb4_unicode_ci', function () {
    expect(DB::scalar('SELECT @@collation_database'))->toBe('utf8mb4_unicode_ci');
});
