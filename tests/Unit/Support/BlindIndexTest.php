<?php

use App\Support\Privacy\BlindIndex;

const TEST_BLIND_INDEX_KEY = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

it('donne la même empreinte pour la même pièce, quelle que soit la saisie', function () {
    $index = new BlindIndex(TEST_BLIND_INDEX_KEY);

    expect($index->hash('12 345 678', 'id_document'))
        ->toBe($index->hash('12345678', 'id_document'))
        ->toMatch('/^[0-9a-f]{64}$/');
});

it('harmonise la saisie avant le HMAC : majuscules, sans espaces, tirets ni points', function (string $input) {
    $index = new BlindIndex(TEST_BLIND_INDEX_KEY);

    expect($index->hash($input, 'id_document'))->toBe($index->hash('01234567', 'id_document'));
})->with([
    'espace' => ['0123 4567'],
    'tiret' => ['0123-4567'],
    'sans séparateur' => ['01234567'],
    'points' => ['0123.4567'],
    'espaces en bordure' => ['  0123 4567  '],
    'mélange' => [' 0123 - 45.67 '],
]);

it('harmonise les lettres d\'un passeport sans tenir compte de la casse', function () {
    $index = new BlindIndex(TEST_BLIND_INDEX_KEY);

    expect($index->hash('ab-123.456', 'id_document'))->toBe($index->hash('AB 123456', 'id_document'));
});

it('conserve le zéro initial : 01234567 et 1234567 sont deux pièces différentes', function () {
    $index = new BlindIndex(TEST_BLIND_INDEX_KEY);

    expect($index->hash('01234567', 'id_document'))->not->toBe($index->hash('1234567', 'id_document'));
});

it('donne des empreintes différentes selon l\'usage et selon la clé', function () {
    $index = new BlindIndex(TEST_BLIND_INDEX_KEY);
    $other = new BlindIndex(strrev(TEST_BLIND_INDEX_KEY));

    expect($index->hash('12345678', 'id_document'))
        ->not->toBe($index->hash('12345678', 'license'))
        ->not->toBe($other->hash('12345678', 'id_document'));
});

it('ne contient jamais le numéro en clair', function () {
    $hash = (new BlindIndex(TEST_BLIND_INDEX_KEY))->hash('12345678', 'id_document');

    expect($hash)->not->toContain('12345678');
});

it('refuse une clé absente ou trop courte', function (string $key) {
    new BlindIndex($key);
})->with(['vide' => [''], 'courte' => ['trop-courte']])
    ->throws(RuntimeException::class, 'AVIATOR_BLIND_INDEX_KEY');
