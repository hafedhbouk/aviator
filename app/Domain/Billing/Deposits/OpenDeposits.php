<?php

declare(strict_types=1);

namespace App\Domain\Billing\Deposits;

use App\Enums\DepositStatus;
use App\Models\Deposit;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Cautions en cours et leur ancienneté (F-08) : reste à restituer ou retenir positif, dans
 * la portée de l'utilisateur (AgencyScope), la plus ancienne d'abord. Hors chiffre
 * d'affaires (F-07) : liste et journal à part.
 */
final class OpenDeposits
{
    /**
     * @return list<array{deposit: Deposit, remaining: Money, status: DepositStatus, days: int}>
     */
    public function list(?CarbonImmutable $at = null): array
    {
        $today = ($at ?? CarbonImmutable::now())->timezone((string) config('app.timezone'))->startOfDay();
        $rows = [];

        foreach (Deposit::query()->orderBy('received_at')->orderBy('id')->get() as $deposit) {
            $remaining = $deposit->remaining();

            if (! $remaining->isPositive()) {
                continue;
            }

            $received = $deposit->received_at->timezone((string) config('app.timezone'))->startOfDay();

            $rows[] = [
                'deposit' => $deposit,
                'remaining' => $remaining,
                'status' => $deposit->status(),
                'days' => (int) $received->diffInDays($today),
            ];
        }

        return $rows;
    }
}
