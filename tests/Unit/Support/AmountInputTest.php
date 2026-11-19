<?php

use App\Support\Money\AmountInput;
use App\Support\Money\Money;

/*
 * Saisie des montants dans les formulaires : virgule ou point, espaces de milliers,
 * 3 décimales au plus, jamais de float.
 */

it('lit un montant saisi en français ou avec un point', function (mixed $input, string $expected) {
    expect(AmountInput::parse($input)?->toDecimalString())->toBe($expected);
})->with([
    'entier' => ['150', '150.000'],
    'entier PHP' => [150, '150.000'],
    'virgule' => ['150,5', '150.500'],
    'point' => ['150.250', '150.250'],
    'milliers avec espace' => ['1 500,250', '1500.250'],
    'milliers avec espace insécable' => ["1\u{00A0}500,250", '1500.250'],
    'millime' => ['0,001', '0.001'],
    'zéro' => ['0', '0.000'],
    'espaces autour' => ['  12,3  ', '12.300'],
]);

it('renvoie null pour une saisie vide', function (mixed $input) {
    expect(AmountInput::parse($input))->toBeNull();
})->with([null, '', '   ']);

it('refuse ce qui n\'est pas un montant positif au millime', function (mixed $input) {
    expect(fn () => AmountInput::parse($input))->toThrow(InvalidArgumentException::class, 'Montant invalide');
})->with([
    'float' => [150.5],
    'négatif' => ['-10'],
    'quatre décimales' => ['1,2345'],
    'lettres' => ['douze'],
    'deux séparateurs' => ['1,5,0'],
    'milliers mal groupés' => ['15 00'],
    'exposant' => ['1e3'],
]);

it('affiche un montant avec la virgule décimale', function () {
    expect(AmountInput::display(Money::of('1500.25')))->toBe('1500,250')
        ->and(AmountInput::display(null))->toBeNull()
        ->and(AmountInput::display('12.5'))->toBe('12,500');
});
