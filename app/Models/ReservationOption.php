<?php

namespace App\Models;

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Option retenue sur une réservation, au prix du devis figé.
 *
 * @property int $id
 * @property int $reservation_id
 * @property int $option_id
 * @property int $quantity
 * @property Money $unit_amount
 * @property Money $total_amount
 */
class ReservationOption extends Model
{
    public $timestamps = false;

    protected $fillable = ['reservation_id', 'option_id', 'quantity', 'unit_amount', 'total_amount'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_amount' => MoneyCast::class,
            'total_amount' => MoneyCast::class,
        ];
    }

    /**
     * @return BelongsTo<Option, $this>
     */
    public function option(): BelongsTo
    {
        return $this->belongsTo(Option::class);
    }
}
