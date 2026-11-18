<?php

use App\Support\Money\Money;
use Brick\Math\Exception\RoundingNecessaryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Models\PricedItem;

uses(RefreshDatabase::class);

it('stocke un montant exact en DECIMAL(15,3) et le relit en Money', function () {
    $item = PricedItem::query()->create(['amount' => Money::of('1234.567')]);

    $raw = DB::scalar('SELECT amount FROM test_priced_items WHERE id = ?', [$item->id]);

    expect($raw)->toBe('1234.567')
        ->and($item->fresh()?->amount)->toBeInstanceOf(Money::class)
        ->and($item->fresh()?->amount->toDecimalString())->toBe('1234.567');
});

it('accepte une chaîne décimale ou un entier', function () {
    $item = PricedItem::query()->create(['amount' => '12.5', 'optional_amount' => 7]);

    expect($item->fresh()?->amount->toDecimalString())->toBe('12.500')
        ->and($item->fresh()?->optional_amount?->toDecimalString())->toBe('7.000');
});

it('conserve un montant nul (NULL)', function () {
    $item = PricedItem::query()->create(['amount' => '1', 'optional_amount' => null]);

    expect($item->fresh()?->optional_amount)->toBeNull();
});

it('refuse un montant fourni en float', function () {
    PricedItem::query()->create(['amount' => 12.5]);
})->throws(InvalidArgumentException::class, 'float');

it('refuse un montant de plus de 3 décimales sans arrondi explicite', function () {
    PricedItem::query()->create(['amount' => '1.0005']);
})->throws(RoundingNecessaryException::class);

it('additionne en base sans perte (somme SQL exacte au millime)', function () {
    foreach (['0.1', '0.2', '0.3'] as $amount) {
        PricedItem::query()->create(['amount' => $amount]);
    }

    expect(PricedItem::query()->sum('amount'))->toBe('0.600');
});
