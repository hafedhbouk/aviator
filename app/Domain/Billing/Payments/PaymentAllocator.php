<?php

declare(strict_types=1);

namespace App\Domain\Billing\Payments;

use App\Domain\Billing\Exceptions\PaymentException;
use App\Domain\Billing\InvoiceBalance;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Support\Money\AmountInput;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Imputation d'un encaissement sur des factures (E-06, Q81, D57) : proposée « la plus
 * ancienne d'abord » (propose()), puis confirmée par l'agent (allocate()). Sous verrou
 * de l'encaissement puis des factures, dans l'ordre de leurs identifiants : Σ imputations
 * ≤ montant encaissé et ≤ reste dû de chaque facture, même sous saisies simultanées.
 * La part non imputée reste un acompte du client, imputable plus tard. Le statut de
 * paiement de chaque facture est recalculé dans la même transaction.
 */
final class PaymentAllocator
{
    /**
     * Proposition « la plus ancienne d'abord » : factures du client avec un reste dû,
     * par date de validation ; acomptes de la réservation du contrat en priorité sur
     * sa facture (D37).
     *
     * @return array<int, Money> facture => montant proposé
     */
    public function propose(int $customerId, Money $amount, User $actor): array
    {
        $proposal = [];
        $left = $amount;

        foreach ($this->openInvoices($customerId, $actor) as $invoice) {
            if (! $left->isPositive()) {
                break;
            }

            $remaining = InvoiceBalance::remaining($invoice);
            $share = $remaining->isGreaterThan($left) ? $left : $remaining;
            $proposal[$invoice->id] = $share;
            $left = $left->minus($share);
        }

        return $proposal;
    }

    /**
     * Factures du client qui ont un reste dû et que l'agent peut voir, la plus ancienne
     * d'abord.
     *
     * @return list<Invoice>
     */
    public function openInvoices(int $customerId, User $actor): array
    {
        return Invoice::query()->withoutGlobalScopes()
            ->where('customer_id', $customerId)
            ->whereNotNull('validated_at')
            ->orderBy('validated_at')->orderBy('id')
            ->get()
            ->filter(fn (Invoice $invoice): bool => InvoiceBalance::canReceive($invoice)
                && $actor->can('view', $invoice)
                && InvoiceBalance::remaining($invoice)->isPositive())
            ->values()->all();
    }

    /**
     * Acomptes encore non imputés de la réservation d'où vient le contrat de la facture
     * (D37), à proposer en premier sur cette facture.
     *
     * @return list<Payment>
     */
    public function depositsFor(Invoice $invoice): array
    {
        $reservationId = $invoice->contract_id === null ? null
            : Contract::query()->withoutGlobalScopes()->whereKey($invoice->contract_id)->value('reservation_id');

        if ($reservationId === null) {
            return [];
        }

        return Payment::query()->withoutGlobalScopes()
            ->where('reservation_id', $reservationId)->where('status', Payment::RECORDED)->orderBy('received_at')->get()
            ->filter(fn (Payment $payment): bool => self::unallocated($payment)->isPositive())
            ->values()->all();
    }

    /**
     * Part d'un encaissement encore non imputée (acompte) ; nulle s'il est annulé. Lue sur
     * le cumul de la ligne (à jour sous son verrou, voir InvoiceBalance).
     */
    public static function unallocated(Payment $payment): Money
    {
        return $payment->isCancelled() ? Money::zero() : $payment->amount->minus($payment->allocated_amount);
    }

    /**
     * Imputations confirmées par l'agent.
     *
     * @param  array<int|string, mixed>  $amounts  facture => montant
     * @return list<PaymentAllocation>
     */
    public function allocate(Payment $payment, array $amounts, User $actor): array
    {
        if (! $actor->can('allocate', $payment)) {
            throw PaymentException::notAllowed();
        }

        $values = [];

        foreach ($amounts as $invoiceId => $value) {
            try {
                $amount = AmountInput::parse($value);
            } catch (InvalidArgumentException $exception) {
                throw PaymentException::invalidInvoice($exception->getMessage());
            }

            if ($amount !== null && $amount->isPositive()) {
                $values[(int) $invoiceId] = $amount;
            }
        }

        if ($values === []) {
            return [];
        }

        return DB::transaction(function () use ($payment, $values, $actor): array {
            $locked = Payment::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($payment->id);

            if ($locked->isCancelled()) {
                throw PaymentException::cancelled();
            }

            $ids = array_keys($values);
            sort($ids);
            $invoices = Invoice::query()->withoutGlobalScopes()->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $total = array_reduce($values, fn (Money $sum, Money $amount): Money => $sum->plus($amount), Money::zero());
            $unallocated = self::unallocated($locked);

            if ($total->isGreaterThan($unallocated)) {
                throw PaymentException::paymentExceeded($unallocated->format());
            }

            $created = [];

            foreach ($values as $invoiceId => $amount) {
                $invoice = $invoices->get($invoiceId);

                if (! $invoice instanceof Invoice || ! InvoiceBalance::canReceive($invoice)) {
                    throw PaymentException::invalidInvoice('seule une facture validée reçoit un encaissement.');
                }

                if ($invoice->customer_id !== $locked->customer_id) {
                    throw PaymentException::invalidInvoice("la facture {$invoice->number} n'est pas celle du client de l'encaissement.");
                }

                if (! $actor->can('view', $invoice)) {
                    throw PaymentException::notAllowed();
                }

                $remaining = InvoiceBalance::remaining($invoice);

                if ($amount->isGreaterThan($remaining)) {
                    throw PaymentException::invoiceExceeded((string) $invoice->number, $remaining->format());
                }

                $created[] = PaymentAllocation::query()->create([
                    'payment_id' => $locked->id,
                    'invoice_id' => $invoice->id,
                    'amount' => $amount,
                    'created_by' => $actor->id,
                ]);

                InvoiceBalance::apply($invoice, $amount);
            }

            $locked->forceFill(['allocated_amount' => $locked->allocated_amount->plus($total)])->save();

            return $created;
        });
    }
}
