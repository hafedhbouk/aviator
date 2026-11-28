<?php

declare(strict_types=1);

namespace App\Domain\Billing\Deposits;

use App\Domain\Approvals\Actions\RequestApproval;
use App\Domain\Billing\Cash\CashRegister;
use App\Domain\Billing\ClosedPeriodGuard;
use App\Domain\Billing\Exceptions\DepositException;
use App\Domain\Billing\InvoiceBalance;
use App\Domain\Billing\Payments\PaymentAllocator;
use App\Domain\Billing\Payments\RecordPayment;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Enums\ContractStatus;
use App\Models\ApprovalRequest;
use App\Models\Contract;
use App\Models\Deposit;
use App\Models\DepositMovement;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\SequenceSeries;
use App\Models\User;
use App\Support\Files\PrivateFiles;
use App\Support\Money\AmountInput;
use App\Support\Money\Money;
use App\Support\Sequences\SequenceAllocator;
use App\Support\Sequences\Series;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Cautions (module F, RG-08, Q51, Q52, Q54, Q56, D46, D58, E23) :
 *
 * - réception au départ (receive()) : une par contrat, montant prévu repris du contrat,
 *   écart motivé ; mode, référence (autorisation pour une empreinte, **jamais** de donnée
 *   de carte), banque ; reçu `CAU-AAAA-NNNNN` ; espèces dans la caisse ouverte ;
 * - restitution totale ou partielle au retour (refund()), reçu numéroté à chaque fois ;
 * - retenue (requestRetention(), puis applyRetention()) : motif, justificatif, facture
 *   de frais du contrat ; **toute** retenue est soumise au super admin (D46, Q52) ; une
 *   fois approuvée, elle devient un encaissement « retenue sur caution » imputé sur la
 *   facture de frais (Q51), qui porte seule la qualification fiscale : la caution
 *   n'entre jamais en chiffre d'affaires (F-07) ;
 * - sous verrou de la caution : restitué + retenu ≤ reçu (RG-08), une retenue en attente
 *   reste réservée ; cumuls tenus sur la ligne verrouillée (voir InvoiceBalance).
 */
final class DepositDesk
{
    public const MIN_REASON_LENGTH = 10;

    public function __construct(
        private readonly SequenceAllocator $sequences,
        private readonly CashRegister $cash,
        private readonly ClosedPeriodGuard $periods,
        private readonly RequestApproval $approvals,
        private readonly PaymentAllocator $allocator,
    ) {}

    /**
     * @param  array<string, mixed>  $data  amount, payment_method_id, reference, bank_name, difference_reason
     */
    public function receive(Contract $contract, array $data, User $actor): Deposit
    {
        if (! $actor->can('create', [Deposit::class, $contract])) {
            throw DepositException::notAllowed();
        }

        $amount = $this->amount($data['amount'] ?? null);
        [$method, $reference, $bank] = $this->method($data);
        $at = CarbonImmutable::now();
        $this->sequences->prepareSeries(Series::DepositReceipt, $at);

        return DB::transaction(function () use ($contract, $data, $actor, $amount, $method, $reference, $bank, $at): Deposit {
            $locked = Contract::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($contract->id);

            if ($locked->status !== ContractStatus::Draft && $locked->status !== ContractStatus::Active) {
                throw DepositException::contractStatus($locked->status->label());
            }

            if (Deposit::query()->withoutGlobalScopes()->where('contract_id', $locked->id)->exists()) {
                throw DepositException::alreadyRecorded();
            }

            $expected = $locked->deposit_expected_amount;
            $reason = trim((string) ($data['difference_reason'] ?? ''));

            if ($expected !== null && ! $expected->equals($amount) && mb_strlen($reason) < self::MIN_REASON_LENGTH) {
                throw DepositException::differenceReasonRequired($expected->format(), self::MIN_REASON_LENGTH);
            }

            $session = $method->code === CashRegister::CASH_METHOD ? $this->cash->openFor($locked->agency_id) : null;
            [$number, $key] = $this->receiptNumber($at);

            return Deposit::query()->create([
                'agency_id' => $locked->agency_id,
                'contract_id' => $locked->id,
                'customer_id' => $locked->customer_id,
                'expected_amount' => $expected,
                'amount_received' => $amount,
                'difference_reason' => $expected !== null && ! $expected->equals($amount) ? mb_substr($reason, 0, 500) : null,
                'payment_method_id' => $method->id,
                'reference' => $reference,
                'bank_name' => $bank,
                'received_at' => $at,
                'cash_session_id' => $session?->id,
                'receipt_number' => $number,
                'receipt_sequence_key' => $key,
                'created_by' => $actor->id,
            ]);
        });
    }

