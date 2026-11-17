<?php

use App\Models\Agency;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('crée les 4 agences, dont Djerba comme siège', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Agency::query()->orderBy('code')->pluck('name', 'code')->all())->toBe([
        'DJE' => 'Djerba',
        'SFX' => 'Sfax',
        'SOU' => 'Sousse',
        'TUN' => 'Tunis',
    ]);

    expect(Agency::query()->where('is_head_office', true)->pluck('code')->all())->toBe(['DJE']);
});

it('peut être rejoué sans créer de doublon', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(Agency::query()->count())->toBe(4);
});

it('ne crée aucun compte utilisateur (Q2)', function () {
    $this->seed(DatabaseSeeder::class);

    expect(User::query()->count())->toBe(0);
});
