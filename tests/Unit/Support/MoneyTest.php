<?php

use App\Support\Money\Money;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;

// --- Création -----------------------------------------------------------------

it('représente un montant au millime, toujours avec 3 décimales', function (string|int $input, string $expected) {
    expect(Money::of($input)->toDecimalString())->toBe($expected);
})->with([
    'entier' => [12, '12.000'],
    'une décimale' => ['12.5', '12.500'],
    'trois décimales' => ['0.125', '0.125'],
    'négatif' => ['-7.05', '-7.050'],
    'zéro' => ['0', '0.000'],
    'maximum DECIMAL(15,3)' => ['999999999999.999', '999999999999.999'],
]);

it('refuse un montant de plus de 3 décimales sans arrondi explicite', function () {
    Money::of('1.0005');
})->throws(RoundingNecessaryException::class);

it('refuse une saisie qui n\'est pas un nombre décimal', function (string $input) {
    Money::of($input);
})->with(['virgule' => ['12,5'], 'exposant' => ['1e3'], 'texte' => ['douze'], 'vide' => ['']])
    ->throws(InvalidArgumentException::class, 'Montant invalide');

it('refuse un montant hors de la capacité de DECIMAL(15,3)', function () {
    Money::of('1000000000000');
})->throws(InvalidArgumentException::class, 'DECIMAL(15,3)');

it('crée un montant à partir de millimes', function () {
    expect(Money::ofMillimes(1234567)->toDecimalString())->toBe('1234.567');
});

// --- Arrondi au millime explicite ----------------------------------------------

it('arrondit au millime selon le mode choisi', function (string $input, RoundingMode $mode, string $expected) {
    expect(Money::ofRounded($input, $mode)->toDecimalString())->toBe($expected);
})->with([
    'demi vers le haut, 0,0005' => ['0.0005', RoundingMode::HalfUp, '0.001'],
    'demi vers le haut, 2,1235' => ['2.1235', RoundingMode::HalfUp, '2.124'],
    'demi vers le haut, négatif' => ['-2.1235', RoundingMode::HalfUp, '-2.124'],
    'demi pair, 2,1235' => ['2.1235', RoundingMode::HalfEven, '2.124'],
    'demi pair, 2,1225' => ['2.1225', RoundingMode::HalfEven, '2.122'],
    'vers le bas' => ['2.1239', RoundingMode::Down, '2.123'],
    'vers le haut' => ['2.1231', RoundingMode::Up, '2.124'],
]);

it('multiplie par un taux en arrondissant au millime de façon explicite', function () {
    // 95,000 × 4 jours exact ; 33,333 × 1,07 = 35,66631 arrondi au millime.
    expect(Money::of('95')->multipliedBy(4, RoundingMode::Unnecessary)->toDecimalString())->toBe('380.000')
        ->and(Money::of('33.333')->multipliedBy('1.07', RoundingMode::HalfUp)->toDecimalString())->toBe('35.666');
});

it('exige un mode d\'arrondi quand la multiplication déborde le millime', function () {
    Money::of('33.333')->multipliedBy('1.07', RoundingMode::Unnecessary);
})->throws(RoundingNecessaryException::class);

it('divise en arrondissant au millime de façon explicite', function () {
    expect(Money::of('100')->dividedBy(3, RoundingMode::HalfUp)->toDecimalString())->toBe('33.333')
        ->and(Money::of('100')->dividedBy(3, RoundingMode::Up)->toDecimalString())->toBe('33.334');
});

// --- Répartition sans perte ----------------------------------------------------

it('répartit un montant au millime près, sans rien perdre ni créer', function (string $total, int $parts, array $expected) {
    $shares = Money::of($total)->allocate($parts);
    $sum = array_reduce($shares, fn (Money $carry, Money $share): Money => $carry->plus($share), Money::zero());

    expect(array_map(fn (Money $m): string => $m->toDecimalString(), $shares))->toBe($expected)
        ->and($sum->toDecimalString())->toBe(Money::of($total)->toDecimalString());
})->with([
    '100 en 3' => ['100', 3, ['33.334', '33.333', '33.333']],
    '0,002 en 3' => ['0.002', 3, ['0.001', '0.001', '0.000']],
    'négatif' => ['-100', 3, ['-33.334', '-33.333', '-33.333']],
    'exact' => ['90', 3, ['30.000', '30.000', '30.000']],
]);

// --- Opérations et affichage ---------------------------------------------------

it('additionne, soustrait et compare sans erreur d\'arrondi binaire', function () {
    $a = Money::of('0.1');
    $b = Money::of('0.2');

    expect($a->plus($b)->equals(Money::of('0.3')))->toBeTrue()
        ->and(Money::of('10')->minus(Money::of('10.001'))->toDecimalString())->toBe('-0.001')
        ->and(Money::of('5')->isGreaterThan(Money::of('4.999')))->toBeTrue()
        ->and(Money::zero()->isZero())->toBeTrue();
});

it('affiche le montant en français', function (string $amount, string $expected) {
    expect(Money::of($amount)->format())->toBe($expected);
})->with([
    ['1234.5', "1\u{202F}234,500 DT"],
    ['0.075', '0,075 DT'],
    ['-1234567.891', "-1\u{202F}234\u{202F}567,891 DT"],
]);
