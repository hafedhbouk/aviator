<?php

namespace Tests\Fixtures\Models;

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Model;

/**
 * Modèle de test : montants en DECIMAL(15,3) castés en Money.
 *
 * @property Money $amount
 * @property Money|null $optional_amount
 */
class PricedItem extends Model
{
    protected $table = 'test_priced_items';

    protected $fillable = ['amount', 'optional_amount'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'optional_amount' => MoneyCast::class,
        ];
    }
}
