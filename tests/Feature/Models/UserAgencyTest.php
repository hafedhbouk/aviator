<?php

use App\Models\Agency;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rattache un utilisateur à une agence par défaut et à plusieurs agences (Q69)', function () {
    [$sfax, $sousse] = Agency::factory()->count(2)->create();
    $user = User::factory()->create(['agency_id' => $sfax->id]);
    $user->agencies()->attach([$sfax->id, $sousse->id]);

    expect($user->agency?->is($sfax))->toBeTrue()
        ->and($user->agencies()->pluck('agencies.id')->sort()->values()->all())
        ->toBe(collect([$sfax->id, $sousse->id])->sort()->values()->all());
});

it('crée un utilisateur actif par défaut', function () {
    expect(User::factory()->create()->fresh()?->is_active)->toBeTrue();
});

it('masque le mot de passe et les secrets de 2FA à la sérialisation', function () {
    $user = User::factory()->create();

    expect($user->toArray())->not->toHaveKeys([
        'password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes',
    ]);
});

it('refuse de supprimer une agence encore rattachée à un utilisateur', function () {
    $agency = Agency::factory()->create();
    User::factory()->create(['agency_id' => $agency->id]);

    $agency->delete();
})->throws(QueryException::class);
