<?php

declare(strict_types=1);

namespace App\Domain\Billing\Payments;

use App\Domain\Billing\ClosedPeriodGuard;
use App\Domain\Billing\Exceptions\PaymentException;
use App\Enums\ReservationStatus;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Money\AmountInput;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Encaissement (E-06, E17, D37, D57) : espèces, chèque, carte, virement ou traite, en
 * dinars ; référence si le mode l'exige, banque pour le chèque et le virement (E17).
 * Imputé aussitôt sur les factures confirmées par l'agent (PaymentAllocator), dans la
 * même transaction ; la part non imputée reste un acompte. Un acompte de réservation
 * (D37, Q40) se rattache à la réservation, sans document fiscal, puis s'impute sur la
 * facture du contrat. Le rattachement des espèces à la caisse ouverte arrive au lot L8.
 */
final class RecordPayment
{
    /** Modes pour lesquels la banque est exigée (E17). */
    public const BANK_METHODS = ['cheque', 'transfer'];

    public function __construct(
        private readonly PaymentAllocator $allocator,
        private readonly ClosedPeriodGuard $periods,
    ) {}

    /**
     * @param  array<string, mixed>  $data  agency_id, customer_id, payment_method_id, amount, reference, bank_name, received_at, reservation_id, allocations (facture => montant)
     */
    public function handle(array $data, User $actor): Payment
    {
        $values = $this->validated($data);

        if (! $actor->can('create', [Payment::class, $values['agency_id']])) {
            throw PaymentException::notAllowed();
        }

        $this->periods->assertOpen($values['received_at'], 'l\'encaissement');

        return DB::transaction(function () use ($values, $actor): Payment {
            $payment = Payment::query()->create([...$values, 'created_by' => $actor->id]);

            $this->allocator->allocate($payment, $values['allocations'], $actor);

            return $payment->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{agency_id: int, customer_id: int, payment_method_id: int, amount: Money, reference: string|null, bank_name: string|null, received_at: CarbonImmutable, reservation_id: int|null, currency: string, allocations: array<int|string, mixed>}
     */
    private function validated(array $data): array
    {
        $validated = Validator::make($data, [
            'agency_id' => ['required', 'integer', 'exists:agencies,id'],
            'customer_id' => ['required', 'integer'],
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'amount' => ['required'],
            'reference' => ['nullable', 'string', 'max:64'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'received_at' => ['nullable', 'date'],
            'reservation_id' => ['nullable', 'integer'],
            'currency' => ['nullable', 'string'],
            'allocations' => ['nullable', 'array'],
        ], [], [
            'agency_id' => 'agence', 'customer_id' => 'client', 'payment_method_id' => 'mode', 'amount' => 'montant',
            'reference' => 'référence', 'bank_name' => 'banque', 'received_at' => 'date de réception',
        ])->validate();

        try {
            $amount = AmountInput::parse($validated['amount']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['amount' => "Le champ montant : {$exception->getMessage()}"]);
        }

        if ($amount === null || ! $amount->isPositive()) {
            throw ValidationException::withMessages(['amount' => 'Le montant doit être supérieur à zéro.']);
        }

        $customer = Customer::query()->withoutGlobalScopes()->findOrFail((int) $validated['customer_id']);
        $method = PaymentMethod::query()->findOrFail((int) $validated['payment_method_id']);
        $reference = blank($validated['reference'] ?? null) ? null : trim((string) $validated['reference']);
        $bank = blank($validated['bank_name'] ?? null) ? null : trim((string) $validated['bank_name']);

        if (! $method->is_active) {
            throw PaymentException::inactiveMethod($method->name);
        }

        if ($method->requires_reference && $reference === null) {
            throw PaymentException::referenceRequired($method->name);
        }

        if (in_array($method->code, self::BANK_METHODS, true) && $bank === null) {
            throw PaymentException::bankRequired($method->name);
        }

        $timezone = (string) config('app.timezone');
        $receivedAt = blank($validated['received_at'] ?? null) ? CarbonImmutable::now() : CarbonImmutable::parse((string) $validated['received_at'], $timezone);

        if ($receivedAt->isFuture()) {
            throw ValidationException::withMessages(['received_at' => 'La date de réception ne peut pas être dans le futur.']);
        }

        $reservationId = blank($validated['reservation_id'] ?? null) ? null : (int) $validated['reservation_id'];

        if ($reservationId !== null) {
            $reservation = Reservation::query()->withoutGlobalScopes()->find($reservationId);

            if ($reservation === null || $reservation->customer_id !== $customer->id) {
                throw PaymentException::invalidReservation('la réservation n\'est pas celle de ce client.');
            }

            if (! in_array($reservation->status, [ReservationStatus::Pending, ReservationStatus::Confirmed, ReservationStatus::Converted], true)) {
                throw PaymentException::invalidReservation("une réservation au statut « {$reservation->status->label()} » ne reçoit pas d'acompte.");
            }
        }

        return [
            'agency_id' => (int) $validated['agency_id'],
            'customer_id' => $customer->id,
            'payment_method_id' => $method->id,
            'amount' => $amount,
            'reference' => $reference,
            'bank_name' => $bank,
            'received_at' => $receivedAt,
            'reservation_id' => $reservationId,
            'currency' => blank($validated['currency'] ?? null) ? Money::CURRENCY : strtoupper(trim((string) $validated['currency'])),
            'allocations' => $validated['allocations'] ?? [],
        ];
    }
}
