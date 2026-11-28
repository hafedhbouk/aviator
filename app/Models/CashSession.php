<?php

namespace App\Models;

use App\Support\Agency\BelongsToAgency;
use App\Support\Audit\AuditsChanges;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Caisse d'une agence pour un jour (E-09, Q55) : fond de caisse à l'ouverture, montant
 * compté et écart motivé à la clôture. Une seule par agence et par jour. Clôturée, elle
 * ne change plus ; jamais supprimée. Écrite uniquement par App\Domain\Billing\Cash.
 *
 * @property int $id
 * @property int $agency_id
 * @property CarbonImmutable $business_date
 * @property CarbonImmutable $opened_at
 * @property int|null $opened_by
 * @property Money $opening_amount
 * @property CarbonImmutable|null $closed_at
 * @property int|null $closed_by
 * @property Money|null $expected_amount
 * @property Money|null $counted_amount
 * @property Money|null $difference_amount
 * @property string|null $difference_reason
 */
#[Fillable([
    'agency_id', 'business_date', 'opened_at', 'opened_by', 'opening_amount', 'closed_at', 'closed_by',
    'expected_amount', 'counted_amount', 'difference_amount', 'difference_reason',
])]
class CashSession extends Model
{
    use AuditsChanges;
    use BelongsToAgency;

    protected static function booted(): void
    {
        static::updating(function (CashSession $session): void {
            if ($session->getOriginal('closed_at') !== null) {
                throw new LogicException('Une caisse clôturée ne se modifie plus.');
            }
        });

        static::deleting(fn () => throw new LogicException('Une caisse ne se supprime pas.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'business_date' => 'immutable_date',
            'opened_at' => 'immutable_datetime',
            'opening_amount' => MoneyCast::class,
            'closed_at' => 'immutable_datetime',
            'expected_amount' => MoneyCast::class,
            'counted_amount' => MoneyCast::class,
            'difference_amount' => MoneyCast::class,
        ];
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }
}