    /**
     * Restitution totale (montant null : tout le reste) ou partielle, au retour.
     *
     * @param  array<string, mixed>  $data  payment_method_id, reference, bank_name
     */
    public function refund(Deposit $deposit, mixed $amount, array $data, User $actor): DepositMovement
    {
        if (! $actor->can('refund', $deposit)) {
            throw DepositException::notAllowed();
        }

        [$method, $reference] = $this->method($data);
        $at = CarbonImmutable::now();
        $this->sequences->prepareSeries(Series::DepositReceipt, $at);

        return DB::transaction(function () use ($deposit, $amount, $method, $reference, $actor, $at): DepositMovement {
            $locked = Deposit::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($deposit->id);
            $contractStatus = Contract::query()->withoutGlobalScopes()->whereKey($locked->contract_id)->value('status');

            if ($contractStatus !== ContractStatus::Returned && $contractStatus !== ContractStatus::Closed) {
                throw DepositException::notReturned();
            }

            $available = $this->available($locked);
            $value = $amount === null ? $available : $this->amount($amount);

            if (! $value->isPositive() || $value->isGreaterThan($available)) {
                $pending = $this->pendingRetention($locked);

                throw $pending->isPositive() && ! $value->isGreaterThan($locked->remaining())
                    ? DepositException::pendingRetention($pending->format())
                    : DepositException::exceeded($available->format());
            }

            $session = $method->code === CashRegister::CASH_METHOD ? $this->cash->openFor($locked->agency_id) : null;
            [$number, $key] = $this->receiptNumber($at);

            $movement = DepositMovement::query()->create([
                'deposit_id' => $locked->id,
                'type' => DepositMovement::REFUND,
                'amount' => $value,
                'payment_method_id' => $method->id,
                'reference' => $reference,
                'cash_session_id' => $session?->id,
                'receipt_number' => $number,
                'receipt_sequence_key' => $key,
                'created_by' => $actor->id,
            ]);

            $locked->forceFill(['refunded_amount' => $locked->refunded_amount->plus($value)])->save();

            return $movement;
        });
    }

    /**
     * Demande de retenue au super admin (F-04, F-05, D46) : motif, justificatif, facture
     * de frais du même contrat, validée, avec un reste dû suffisant.
     *
     * @param  array<string, mixed>  $data  amount, reason, invoice_id, file (fichier déposé)
     */
    public function requestRetention(Deposit $deposit, array $data, User $actor): ApprovalRequest
    {
        if (! $actor->can('retain', $deposit)) {
            throw DepositException::notAllowed();
        }

        $amount = $this->amount($data['amount'] ?? null);
        $reason = trim((string) ($data['reason'] ?? ''));

        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            throw DepositException::reasonRequired(self::MIN_REASON_LENGTH);
        }

        if (blank($data['file'] ?? null)) {
            throw DepositException::justificationRequired();
        }

