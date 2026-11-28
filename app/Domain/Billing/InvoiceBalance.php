<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\PaymentStatus;
use App\Models\CreditNoteSettlement;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Support\Money\Money;

/**
 * Reste dû et statut de paiement d'une facture (E11, D56) : net à payer moins le cumul
 * réglé (`paid_amount` : encaissements non annulés et avoirs imputés, E19). Le statut de
 * paiement est toujours calculé ici, jamais saisi : non payée, partiellement payée,
 * payée.
 *
 * Le cumul est tenu sur la ligne de la facture, modifiée seulement sous son verrou
 * (apply()) : la ligne verrouillée rend toujours sa dernière version validée, même si
 * l'instantané de la transaction précède le règlement d'un processus concurrent. La
 * somme des imputations (fromRecords()) sert au contrôle de cohérence.
 */
final class InvoiceBalance
{
    public static function paid(Invoice $invoice): Money
    {
        return $invoice->paid_amount;
    }

    public static function remaining(Invoice $invoice): Money
    {
        return $invoice->total_due_amount->minus($invoice->paid_amount);
    }

    /**
     * Facture qui peut recevoir une imputation : facture ou facture complémentaire
     * validée, émise ou créditée (un avoir imputé sur elle la solde), jamais un
     * brouillon, une facture annulée ni un avoir.
     */
    public static function canReceive(Invoice $invoice): bool
    {
        return $invoice->type !== InvoiceType::CreditNote
            && in_array($invoice->status, [InvoiceStatus::Validated, InvoiceStatus::Issued, InvoiceStatus::Credited], true);
    }

    /**
     * Ajoute un règlement (négatif à l'annulation) au cumul d'une facture **verrouillée**
     * et recalcule son statut de paiement, dans la transaction qui écrit l'imputation.
     */
    public static function apply(Invoice $locked, Money $amount): void
    {
        $paid = $locked->paid_amount->plus($amount);

        $locked->forceFill([
            'paid_amount' => $paid,
            'payment_status' => self::status($paid, $locked->total_due_amount),
        ])->save();
    }

    public static function status(Money $paid, Money $due): PaymentStatus
    {
        return match (true) {
            ! $paid->isPositive() => PaymentStatus::Unpaid,
            $paid->compareTo($due) >= 0 => PaymentStatus::Paid,
            default => PaymentStatus::PartiallyPaid,
        };
    }

    /**
     * Cumul recalculé depuis les imputations enregistrées (encaissements non annulés,
     * avoirs imputés) : doit égaler `paid_amount`.
     */
    public static function fromRecords(Invoice $invoice): Money
    {
        $payments = PaymentAllocation::query()
            ->where('invoice_id', $invoice->id)
            ->whereIn('payment_id', Payment::query()->withoutGlobalScopes()->select('id')->where('status', Payment::RECORDED))
            ->get()
            ->reduce(fn (Money $sum, PaymentAllocation $allocation): Money => $sum->plus($allocation->amount), Money::zero());

        $credits = CreditNoteSettlement::query()
            ->where('invoice_id', $invoice->id)
            ->where('kind', CreditNoteSettlement::ALLOCATION)
            ->get()
            ->reduce(fn (Money $sum, CreditNoteSettlement $settlement): Money => $sum->plus($settlement->amount), Money::zero());

        return $payments->plus($credits);
    }
}
