<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\ClosedPeriodGuard;
use App\Domain\Billing\Exceptions\InvoiceValidationException;
use App\Domain\Billing\InvoiceSnapshots;
use App\Domain\Billing\InvoiceTaxWriter;
use App\Domain\Tax\Exceptions\TaxRuleUnavailable;
use App\Domain\Tax\FiscalGuard;
use App\Domain\Tax\TaxCalculator;
use App\Domain\Tax\TaxLine;
use App\Domain\Tax\TaxRuleResolver;
use App\Enums\FiscalValidationStatus;
use App\Enums\InvoiceStatus;
use App\Enums\TaxNature;
use App\Models\Agency;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\SequenceSeries;
use App\Models\StampDutyRule;
use App\Models\TaxRule;
use App\Models\User;
use App\Settings\BillingSettings;
use App\Support\Files\PrivateFiles;
use App\Support\Money\Money;
use App\Support\Sequences\SequenceAllocator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Validation d'une facture ou d'une facture complémentaire (E-03, RG-06, RG-07, N2, N3,
 * N5, E6, D53, D55, référentiel §3, §5) :
 *
 * 1. contrôles, **avant** le numéro : brouillon ; période ouverte (ClosedPeriodGuard) ;
 *    en production, garde-fou fiscal sans problème (E6) ; un code fiscal choisi sur
 *    chaque ligne ; une règle applicable à la date (jamais PENDING_DOCUMENTATION) ; un
 *    justificatif rattaché quand le code l'exige (exonéré, hors champ) ou que la règle
 *    est CONDITIONAL ; exonération du timbre seulement à la main, par une règle
 *    d'exonération active et applicable, avec motif et justificatif ;
 * 2. calcul par TaxCalculator (bases agrégées par taux, TVA arrondie, timbre après le
 *    TTC) ; une taxe enregistrée par code avec la copie figée de sa règle, la TVA de
 *    chaque taux répartie entre ses codes sans changer son total ;
 * 3. numéro attribué dans la même transaction (RG-07) : un refus ou une erreur annule
 *    tout, aucun numéro n'est consommé ;
 * 4. client et émetteur figés (D59), échéance selon `billing.payment_terms_days`.
 */
final class ValidateInvoice
{
    public const MIN_EXEMPTION_REASON_LENGTH = 10;

    public function __construct(
        private readonly SequenceAllocator $sequences,
        private readonly TaxRuleResolver $rules,
        private readonly TaxCalculator $calculator,
        private readonly FiscalGuard $fiscalGuard,
        private readonly ClosedPeriodGuard $periods,
        private readonly BillingSettings $settings,
        private readonly InvoiceTaxWriter $taxes,
    ) {}

    /**
     * @param  array{rule_id?: int|string|null, reason?: string|null, file?: string|null}  $stampExemption  exonération manuelle du timbre (N3)
     */
    public function handle(Invoice $invoice, User $actor, array $stampExemption = []): Invoice
    {
        if (! $actor->can('validate', $invoice)) {
            throw InvoiceValidationException::notAllowed();
        }

        $at = CarbonImmutable::now();
        $series = $invoice->type->series();

        // Compteurs créés avant la transaction du document (SequenceAllocator::prepareSeries).
        $this->sequences->prepareSeries($series, $at);

        return DB::transaction(function () use ($invoice, $actor, $stampExemption, $at, $series): Invoice {
            $locked = Invoice::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($invoice->id);

            if ($locked->status !== InvoiceStatus::Draft) {
                throw InvoiceValidationException::invalidStatus($locked->status->label(), 'la validation');
            }

            $this->periods->assertOpen($at, 'la validation de la facture');
            $this->assertProductionReady();

            $lines = InvoiceLine::query()->with(['taxCode', 'justifications'])->where('invoice_id', $locked->id)->orderBy('position')->get();
            $rules = $this->rulesFor($lines, $at);
            $breakdown = $this->calculator->calculate(
                $lines->map(fn (InvoiceLine $line): TaxLine => new TaxLine((string) $line->taxCode?->code, $line->total_excl_tax_amount))->all(),
                $at,
            );
            $exemption = $this->stampExemption($stampExemption, $at);
            $company = Company::current() ?? throw InvoiceValidationException::companyMissing();
            $stamp = $exemption === null ? $breakdown->stampDuty : Money::zero();
            $exemptionFile = $exemption === null ? null : $this->exemptionFile($locked, (string) ($stampExemption['file'] ?? ''));

            $bases = [];

            foreach ($lines as $line) {
                $code = (string) $line->taxCode?->code;
                $bases[$code] = ($bases[$code] ?? Money::zero())->plus($line->total_excl_tax_amount);
            }

            $this->taxes->write($locked, $bases, $rules, $breakdown);

            $number = $this->sequences->nextInSeries($series, $at);
            $key = SequenceSeries::query()->where('code', $series->value)->lockForUpdate()->firstOrFail()->format()->counterKey($series, $at);
            $local = $at->timezone((string) config('app.timezone'));

            $locked->fill([
                'status' => InvoiceStatus::Validated,
                'number' => $number,
                'series' => $series,
                'sequence_key' => $key,
                'fiscal_year' => $local->year,
                'validated_at' => $at,
                'validated_by' => $actor->id,
                'customer_snapshot' => InvoiceSnapshots::customer(Customer::query()->withoutGlobalScopes()->findOrFail($locked->customer_id)),
                'issuer_snapshot' => InvoiceSnapshots::issuer($company, Agency::query()->findOrFail($locked->agency_id)),
                'total_excl_tax_amount' => $breakdown->totalExcludingTax,
                'total_tax_amount' => $breakdown->totalVat,
                'total_incl_tax_amount' => $breakdown->totalIncludingTax,
                'stamp_duty_amount' => $stamp,
                'total_due_amount' => $breakdown->totalIncludingTax->plus($stamp),
                'stamp_duty_rule_id' => $exemption === null ? $this->standardStampRuleId($at) : null,
                'stamp_exemption_rule_id' => $exemption?->id,
                'stamp_exemption_reason' => $exemption === null ? null : trim((string) ($stampExemption['reason'] ?? '')),
                'stamp_exemption_file_path' => $exemptionFile[0] ?? null,
                'stamp_exemption_file_sha256' => $exemptionFile[1] ?? null,
                'due_on' => $local->startOfDay()->addDays($this->settings->payment_terms_days),
            ])->save();

            return $locked->refresh();
        });
    }

