<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Financing;

use App\Enums\FinancingType;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Échéancier d'un financement (B-12), sans effet de bord.
 *
 * - Leasing : une échéance par mois à partir de la première échéance, jusqu'à la fin du
 *   contrat ; loyer HT et TTC tels que saisis (aucun taux de TVA déduit ici).
 * - Crédit (B-15) : mensualité constante d'un prêt amortissable,
 *   A = P × r × (1 + r)^n / ((1 + r)^n − 1), r = taux annuel / 12 ; intérêts de chaque
 *   mois sur le capital restant, arrondis au millime ; la dernière mensualité solde le
 *   capital. Sans TVA. Taux nul : capital / n.
 * - Chaque échéance est imputée au mois de son échéance (RG-17, Q58).
 */
final class FinancingSchedule
{
    /**
     * Nombre d'échéances mensuelles d'un leasing entre la première échéance et la fin.
     */
    public static function leasingCount(CarbonImmutable $firstDueOn, CarbonImmutable $endsOn): int
    {
        $count = 0;
        while ($firstDueOn->addMonthsNoOverflow($count)->lessThanOrEqualTo($endsOn)) {
            $count++;
        }

        return $count;
    }

    /**
     * Mensualité constante d'un crédit, au millime.
     */
    public static function creditPayment(Money $principal, string $annualRatePercent, int $count): Money
    {
        $rate = BigDecimal::of($annualRatePercent)->dividedBy(1200, 12, RoundingMode::HalfUp);

        if ($rate->isZero()) {
            return $principal->dividedBy($count, RoundingMode::HalfUp);
        }

        $growth = $rate->plus(1)->power($count);
        $factor = $rate->multipliedBy($growth)->dividedBy($growth->minus(1), 12, RoundingMode::HalfUp);

        return $principal->multipliedBy($factor, RoundingMode::HalfUp);
    }

    /**
     * @return list<array{sequence: int, due_date: CarbonImmutable, amount_excl_tax: Money, amount_incl_tax: Money, interest_amount: Money|null, charge_month: CarbonImmutable}>
     */
    public static function build(
        FinancingType $type,
        CarbonImmutable $firstDueOn,
        int $count,
        Money $monthlyExcludingTax,
        Money $monthlyIncludingTax,
        ?Money $principal = null,
        ?string $annualRatePercent = null,
    ): array {
        $rows = [];
        $balance = $principal;
        $rate = $annualRatePercent === null ? null : BigDecimal::of($annualRatePercent)->dividedBy(1200, 12, RoundingMode::HalfUp);

        for ($i = 0; $i < $count; $i++) {
            $due = $firstDueOn->addMonthsNoOverflow($i);
            $excl = $monthlyExcludingTax;
            $incl = $monthlyIncludingTax;
            $interest = null;

            if ($type === FinancingType::Credit && $balance !== null && $rate !== null) {
                $interest = $balance->multipliedBy($rate, RoundingMode::HalfUp);
                $capital = $excl->minus($interest);

                if ($i === $count - 1 || $capital->compareTo($balance) > 0) {
                    $capital = $balance;
                    $excl = $capital->plus($interest);
                }

                $incl = $excl;
                $balance = $balance->minus($capital);
            }

            $rows[] = [
                'sequence' => $i + 1,
                'due_date' => $due,
                'amount_excl_tax' => $excl,
                'amount_incl_tax' => $incl,
                'interest_amount' => $interest,
                'charge_month' => $due->startOfMonth(),
            ];
        }

        return $rows;
    }
}
