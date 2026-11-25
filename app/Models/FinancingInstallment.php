<?php

namespace App\Models;

use App\Support\Audit\AuditsChanges;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Échéance d'un financement (B-12) : loyer de leasing ou mensualité de crédit. Imputée
 * au mois de son échéance (`charge_month`, 1er du mois, RG-17, Q58), une seule fois
 * (unique par rang). Le pointage (`paid_on`) ne change jamais l'imputation.
 *
 * @property int $id
 * @property int $vehicle_financing_id
 * @property int $sequence
 * @property CarbonImmutable $due_date
 * @property Money $amount_excl_tax
 * @property Money $amount_incl_tax
 * @property Money|null $interest_amount
 * @property CarbonImmutable $charge_month
 * @property CarbonImmutable|null $paid_on
 */
class FinancingInstallment extends Model
{
    use AuditsChanges;

    protected $fillable = ['vehicle_financing_id', 'sequence', 'due_date', 'amount_excl_tax', 'amount_incl_tax', 'interest_amount', 'charge_month', 'paid_on'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'due_date' => 'immutable_date',
            'amount_excl_tax' => MoneyCast::class,
            'amount_incl_tax' => MoneyCast::class,
            'interest_amount' => MoneyCast::class,
            'charge_month' => 'immutable_date',
            'paid_on' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<VehicleFinancing, $this>
     */
    public function financing(): BelongsTo
    {
        return $this->belongsTo(VehicleFinancing::class, 'vehicle_financing_id');
    }
}
