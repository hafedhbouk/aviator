<?php

use App\Models\NamedLock;
use App\Support\Locking\DatabaseLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * Verrou de sérialisation en base (D38). L'attente entre deux processus est prouvée
 * par tests/Feature/Concurrency/SeasonConcurrencyTest.php.
 */

it('exécute le traitement sous le verrou et renvoie son résultat', function () {
    $result = app(DatabaseLock::class)->run('test:lock', fn (): string => 'fait');

    expect($result)->toBe('fait')
        ->and(NamedLock::query()->whereKey('test:lock')->exists())->toBeTrue();
});

it('crée la ligne du verrou une seule fois', function () {
    $lock = app(DatabaseLock::class);

    $lock->run('test:lock', fn () => null);
    $lock->run('test:lock', fn () => null);

    expect(NamedLock::query()->where('name', 'test:lock')->count())->toBe(1);
});

it('refuse de prendre un verrou hors transaction', function () {
    // RefreshDatabase ouvre une transaction : on la referme le temps de l'essai.
    $level = DB::transactionLevel();
    for ($i = 0; $i < $level; $i++) {
        DB::rollBack();
    }

    try {
        expect(fn () => app(DatabaseLock::class)->acquire('test:lock'))
            ->toThrow(LogicException::class, 'exige une transaction ouverte');
    } finally {
        for ($i = 0; $i < $level; $i++) {
            DB::beginTransaction();
        }
    }
});

it('annule le traitement en cas d\'erreur, sans rien garder', function () {
    expect(fn () => app(DatabaseLock::class)->run('test:lock', function (): void {
        NamedLock::query()->create(['name' => 'test:trace']);

        throw new RuntimeException('échec');
    }))->toThrow(RuntimeException::class);

    expect(NamedLock::query()->whereKey('test:trace')->exists())->toBeFalse();
});
