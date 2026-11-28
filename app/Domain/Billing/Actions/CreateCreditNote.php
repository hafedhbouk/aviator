<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\ClosedPeriodGuard;
use App\Domain\Billing\Exceptions\CreditNoteException;
use App\Domain\Billing\InvoiceTaxWriter;
use App\Domain\Tax\TaxBreakdown;
use App\Domain\Tax\TaxCalculator;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceTax;
use App\Models\SequenceSeries;
use App\Models\TaxRule;
use App\Models\User;
use App\Support\Money\AmountInput;
use App\Support\Money\Money;
use App\Support\Sequences\SequenceAllocator;
use App\Support\Sequences\Series;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Avoir total ou partiel (E-04, RG-06, D27-4, N14, référentiel §6) d'une facture ou
 * d'une facture complémentaire **émise** (une facture validée jamais émise s'annule,
 * Q79). Lié à sa facture d'origine, série `AV-`, numéroté et validé dans la même
 * transaction ; motif obligatoire.
 *
 * - Sous verrou de la facture d'origine : chaque ligne n'est jamais créditée au-delà de
 *   son montant HT, et le total des avoirs ne dépasse jamais celui de la facture ; deux
 *   avoirs simultanés qui le dépasseraient ensemble : un seul passe.
 * - Taxes aux taux figés de la facture d'origine (copie de ses règles), arrondies par
 *   TaxCalculator. L'avoir qui solde la facture reprend le reste exact de la TVA de
 *   chaque taux et le timbre de la facture (Q97) : la somme des avoirs égale alors la
 *   facture au millime, et la facture passe au statut « avoir total ».
 * - Client et émetteur repris de la facture d'origine (§6 : client, agence).
 * - Montants positifs ; le type « avoir » porte le sens (exports, phase 8).
 */
final class CreateCreditNote
{
    public const MIN_REASON_LENGTH = 10;

    public function __construct(
        private readonly SequenceAllocator $sequences,
        private readonly TaxCalculator $calculator,
        private readonly InvoiceTaxWriter $taxes,
        private readonly ClosedPeriodGuard $periods,
    ) {}

