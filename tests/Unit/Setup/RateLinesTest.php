<?php

use App\Domain\Setup\Exceptions\SetupException;
use App\Domain\Setup\RateLines;

/*
 * Tranches de durée d'une grille (A-04, Q33) : continues depuis 1 jour, dernière
 * ouverte, prix journalier HT au millime.
 */

function threeLines(): array
{
    return [
        ['min_days' => '30', 'max_days' => null, 'daily_amount' => '35,000'],
        ['min_days' => '1', 'max_days' => '6', 'daily_amount' => '50,000'],
        ['min_days' => '7', 'max_days' => '29', 'daily_amount' => '42,500'],
    ];
}

it('trie et normalise les tranches', function () {
    expect(RateLines::fromInput(threeLines())->toArray())->toBe([
        ['min_days' => 1, 'max_days' => 6, 'daily_amount' => '50.000'],
        ['min_days' => 7, 'max_days' => 29, 'daily_amount' => '42.500'],
        ['min_days' => 30, 'max_days' => null, 'daily_amount' => '35.000'],
    ]);
});

it('donne le prix journalier de la tranche de la durée (tarif dégressif, Q33)', function (int $days, string $expected) {
    expect(RateLines::fromInput(threeLines())->dailyAmountFor($days)?->toDecimalString())->toBe($expected);
})->with([
    [1, '50.000'], [6, '50.000'], [7, '42.500'], [10, '42.500'], [29, '42.500'], [30, '35.000'], [365, '35.000'],
]);

it('accepte une seule tranche ouverte', function () {
    expect(RateLines::fromInput([['min_days' => 1, 'max_days' => null, 'daily_amount' => '60']])->dailyAmountFor(100)?->toDecimalString())
        ->toBe('60.000');
});

it('refuse des tranches incohérentes', function (array $lines, string $message) {
    expect(fn () => RateLines::fromInput($lines))->toThrow(SetupException::class, $message);
})->with([
    'aucune' => [[], 'au moins une tranche'],
    'ne commence pas à 1' => [[['min_days' => 2, 'max_days' => null, 'daily_amount' => '10']], 'commence à 1 jour'],
    'trou' => [[
        ['min_days' => 1, 'max_days' => 6, 'daily_amount' => '10'],
        ['min_days' => 8, 'max_days' => null, 'daily_amount' => '9'],
    ], 'commencer à 7 jours'],
    'chevauchement' => [[
        ['min_days' => 1, 'max_days' => 7, 'daily_amount' => '10'],
        ['min_days' => 7, 'max_days' => null, 'daily_amount' => '9'],
    ], 'commencer à 8 jours'],
    'doublon' => [[
        ['min_days' => 1, 'max_days' => 6, 'daily_amount' => '10'],
        ['min_days' => 1, 'max_days' => 6, 'daily_amount' => '10'],
        ['min_days' => 7, 'max_days' => null, 'daily_amount' => '9'],
    ], 'commencer à 7 jours'],
    'dernière bornée' => [[['min_days' => 1, 'max_days' => 30, 'daily_amount' => '10']], 'sans limite'],
    'ouverte au milieu' => [[
        ['min_days' => 1, 'max_days' => null, 'daily_amount' => '10'],
        ['min_days' => 7, 'max_days' => null, 'daily_amount' => '9'],
    ], 'seule la dernière'],
    'fin avant début' => [[
        ['min_days' => 1, 'max_days' => 0, 'daily_amount' => '10'],
        ['min_days' => 2, 'max_days' => null, 'daily_amount' => '9'],
    ], 'durée maximale'],
    'prix nul' => [[['min_days' => 1, 'max_days' => null, 'daily_amount' => '0']], 'manquant ou nul'],
    'prix manquant' => [[['min_days' => 1, 'max_days' => null, 'daily_amount' => '']], 'manquant ou nul'],
    'prix à 4 décimales' => [[['min_days' => 1, 'max_days' => null, 'daily_amount' => '10,0001']], 'prix journalier'],
    'prix en float' => [[['min_days' => 1, 'max_days' => null, 'daily_amount' => 10.5]], 'prix journalier'],
    'durée non entière' => [[['min_days' => '1,5', 'max_days' => null, 'daily_amount' => '10']], 'nombre entier'],
]);
