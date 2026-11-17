<?php

use App\Models\Customer;
use App\Support\Privacy\BlindIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('calcule le nom de recherche, le téléphone normalisé et l\'empreinte de la pièce', function () {
    $customer = Customer::factory()->create([
        'first_name' => 'Hédi',
        'last_name' => 'Ben Salah',
        'phone' => '98 765 432',
        'id_document_number' => '12 345 678',
    ]);

    expect($customer->search_name)->toBe('hedi ben salah')
        ->and($customer->phone_search)->toBe('21698765432')
        ->and($customer->id_document_hash)->toBe(app(BlindIndex::class)->hash('12345678', Customer::ID_DOCUMENT_INDEX));
});

it('retrouve la même pièce quelle que soit la saisie, pour que la liste noire ne soit pas contournée', function () {
    $first = Customer::factory()->create(['id_document_number' => '0123 4567']);
    $second = Customer::factory()->create(['id_document_number' => '0123-4567']);
    $third = Customer::factory()->create(['id_document_number' => '01234567']);

    expect($first->id_document_hash)
        ->toBe($second->id_document_hash)
        ->toBe($third->id_document_hash)
        ->and(Customer::query()->where('id_document_hash', $third->id_document_hash)->count())->toBe(3);
});

it('stocke le numéro de pièce chiffré, jamais en clair (D8)', function () {
    $customer = Customer::factory()->create(['id_document_number' => '12345678']);

    $raw = (string) DB::table('customers')->where('id', $customer->id)->value('id_document_number');

    expect($raw)->not->toContain('12345678')
        ->and($customer->fresh()?->id_document_number)->toBe('12345678');
});

it('n\'expose ni le numéro de pièce ni les empreintes à la sérialisation', function () {
    $array = Customer::factory()->create()->toArray();

    expect($array)->not->toHaveKeys(['id_document_number', 'id_document_hash', 'phone_search']);
});

it('affiche la raison sociale pour une entreprise', function () {
    $customer = Customer::factory()->company()->create(['company_name' => 'Société Exemple SARL']);

    expect($customer->displayName())->toBe('Société Exemple SARL')
        ->and($customer->search_name)->toBe('societe exemple sarl');
});

it('vide l\'empreinte quand le numéro de pièce est retiré', function () {
    $customer = Customer::factory()->create(['id_document_number' => '12345678']);
    $customer->update(['id_document_number' => null]);

    expect($customer->fresh()?->id_document_hash)->toBeNull();
});
