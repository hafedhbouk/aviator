<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Financing;

use App\Enums\FinancingType;
use App\Models\FinancingInstallment;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Charges de financement d'un mois (scénario 15, RG-17), réutilisées par les
 * statistiques de la phase 9 :
 * - leasing : loyer **HT** de chaque échéance du mois (Q57 : HT, sous réserve) ;
 * - crédit : **intérêts** seulement (Q59 : le capital n'est pas une charge, le prix
 *   d'achat étant réparti à part, Q19).
 * Chaque échéance est comptée une seule fois, au mois de son échéance (`charge_month`,
 * Q58), qu'elle soit pointée ou non. L'apport initial est réparti en phase 9 (Q19).
 */
final class FinancingCharges
{
    public function forVehicleAndMonth(int $vehicleId, CarbonImmutable $month): Money
    {
        return $this->forMonth($month)[$vehicleId] ?? Money::zero();
    }

    /**
     * @return array<int, Money> charges par véhicule
     */
    public function forMonth(CarbonImmutable $month): array
    {
        $installments = FinancingInstallment::query()
            ->with('financing')
            ->where('charge_month', $month->startOfMonth()->toDateString())
            ->get();

        $charges = [];

        foreach ($installments as $installment) {
            $financing = $installment->financing;
            $amount = $financing->type === FinancingType::Credit
                ? ($installment->interest_amount ?? Money::zero())
                : $installment->amount_excl_tax;

            $charges[$financing->vehicle_id] = ($charges[$financing->vehicle_id] ?? Money::zero())->plus($amount);
        }

        return $charges;
    }
}
