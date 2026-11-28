<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\ClosedPeriodGuard;
use App\Domain\Billing\Exceptions\CreditNoteException;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\CreditNoteSettlement;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\Money\AmountInput;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Règlement d'un avoir (E19) : **remboursé** au client (décaissement tracé : mode et
 * référence) ou **imputé** sur une facture du même client (la facture d'origine ou une
 * autre), totalement ou partiellement. Sous verrou de l'avoir (et de la facture
 * imputée, dans l'ordre des identifiants) : un avoir n'est jamais réglé au-delà de son
 * montant, ni imputé au-delà du reste dû de la facture.
 *
 * Le reste dû tient compte ici des imputations d'avoirs ; les encaissements (L7)
 * s'y ajouteront, avec le statut de paiement calculé. Un remboursement en espèces
 * passera par la caisse ouverte de l'agence (L8).
 */
final class SettleCreditNote
{
    public function __construct(private readonly ClosedPeriodGuard $periods) {}

    public function refund(Invoice $creditNote, mixed $amount, PaymentMethod $method, ?string $reference, User $actor): CreditNoteSettlement
    {
        $value = $this->amount($amount);
        $reference = $reference === null ? null : trim($reference);

        if (! $method->is_active) {
            throw CreditNoteException::invalidRefund("le mode « {$method->name} » est inactif.");
        }

        if ($method->requires_reference && ($reference === null || $reference === '')) {
            throw CreditNoteException::invalidRefund("le mode « {$method->name} » exige une référence (numéro de chèque, de virement).");
        }

        return $this->settle($creditNote, null, $actor, fn (Invoice $note): CreditNoteSettlement => CreditNoteSettlement::query()->create([
            'credit_note_id' => $note->id,
            'kind' => CreditNoteSettlement::REFUND,
            'amount' => $value,
            'payment_method_id' => $method->id,
            'reference' => $reference === null ? null : mb_substr($reference, 0, 64),
            'settled_on' => CarbonImmutable::now()->timezone((string) config('app.timezone'))->startOfDay(),
            'created_by' => $actor->id,
        ]), $value);
    }

    public function allocate(Invoice $creditNote, Invoice $target, mixed $amount, User $actor): CreditNoteSettlement
    {
        $value = $this->amount($amount);

        return $this->settle($creditNote, $target, $actor, function (Invoice $note, ?Invoice $invoice) use ($value, $actor): CreditNoteSettlement {
            if ($invoice === null || $invoice->type === InvoiceType::CreditNote) {
                throw CreditNoteException::invalidTarget('un avoir s\'impute sur une facture, pas sur un avoir.');
            }

            if ($invoice->customer_id !== $note->customer_id) {
                throw CreditNoteException::invalidTarget('la facture n\'est pas celle du même client.');
            }

            if (! in_array($invoice->status, [InvoiceStatus::Validated, InvoiceStatus::Issued, InvoiceStatus::Credited], true)) {
                throw CreditNoteException::invalidTarget("une facture au statut « {$invoice->status->label()} » ne reçoit pas d'imputation.");
            }

            $remaining = $invoice->total_due_amount->minus(self::allocatedTo($invoice));

            if ($value->isGreaterThan($remaining)) {
                throw CreditNoteException::invalidTarget("il reste {$remaining->format()} dû sur la facture {$invoice->number}.");
            }

            return CreditNoteSettlement::query()->create([
                'credit_note_id' => $note->id,
                'kind' => CreditNoteSettlement::ALLOCATION,
                'amount' => $value,
                'invoice_id' => $invoice->id,
                'settled_on' => CarbonImmutable::now()->timezone((string) config('app.timezone'))->startOfDay(),
                'created_by' => $actor->id,
            ]);
        }, $value);
    }

    /** Reste à rembourser ou imputer d'un avoir. */
    public static function remaining(Invoice $creditNote): Money
    {
        $settled = CreditNoteSettlement::query()->where('credit_note_id', $creditNote->id)->get()
            ->reduce(fn (Money $sum, CreditNoteSettlement $settlement): Money => $sum->plus($settlement->amount), Money::zero());

        return $creditNote->total_due_amount->minus($settled);
    }

    /** Total des avoirs imputés sur une facture. */
    public static function allocatedTo(Invoice $invoice): Money
    {
        return CreditNoteSettlement::query()->where('invoice_id', $invoice->id)->where('kind', CreditNoteSettlement::ALLOCATION)->get()
            ->reduce(fn (Money $sum, CreditNoteSettlement $settlement): Money => $sum->plus($settlement->amount), Money::zero());
    }

    /**
     * @param  callable(Invoice, Invoice|null): CreditNoteSettlement  $write
     */
    private function settle(Invoice $creditNote, ?Invoice $target, User $actor, callable $write, Money $amount): CreditNoteSettlement
    {
        if (! $actor->can('settle', $creditNote)) {
            throw CreditNoteException::notAllowed();
        }

        return DB::transaction(function () use ($creditNote, $target, $write, $amount): CreditNoteSettlement {
            // Verrous dans l'ordre des identifiants : jamais d'interblocage entre deux règlements croisés.
            $ids = array_values(array_unique(array_filter([$creditNote->id, $target?->id])));
            sort($ids);
            $locked = Invoice::query()->withoutGlobalScopes()->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $note = $locked->get($creditNote->id);

            if (! $note instanceof Invoice || $note->type !== InvoiceType::CreditNote || ! in_array($note->status, [InvoiceStatus::Validated, InvoiceStatus::Issued], true)) {
                throw CreditNoteException::notACreditNote();
            }

            $this->periods->assertOpen(CarbonImmutable::now(), 'le règlement de l\'avoir');

            $remaining = self::remaining($note);

            if ($amount->isGreaterThan($remaining)) {
                throw CreditNoteException::settlementExceeded($remaining->format());
            }

            return $write($note, $target === null ? null : $locked->get($target->id));
        });
    }

    private function amount(mixed $value): Money
    {
        try {
            $amount = AmountInput::parse($value);
        } catch (InvalidArgumentException $exception) {
            throw CreditNoteException::invalidRefund($exception->getMessage());
        }

        if ($amount === null || ! $amount->isPositive()) {
            throw CreditNoteException::invalidRefund('le montant doit être supérieur à zéro.');
        }

        return $amount;
    }
}
