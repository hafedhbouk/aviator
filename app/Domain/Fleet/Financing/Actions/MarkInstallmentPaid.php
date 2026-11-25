<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Financing\Actions;

use App\Domain\Fleet\Exceptions\FleetException;
use App\Models\FinancingInstallment;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Pointage d'une échéance (B-12) : date de paiement, jamais dans le futur ; une
 * date vide annule le pointage (correction, journalisée). Le mois d'imputation ne
 * change jamais (RG-17, Q58 : mois de l'échéance).
 */
final class MarkInstallmentPaid
{
    public function handle(FinancingInstallment $installment, ?CarbonImmutable $paidOn, User $actor): FinancingInstallment
    {
        if (! $actor->can('update', $installment->financing)) {
            throw FleetException::notAllowed('les échéances');
        }

        if ($paidOn !== null && $paidOn->startOfDay()->isAfter(CarbonImmutable::today())) {
            throw FleetException::invalidPaymentDate();
        }

        $installment->paid_on = $paidOn?->startOfDay();
        $installment->save();

        return $installment;
    }
}
