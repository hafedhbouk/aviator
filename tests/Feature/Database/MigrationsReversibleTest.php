<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * Migrations réversibles (prompt maître §3) : annulation complète puis
 * réapplication. Ce test ne s'enveloppe pas dans une transaction
 * (MariaDB valide implicitement chaque ordre DDL) et laisse la base migrée.
 */

it('annule toutes les migrations puis les réapplique sans erreur', function () {
    expect(Artisan::call('migrate:fresh', ['--force' => true]))->toBe(0);
    expect(Artisan::call('migrate:reset', ['--force' => true]))->toBe(0);

    $remaining = collect(DB::select('SHOW TABLES'))
        ->map(fn (object $row): string => (string) array_values((array) $row)[0])
        ->reject(fn (string $table): bool => $table === 'migrations')
        ->values()
        ->all();

    expect($remaining)->toBe([]);
    expect(Artisan::call('migrate', ['--force' => true]))->toBe(0);
});