    /**
     * @param  array<int|string, mixed>|null  $amounts  ligne de la facture d'origine => montant HT à créditer ; null = avoir total (tout le reste)
     */
    public function handle(Invoice $invoice, ?array $amounts, string $reason, User $actor): Invoice
    {
        if (! $actor->can('creditNote', $invoice)) {
            throw CreditNoteException::notAllowed();
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            throw CreditNoteException::reasonRequired(self::MIN_REASON_LENGTH);
        }

        $at = CarbonImmutable::now();
        $this->sequences->prepareSeries(Series::CreditNote, $at);

        return DB::transaction(function () use ($invoice, $amounts, $reason, $actor, $at): Invoice {
            $original = Invoice::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($invoice->id);
            $this->assertCreditable($original);
            $this->periods->assertOpen($at, 'la création de l\'avoir');

            $lines = InvoiceLine::query()->with('taxCode')->where('invoice_id', $original->id)->orderBy('position')->get();
            $previous = Invoice::query()->withoutGlobalScopes()->where('credited_invoice_id', $original->id)
                ->where('type', InvoiceType::CreditNote)->whereNotNull('number')->get();
            $credited = $this->creditedByLine($previous);
            $requested = $this->requested($lines, $credited, $amounts);
            $complete = $lines->every(fn (InvoiceLine $line): bool => $line->total_excl_tax_amount
                ->minus($credited[$line->id] ?? Money::zero())->minus($requested[$line->id] ?? Money::zero())->isZero());

            [$bases, $rules, $snapshots] = $this->taxBasis($original, $lines, $requested);
            $breakdown = $complete
                ? $this->closingBreakdown($original, $previous, $bases, $rules)
                : $this->calculator->fromBases(InvoiceTaxWriter::groups($bases, $rules), Money::zero());

            $alreadyTotal = $previous->reduce(fn (Money $sum, Invoice $note): Money => $sum->plus($note->total_due_amount), Money::zero());

            if ($alreadyTotal->plus($breakdown->netToPay)->isGreaterThan($original->total_due_amount)) {
                throw CreditNoteException::capExceeded($original->total_due_amount->minus($alreadyTotal)->format());
            }

            $note = Invoice::query()->create([
                'agency_id' => $original->agency_id,
                'type' => InvoiceType::CreditNote,
                'contract_id' => $original->contract_id,
                'customer_id' => $original->customer_id,
                'credited_invoice_id' => $original->id,
                'credit_reason' => mb_substr($reason, 0, 500),
                'created_by' => $actor->id,
            ]);

            $position = 0;

            foreach ($lines as $line) {
                $amount = $requested[$line->id] ?? null;

                if ($amount === null) {
                    continue;
                }

                InvoiceLine::query()->create([
                    'invoice_id' => $note->id,
                    'position' => ++$position,
                    'nature' => $line->nature,
                    'source_type' => InvoiceLine::SOURCE_INVOICE_LINE,
                    'source_id' => $line->id,
                    'tax_code_id' => $line->tax_code_id,
                    'label' => $line->label,
                    'quantity' => '1.000',
                    'unit_amount_excl_tax' => $amount,
                    'discount_amount' => Money::zero(),
                    'total_excl_tax_amount' => $amount,
                ]);
            }

            $this->taxes->write($note, $bases, $rules, $breakdown, $snapshots);

            $number = $this->sequences->nextInSeries(Series::CreditNote, $at);
            $key = SequenceSeries::query()->where('code', Series::CreditNote->value)->lockForUpdate()->firstOrFail()
                ->format()->counterKey(Series::CreditNote, $at);

            $note->fill([
                'status' => InvoiceStatus::Validated,
                'number' => $number,
                'series' => Series::CreditNote,
                'sequence_key' => $key,
                'fiscal_year' => $at->timezone((string) config('app.timezone'))->year,
                'validated_at' => $at,
                'validated_by' => $actor->id,
                'customer_snapshot' => $original->customer_snapshot,
                'issuer_snapshot' => $original->issuer_snapshot,
                'total_excl_tax_amount' => $breakdown->totalExcludingTax,
                'total_tax_amount' => $breakdown->totalVat,
                'total_incl_tax_amount' => $breakdown->totalIncludingTax,
                'stamp_duty_amount' => $breakdown->stampDuty,
                'total_due_amount' => $breakdown->netToPay,
                'stamp_duty_rule_id' => $breakdown->stampDuty->isPositive() ? $original->stamp_duty_rule_id : null,
            ])->save();

            if ($complete) {
                $original->fill(['status' => InvoiceStatus::Credited])->save();
            }

            return $note->refresh();
        });
    }

    private function assertCreditable(Invoice $original): void
    {
        if ($original->type === InvoiceType::CreditNote) {
            throw CreditNoteException::notOnCreditNote();
        }

        match ($original->status) {
            InvoiceStatus::Issued => null,
            InvoiceStatus::Credited => throw CreditNoteException::fullyCredited(),
            InvoiceStatus::Validated => throw CreditNoteException::notIssued(),
            default => throw CreditNoteException::invalidStatus($original->status->label()),
        };
    }

    /**
     * Montant HT déjà crédité, par ligne de la facture d'origine.
     *
     * @param  Collection<int, Invoice>  $previous
     * @return array<int, Money>
     */
    private function creditedByLine(Collection $previous): array
    {
        $credited = [];

        if ($previous->isEmpty()) {
            return $credited;
        }

        foreach (InvoiceLine::query()->whereIn('invoice_id', $previous->modelKeys())->where('source_type', InvoiceLine::SOURCE_INVOICE_LINE)->get() as $line) {
            $id = (int) $line->source_id;
            $credited[$id] = ($credited[$id] ?? Money::zero())->plus($line->total_excl_tax_amount);
        }

        return $credited;
    }

