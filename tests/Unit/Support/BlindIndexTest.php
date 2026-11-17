<?php

use App\Support\Privacy\BlindIndex;

const TEST_BLIND_INDEX_KEY = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

it('donne la même empreinte pour la même pièce, quelle que soit la saisie', function () {
    $index = new BlindIndex(TEST_BLIND_INDEX_KEY);

    expect($index->hash('12 345 678', 'id_document'))
        ->toBe($index->hash('12345678', 'id_document'))
        ->toMatch('/^[0-9a-f]{64}$/');
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
