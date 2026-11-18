<?php

use App\Support\Text\SearchNormalizer;

it('normalise un nom sans accents, en minuscules, espaces simples', function (string $input, string $expected) {
    expect(SearchNormalizer::name($input))->toBe($expected);
})->with([
    'accents' => ['  Hédi   BEN Salâh ', 'hedi ben salah'],
    'tiret et apostrophe' => ["Ben-Ali O'Neil", 'ben ali o neil'],
    'déjà normalisé' => ['sami t', 'sami t'],
]);

it('normalise un identifiant en lettres et chiffres majuscules', function (string $input, string $expected) {
    expect(SearchNormalizer::identifier($input))->toBe($expected);
})->with([
    'CIN avec espaces' => ['12 345 678', '12345678'],
    'permis avec barre' => ['12/345678', '12345678'],
    'passeport minuscules' => ['ab-123 456', 'AB123456'],
]);

it('normalise un téléphone avec l\'indicatif tunisien par défaut', function (string $input, string $expected) {
    expect(SearchNormalizer::phone($input))->toBe($expected);
})->with([
    'national' => ['98 765 432', '21698765432'],
    'international +' => ['+216 98 765 432', '21698765432'],
    'international 00' => ['00216 98-765-432', '21698765432'],
    'étranger' => ['+33 6 12 34 56 78', '33612345678'],
]);
