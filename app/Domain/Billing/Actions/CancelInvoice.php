<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\ClosedPeriodGuard;
use App\Domain\Billing\Exceptions\InvoiceValidationException;
use App\Domain\Billing\InvoiceBalance;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Annulation sans avoir (E11, Q79, D56) : seulement une facture validée (numérotée)
 * **jamais émise** au client, motif obligatoire. Le numéro est conservé (aucun trou
 * dans la série, RG-07) ; la période d'une facture de location redevient facturable.
 * Une facture émise ne s'annule pas : elle se corrige par un avoir (L6). Garde de
 * période sur le mois de la validation (D53).
 */
final class CancelInvoice
{
    public const MIN_REASON_LENGTH = 10;

    public function __construct(private readonly ClosedPeriodGuard $periods) {}

    public function handle(Invoice $invoice, string $reason, User $actor): Invoice
    {
        if (! $actor->can('cancel', $invoice)) {
            throw InvoiceValidationException::notAllowed();
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            throw InvoiceValidationException::cancellationReason(self::MIN_REASON_LENGTH);
        }

        return DB::transaction(function () use ($invoice, $reason, $actor): Invoice {
            $locked = Invoice::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($invoice->id);

            if ($locked->status === InvoiceStatus::Issued || $locked->status === InvoiceStatus::Credited) {
                throw InvoiceValidationException::issuedNeedsCreditNote();
            }

            if ($locked->status !== InvoiceStatus::Validated) {
                throw InvoiceValidationException::invalidStatus($locked->status->label(), 'l\'annulation');
            }

            if (InvoiceBalance::paid($locked)->isPositive()) {
                throw InvoiceValidationException::cancelWithAllocations();
            }

            $this->periods->assertOpen($locked->validated_at ?? CarbonImmutable::now(), 'l\'annulation de la facture');

            $locked->fill([
                'status' => InvoiceStatus::Cancelled,
                'cancellation_reason' => mb_substr($reason, 0, 500),
                'cancelled_at' => CarbonImmutable::now(),
                'cancelled_by' => $actor->id,
            ])->save();

            return $locked->refresh();
        });
    }
}
