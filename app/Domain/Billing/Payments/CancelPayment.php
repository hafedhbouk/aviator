<?php

declare(strict_types=1);

namespace App\Domain\Billing\Payments;

use App\Domain\Billing\ClosedPeriodGuard;
use App\Domain\Billing\Exceptions\PaymentException;
use App\Domain\Billing\InvoiceBalance;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Annulation d'un encaissement (RG-11, D57) : jamais supprimé, il passe au statut
 * « annulé » avec un motif ; ses imputations ne comptent plus et le statut de paiement
 * des factures concernées est recalculé, sous verrou (encaissement, puis factures dans
 * l'ordre de leurs identifiants). Garde de période sur la date de réception (D53).
 */
final class CancelPayment
{
    public const MIN_REASON_LENGTH = 10;

    public function __construct(private readonly ClosedPeriodGuard $periods) {}

    public function handle(Payment $payment, string $reason, User $actor): Payment
    {
        if (! $actor->can('cancel', $payment)) {
            throw PaymentException::notAllowed();
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            throw PaymentException::reasonRequired(self::MIN_REASON_LENGTH);
        }

        return DB::transaction(function () use ($payment, $reason, $actor): Payment {
            $locked = Payment::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($payment->id);

            if ($locked->isCancelled()) {
                throw PaymentException::alreadyCancelled();
            }

            $this->periods->assertOpen($locked->received_at, 'l\'annulation de l\'encaissement');

            // Imputations de cet encaissement : créées seulement sous son verrou, déjà tenu ici.
            $allocations = PaymentAllocation::query()->where('payment_id', $locked->id)->get();
            $invoiceIds = $allocations->pluck('invoice_id')->unique()->sort()->values()->all();
            $invoices = Invoice::query()->withoutGlobalScopes()->whereKey($invoiceIds)->orderBy('id')->lockForUpdate()->get();

            $locked->fill([
                'status' => Payment::CANCELLED,
                'cancellation_reason' => mb_substr($reason, 0, 500),
                'cancelled_at' => CarbonImmutable::now(),
                'cancelled_by' => $actor->id,
            ])->save();

            foreach ($invoices as $invoice) {
                $amount = $allocations->where('invoice_id', $invoice->id)
                    ->reduce(fn (Money $sum, PaymentAllocation $allocation): Money => $sum->plus($allocation->amount), Money::zero());
                InvoiceBalance::apply($invoice, $amount->negated());
            }

            return $locked->refresh();
        });
    }
}