    /**
     * Montant HT à créditer par ligne : tout le reste (avoir total) ou la saisie, jamais
     * au-delà du reste de chaque ligne.
     *
     * @param  Collection<int, InvoiceLine>  $lines
     * @param  array<int, Money>  $credited
     * @param  array<int|string, mixed>|null  $amounts
     * @return array<int, Money>
     */
    private function requested(Collection $lines, array $credited, ?array $amounts): array
    {
        $requested = [];
        $byId = $lines->keyBy('id');

        if ($amounts === null) {
            foreach ($lines as $line) {
                $remaining = $line->total_excl_tax_amount->minus($credited[$line->id] ?? Money::zero());

                if ($remaining->isPositive()) {
                    $requested[$line->id] = $remaining;
                }
            }
        } else {
            foreach ($amounts as $lineId => $value) {
                $line = $byId->get((int) $lineId);

                if (! $line instanceof InvoiceLine) {
                    throw CreditNoteException::unknownLine();
                }

                try {
                    $amount = AmountInput::parse($value);
                } catch (InvalidArgumentException $exception) {
                    throw CreditNoteException::invalidAmount($line->label, $exception->getMessage());
                }

                if ($amount === null || $amount->isZero()) {
                    continue;
                }

                $remaining = $line->total_excl_tax_amount->minus($credited[$line->id] ?? Money::zero());

                if ($amount->isGreaterThan($remaining)) {
                    throw CreditNoteException::lineExceeded($line->label, $remaining->format());
                }

                $requested[$line->id] = $amount;
            }
        }

        if ($requested === []) {
            throw $amounts === null ? CreditNoteException::fullyCredited() : CreditNoteException::nothingToCredit();
        }

        return $requested;
    }

    /**
     * Bases par code, règles et copies figées reprises de la facture d'origine.
     *
     * @param  Collection<int, InvoiceLine>  $lines
     * @param  array<int, Money>  $requested
     * @return array{0: array<string, Money>, 1: array<string, TaxRule>, 2: array<string, array<string, mixed>>}
     */
    private function taxBasis(Invoice $original, Collection $lines, array $requested): array
    {
        $taxes = InvoiceTax::query()->where('invoice_id', $original->id)->get()->keyBy('code');
        $bases = [];
        $rules = [];
        $snapshots = [];

        foreach ($lines as $line) {
            if (! isset($requested[$line->id])) {
                continue;
            }

            $code = (string) $line->taxCode?->code;
            $tax = $taxes->get($code);

            if (! $tax instanceof InvoiceTax) {
                throw CreditNoteException::missingOriginalTax($code);
            }

            $bases[$code] = ($bases[$code] ?? Money::zero())->plus($requested[$line->id]);
            $rules[$code] ??= TaxRule::query()->with('taxCode')->findOrFail($tax->tax_rule_id);
            $snapshots[$code] = $tax->rule_snapshot;
        }

        return [$bases, $rules, $snapshots];
    }

    /**
     * Avoir qui solde la facture : TVA de chaque taux = TVA de la facture moins celle
     * déjà créditée ; timbre de la facture moins celui déjà crédité (Q97).
     *
     * @param  Collection<int, Invoice>  $previous
     * @param  array<string, Money>  $bases
     * @param  array<string, TaxRule>  $rules
     */
    private function closingBreakdown(Invoice $original, Collection $previous, array $bases, array $rules): TaxBreakdown
    {
        $vatByRate = fn (int $invoiceId): array => InvoiceTax::query()->where('invoice_id', $invoiceId)->get()
            ->groupBy(fn (InvoiceTax $tax): string => $tax->rate === null ? 'none' : rtrim(rtrim($tax->rate, '0'), '.'))
            ->map(fn ($taxes): Money => $taxes->reduce(fn (Money $sum, InvoiceTax $tax): Money => $sum->plus($tax->tax_amount), Money::zero()))
            ->all();

        $remainingVat = $vatByRate($original->id);
        $stamp = $original->stamp_duty_amount;

        foreach ($previous as $note) {
            foreach ($vatByRate($note->id) as $rate => $vat) {
                $remainingVat[$rate] = ($remainingVat[$rate] ?? Money::zero())->minus($vat);
            }

            $stamp = $stamp->minus($note->stamp_duty_amount);
        }

        $groups = [];
        $totalHt = Money::zero();
        $totalVat = Money::zero();

        foreach (InvoiceTaxWriter::groups($bases, $rules) as $group) {
            $vat = $group['rate'] === null ? Money::zero() : ($remainingVat[$group['rate']] ?? Money::zero());
            $groups[] = $group + ['vat' => $vat];
            $totalHt = $totalHt->plus($group['base']);
            $totalVat = $totalVat->plus($vat);
        }

        return new TaxBreakdown(
            bases: $groups,
            totalExcludingTax: $totalHt,
            totalVat: $totalVat,
            totalIncludingTax: $totalHt->plus($totalVat),
            stampDuty: $stamp,
            netToPay: $totalHt->plus($totalVat)->plus($stamp),
        );
    }
}
