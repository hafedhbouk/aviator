<?php

declare(strict_types=1);

namespace App\Domain\Billing\Payments;

use App\Domain\Billing\Exceptions\PaymentException;
use App\Domain\Billing\InvoiceBalance;
use App\Enums\ReminderChannel;
use App\Models\Invoice;
use App\Models\PaymentReminder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Relance manuelle d'une facture impayée (E-08, Q53) : appel, courrier ou visite, date
 * (jamais dans le futur), issue. Seulement une facture qui a encore un reste dû, par un
 * profil de son agence. L'historique (history()) se lit du plus récent au plus ancien.
 */
final class RecordReminder
{
    /**
     * @param  array<string, mixed>  $data  channel, reminded_at (AAAA-MM-JJ HH:MM, heure de Tunis), outcome
     */
    public function handle(Invoice $invoice, array $data, User $actor): PaymentReminder
    {
        if (! $actor->can('remind', $invoice)) {
            throw PaymentException::notAllowed();
        }

        if (! InvoiceBalance::canReceive($invoice) || ! InvoiceBalance::remaining($invoice)->isPositive()) {
            throw PaymentException::nothingToRemind();
        }

        $validated = Validator::make($data, [
            'channel' => ['required', Rule::enum(ReminderChannel::class)],
            'reminded_at' => ['nullable', 'date'],
            'outcome' => ['nullable', 'string', 'max:2000'],
        ], [], ['channel' => 'canal', 'reminded_at' => 'date de la relance', 'outcome' => 'issue'])->validate();

        $at = blank($validated['reminded_at'] ?? null)
            ? CarbonImmutable::now()
            : CarbonImmutable::parse((string) $validated['reminded_at'], (string) config('app.timezone'));

        if ($at->isFuture()) {
            throw ValidationException::withMessages(['reminded_at' => 'La date de la relance ne peut pas être dans le futur.']);
        }

        return PaymentReminder::query()->create([
            'invoice_id' => $invoice->id,
            'channel' => ReminderChannel::from((string) $validated['channel']),
            'reminded_at' => $at,
            'outcome' => blank($validated['outcome'] ?? null) ? null : trim((string) $validated['outcome']),
            'created_by' => $actor->id,
        ]);
    }

    /**
     * @return Collection<int, PaymentReminder>
     */
    public function history(Invoice $invoice): Collection
    {
        return PaymentReminder::query()->with('author')->where('invoice_id', $invoice->id)
            ->orderByDesc('reminded_at')->orderByDesc('id')->get();
    }
}