    /**
     * Garde-fou fiscal (E6, N5) : en production, aucune validation tant que la
     * configuration fiscale ou les mentions obligatoires présentent un problème.
     */
    private function assertProductionReady(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        $problems = $this->fiscalGuard->problems();

        if ($problems !== []) {
            throw InvoiceValidationException::fiscalGuard(implode(' ; ', $problems));
        }
    }

    /**
     * Règle applicable de chaque ligne ; code choisi et justificatif exigés (N2, D55).
     *
     * @param  Collection<int, InvoiceLine>  $lines
     * @return array<string, TaxRule> par code fiscal
     */
    private function rulesFor(Collection $lines, CarbonImmutable $at): array
    {
        $total = $lines->reduce(fn (Money $sum, InvoiceLine $line): Money => $sum->plus($line->total_excl_tax_amount), Money::zero());

        if ($lines->isEmpty() || ! $total->isPositive()) {
            throw InvoiceValidationException::noLines();
        }

        $rules = [];

        foreach ($lines as $line) {
            $code = $line->taxCode;

            if ($code === null) {
                throw InvoiceValidationException::missingTaxCode($line->label);
            }

            try {
                $rule = $rules[$code->code] ?? $this->rules->resolve($code->code, $at);
            } catch (TaxRuleUnavailable $exception) {
                throw InvoiceValidationException::ruleUnavailable($line->label, $exception->getMessage());
            }

            $needsProof = $code->requires_justification
                || $code->nature !== TaxNature::Taxable
                || $rule->validation_status === FiscalValidationStatus::Conditional;

            if ($needsProof && $line->justifications->isEmpty()) {
                throw InvoiceValidationException::missingJustification($line->label, $code->code);
            }

            $rules[$code->code] = $rule;
        }

        return $rules;
    }

    /**
     * Exonération manuelle du timbre (N3, référentiel §3.3) : jamais automatique (une
     * facture impayée garde son timbre). Une règle d'exonération active, applicable à
     * la date, jamais PENDING_DOCUMENTATION, avec un motif ; le justificatif est
     * rattaché ensuite.
     *
     * @param  array{rule_id?: int|string|null, reason?: string|null, file?: string|null}  $data
     */
    private function stampExemption(array $data, CarbonImmutable $at): ?StampDutyRule
    {
        $ruleId = $data['rule_id'] ?? null;

        if ($ruleId === null || $ruleId === '') {
            return null;
        }

        $rule = StampDutyRule::query()->find($ruleId);

        if ($rule === null || $rule->rule_code === StampDutyRule::STANDARD) {
            throw InvoiceValidationException::stampExemption('règle d\'exonération inconnue.');
        }

        if ($rule->validation_status === FiscalValidationStatus::PendingDocumentation) {
            throw InvoiceValidationException::stampExemption("la règle {$rule->rule_code} attend ses conditions et justificatifs (PENDING_DOCUMENTATION) : elle ne s'applique pas.");
        }

        $day = $at->timezone((string) config('app.timezone'))->format('Y-m-d');

        if (! $rule->is_active || $rule->effective_from->format('Y-m-d') > $day || ($rule->effective_to !== null && $rule->effective_to->format('Y-m-d') < $day)) {
            throw InvoiceValidationException::stampExemption("la règle {$rule->rule_code} n'est pas active à cette date.");
        }

        if (mb_strlen(trim((string) ($data['reason'] ?? ''))) < self::MIN_EXEMPTION_REASON_LENGTH) {
            throw InvoiceValidationException::stampExemption('un motif d\'au moins '.self::MIN_EXEMPTION_REASON_LENGTH.' caractères est obligatoire.');
        }

        if (trim((string) ($data['file'] ?? '')) === '') {
            throw InvoiceValidationException::stampExemption('le justificatif est obligatoire.');
        }

        return $rule;
    }

    /**
     * @return array{0: string, 1: string} chemin privé et empreinte SHA-256
     */
    private function exemptionFile(Invoice $invoice, string $uploadedPath): array
    {
        try {
            $path = PrivateFiles::attach($uploadedPath, "invoices/{$invoice->id}/timbre");
        } catch (InvalidArgumentException $exception) {
            throw InvoiceValidationException::stampExemption($exception->getMessage());
        }

        return [$path, hash('sha256', (string) Storage::disk(PrivateFiles::DISK)->get($path))];
    }

    private function standardStampRuleId(CarbonImmutable $at): ?int
    {
        $day = $at->timezone((string) config('app.timezone'))->format('Y-m-d');
        $id = StampDutyRule::query()
            ->where('rule_code', StampDutyRule::STANDARD)
            ->where('is_active', true)
            ->where('validation_status', '!=', FiscalValidationStatus::PendingDocumentation->value)
            ->where('effective_from', '<=', $day)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $day))
            ->orderByDesc('effective_from')
            ->value('id');

        return is_numeric($id) ? (int) $id : null;
    }
}
