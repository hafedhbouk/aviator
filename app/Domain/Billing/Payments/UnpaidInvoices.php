<?php

declare(strict_types=1);

namespace App\Domain\Billing\Payments;

use App\Domain\Billing\InvoiceBalance;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Factures impayées et leur ancienneté (E-07) : factures et factures complémentaires
 * validées, émises ou créditées avec un reste dû, dans la portée de l'utilisateur
 * (AgencyScope). Ancienneté en jours depuis l'échéance (ou la validation), et tranche :
 * à échoir, 0-30, 31-60, 61-90, plus de 90 jours.
 */
final class UnpaidInvoices
{
    /**
     * @return list<array{invoice: Invoice, remaining: Money, days_overdue: int, bucket: string}>
     */
    public function list(?CarbonImmutable $at = null): array
    {
        $today = ($at ?? CarbonImmutable::now())->timezone((string) config('app.timezone'))->startOfDay();
        $rows = [];

        $invoices = Invoice::query()
            ->whereIn('payment_status', [PaymentStatus::Unpaid, PaymentStatus::PartiallyPaid])
            ->whereNotNull('validated_at')
            ->orderBy('due_on')->orderBy('validated_at')->orderBy('id')
            ->get();

        foreach ($invoices as $invoice) {
            if (! InvoiceBalance::canReceive($invoice)) {
                continue;
            }

            $remaining = InvoiceBalance::remaining($invoice);

            if (! $remaining->isPositive()) {
                continue;
            }

            $due = $invoice->due_on ?? $invoice->validated_at?->timezone((string) config('app.timezone'))->startOfDay() ?? $today;
            $days = (int) $due->startOfDay()->diffInDays($today, false);

            $rows[] = [
                'invoice' => $invoice,
                'remaining' => $remaining,
                'days_overdue' => max(0, $days),
                'bucket' => self::bucket($days),
            ];
        }

        return $rows;
    }

    public static function bucket(int $days): string
    {
        return match (true) {
            $days <= 0 => 'à échoir',
            $days <= 30 => '0-30 jours',
            $days <= 60 => '31-60 jours',
            $days <= 90 => '61-90 jours',
            default => 'plus de 90 jours',
        };
    }
}
