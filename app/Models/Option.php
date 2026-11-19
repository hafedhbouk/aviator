<?php

namespace App\Models;

use App\Support\Audit\AuditsChanges;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Option facturable (A-05) : conducteur supplémentaire, siège enfant, GPS, livraison,
 * franchise réduite… Prix HT par jour ou par location, plafond facultatif, code fiscal
 * choisi explicitement. Modifiée uniquement par App\Domain\Setup\Actions\SaveOption ;
 * jamais supprimée (désactivation).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $pricing_unit per_day ou per_rental
 * @property Money|null $amount
 * @property Money|null $max_amount_per_rental
 * @property int|null $tax_code_id
 * @property bool $is_active
 */
#[Fillable(['code', 'name', 'pricing_unit', 'amount', 'max_amount_per_rental', 'tax_code_id', 'is_active'])]
class Option extends Model
{
    use AuditsChanges;

    public const PER_DAY = 'per_day';

    public const PER_RENTAL = 'per_rental';

    public const PRICING_UNITS = [self::PER_DAY => 'Par jour', self::PER_RENTAL => 'Par location'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'max_amount_per_rental' => MoneyCast::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<TaxCode, $this>
     */
    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class);
    }
}