        return DB::transaction(function () use ($deposit, $data, $actor, $amount, $reason): ApprovalRequest {
            $locked = Deposit::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($deposit->id);

            // Une nouvelle demande remplace celle en attente (RequestApproval) : seul le reste compte.
            if ($amount->isGreaterThan($locked->remaining())) {
                throw DepositException::exceeded($locked->remaining()->format());
            }

            $invoice = $this->feeInvoice($locked, (int) ($data['invoice_id'] ?? 0), $amount);
            $contractNumber = (string) Contract::query()->withoutGlobalScopes()->whereKey($locked->contract_id)->value('number');

            try {
                $path = PrivateFiles::attach((string) $data['file'], "deposits/{$locked->id}/retenues");
            } catch (InvalidArgumentException $exception) {
                throw DepositException::invalidAmount($exception->getMessage());
            }

            return $this->approvals->handle(ApprovalType::Retention, $locked, $locked->agency_id, [
                'document_number' => $contractNumber,
                'deposit_receipt' => $locked->receipt_number,
                'deposit_amount' => $locked->amount_received->toDecimalString(),
                'remaining_amount' => $locked->remaining()->toDecimalString(),
                'retention_amount' => $amount->toDecimalString(),
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->number,
                'file_path' => $path,
                'file_sha256' => hash('sha256', (string) Storage::disk(PrivateFiles::DISK)->get($path)),
            ], $reason, $actor);
        });
    }

    /**
     * Application d'une retenue **approuvée** : encaissement « retenue sur caution »
     * imputé sur la facture de frais (Q51), mouvement de retenue, cumul retenu.
     */
    public function applyRetention(ApprovalRequest $request, User $actor): DepositMovement
    {
        if ($request->type !== ApprovalType::Retention || $request->subject_type !== (new Deposit)->getMorphClass()) {
            throw DepositException::notApproved();
        }

        $deposit = Deposit::query()->withoutGlobalScopes()->findOrFail($request->subject_id);

        if (! $actor->can('retain', $deposit)) {
            throw DepositException::notAllowed();
        }

        return DB::transaction(function () use ($request, $deposit, $actor): DepositMovement {
            $locked = Deposit::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($deposit->id);
            $decision = ApprovalRequest::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($request->id);

            if ($decision->status !== ApprovalStatus::Approved) {
                throw DepositException::notApproved();
            }

            if (DepositMovement::query()->where('approval_request_id', $decision->id)->exists()) {
                throw DepositException::alreadyApplied();
            }

            $amount = Money::of((string) $decision->payload['retention_amount']);

            if ($amount->isGreaterThan($locked->remaining())) {
                throw DepositException::exceeded($locked->remaining()->format());
            }

            $at = CarbonImmutable::now();
            $this->periods->assertOpen($at, 'la retenue sur caution');
            $invoice = $this->feeInvoice($locked, (int) $decision->payload['invoice_id'], $amount);

            $payment = Payment::query()->create([
                'agency_id' => $locked->agency_id,
                'customer_id' => $locked->customer_id,
                'payment_method_id' => PaymentMethod::depositRetention()->id,
                'reference' => $locked->receipt_number,
                'amount' => $amount,
                'received_at' => $at,
                'created_by' => $actor->id,
            ]);
            $this->allocator->allocate($payment, [$invoice->id => $amount], $actor);

            $movement = DepositMovement::query()->create([
                'deposit_id' => $locked->id,
                'type' => DepositMovement::RETENTION,
                'amount' => $amount,
                'reason' => mb_substr($decision->reason, 0, 1000),
                'file_path' => $decision->payload['file_path'] ?? null,
                'file_sha256' => $decision->payload['file_sha256'] ?? null,
                'invoice_id' => $invoice->id,
                'approval_request_id' => $decision->id,
                'payment_id' => $payment->id,
                'created_by' => $actor->id,
            ]);

            $locked->forceFill(['retained_amount' => $locked->retained_amount->plus($amount)])->save();

            return $movement;
        });
    }

    /** Montant d'une retenue en attente de décision, réservé sur la caution. */
    public function pendingRetention(Deposit $deposit): Money
    {
        return ApprovalRequest::query()->withoutGlobalScopes()
            ->where('type', ApprovalType::Retention)
            ->where('subject_type', (new Deposit)->getMorphClass())
            ->where('subject_id', $deposit->id)
            ->where('status', ApprovalStatus::Pending)
            ->get()
            ->reduce(fn (Money $sum, ApprovalRequest $request): Money => $sum->plus(Money::of((string) ($request->payload['retention_amount'] ?? '0'))), Money::zero());
    }

    /** Reste disponible pour une restitution : reste moins la retenue en attente. */
    public function available(Deposit $deposit): Money
    {
        $available = $deposit->remaining()->minus($this->pendingRetention($deposit));

        return $available->isNegative() ? Money::zero() : $available;
    }

    private function feeInvoice(Deposit $deposit, int $invoiceId, Money $amount): Invoice
    {
        $invoice = Invoice::query()->withoutGlobalScopes()->lockForUpdate()->find($invoiceId);

        if ($invoice === null || $invoice->contract_id !== $deposit->contract_id) {
            throw DepositException::invalidInvoice('elle doit être une facture du même contrat.');
        }

        if (! InvoiceBalance::canReceive($invoice)) {
            throw DepositException::invalidInvoice('elle doit être validée (la retenue la règle).');
        }

        $remaining = InvoiceBalance::remaining($invoice);

        if ($amount->isGreaterThan($remaining)) {
            throw DepositException::invalidInvoice("il reste {$remaining->format()} dû sur la facture {$invoice->number}.");
        }

        return $invoice;
    }

    /**
     * @return array{0: string, 1: string} numéro du reçu et clé du compteur
     */
    private function receiptNumber(CarbonImmutable $at): array
    {
        $number = $this->sequences->nextInSeries(Series::DepositReceipt, $at);
        $key = SequenceSeries::query()->where('code', Series::DepositReceipt->value)->lockForUpdate()->firstOrFail()
            ->format()->counterKey(Series::DepositReceipt, $at);

        return [$number, $key];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: PaymentMethod, 1: string|null, 2: string|null}
     */
    private function method(array $data): array
    {
        $method = PaymentMethod::query()->find($data['payment_method_id'] ?? null);

        if ($method === null || ! $method->is_active) {
            throw DepositException::invalidMethod('choisissez un mode actif.');
        }

        $reference = blank($data['reference'] ?? null) ? null : mb_substr(trim((string) $data['reference']), 0, 64);
        $bank = blank($data['bank_name'] ?? null) ? null : mb_substr(trim((string) $data['bank_name']), 0, 120);

        // Aucune donnée de carte (Q54) : une suite de 12 chiffres ou plus ressemble à un numéro de carte.
        if ($reference !== null && preg_match('/\d{12,}/', (string) preg_replace('/[\s.\-]/', '', $reference)) === 1) {
            throw DepositException::cardData();
        }

        if ($method->requires_reference && $reference === null) {
            throw DepositException::invalidMethod("le mode « {$method->name} » exige une référence (numéro de chèque, d'autorisation de l'empreinte, de virement).");
        }

        if (in_array($method->code, RecordPayment::BANK_METHODS, true) && $bank === null) {
            throw DepositException::invalidMethod("le mode « {$method->name} » exige le nom de la banque.");
        }

        return [$method, $reference, $bank];
    }

    private function amount(mixed $value): Money
    {
        try {
            $amount = AmountInput::parse($value);
        } catch (InvalidArgumentException $exception) {
            throw DepositException::invalidAmount($exception->getMessage());
        }

        if ($amount === null || ! $amount->isPositive()) {
            throw DepositException::invalidAmount('le montant doit être supérieur à zéro.');
        }

        return $amount;
    }
}
