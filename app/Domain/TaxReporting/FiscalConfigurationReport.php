<?php

declare(strict_types=1);

namespace App\Domain\TaxReporting;

use App\Domain\Tax\Exceptions\TaxRuleUnavailable;
use App\Domain\Tax\TaxCalculator;
use App\Domain\Tax\TaxLine;
use App\Models\FiscalReference;
use App\Models\StampDutyRule;
use App\Models\TaxCode;
use App\Models\TaxRule;
use App\Settings\BillingSettings;
use App\Support\Money\Money;
use DateTimeInterface;

/**
 * Rapport de configuration fiscale (plan de la phase 3, §3.4), à signer par la
 * direction avant la mise en production (phase 10) : références validées et leur
 * SHA-256, chaque code et chaque version de sa règle, chaque règle de timbre, mode
 * d'arrondi, et l'exemple chiffré du référentiel (§5) **calculé en direct** par
 * TaxCalculator avec les règles enregistrées.
 */
final class FiscalConfigurationReport
{
    /** Exemple du référentiel v1.0, §5 : code fiscal => montant HT. */
    public const EXAMPLE = ['RENTAL' => '100.000', 'GPS' => '10.000', 'CHILD_SEAT' => '5.000'];

    public function __construct(
        private readonly TaxCalculator $calculator,
        private readonly BillingSettings $billing,
    ) {}

    /**
     * @return array{
     *     generated_at: string,
     *     as_of: string,
     *     rounding_mode: string,
     *     references: list<array<string, string>>,
     *     rules: list<array<string, string>>,
     *     stamps: list<array<string, string>>,
     *     example: array{lines: list<array<string, string>>, totals: list<array<string, string>>, error: string|null}
     * }
     */
    public function build(DateTimeInterface $asOf): array
    {
        return [
            'generated_at' => now()->format('d/m/Y H:i'),
            'as_of' => $asOf->format('d/m/Y'),
            'rounding_mode' => $this->billing->vat_rounding_mode === 'half_even'
                ? 'Demi pair (half_even)'
                : 'Demi supérieur (half_up), à confirmer par le cabinet (Q86)',
            'references' => FiscalReference::query()->orderBy('validated_at')->get()->map(fn (FiscalReference $r): array => [
                'version' => $r->version,
                'title' => $r->title,
                'file_name' => $r->file_name,
                'sha256' => $r->sha256,
                'validation_source' => $r->validation_source,
                'validated_at' => $r->validated_at->format('d/m/Y'),
                'attestation' => $r->attestation_file_name ?? '—',
            ])->values()->all(),
            'rules' => TaxRule::query()->with(['taxCode', 'fiscalReference'])
                ->join('tax_codes', 'tax_codes.id', '=', 'tax_rules.tax_code_id')
                ->orderBy('tax_codes.id')->orderBy('tax_rules.valid_from')
                ->select('tax_rules.*')
                ->get()->map(fn (TaxRule $rule): array => [
                    'code' => $rule->taxCode->code,
                    'label' => $rule->taxCode->label,
                    'nature' => $rule->taxCode->nature->label(),
                    'rate' => $rule->rate === null ? '—' : rtrim(rtrim(str_replace('.', ',', $rule->rate), '0'), ',').' %',
                    'justification' => $rule->taxCode->requires_justification ? 'Oui' : 'Non',
                    'valid_from' => $rule->valid_from->format('d/m/Y'),
                    'valid_to' => $rule->valid_to?->format('d/m/Y') ?? '—',
                    'status' => $rule->validation_status->value,
                    'active' => $rule->is_active ? 'Oui' : 'Non',
                    'reference' => $rule->fiscalReference?->label() ?? '—',
                    'legal_reference' => $rule->taxCode->legal_reference,
                ])->values()->all(),
            'stamps' => StampDutyRule::query()->with('fiscalReference')->orderBy('rule_code')->orderBy('effective_from')
                ->get()->map(fn (StampDutyRule $rule): array => [
                    'code' => $rule->rule_code,
                    'description' => $rule->description,
                    'amount' => $rule->amount->format(),
                    'condition' => $rule->exemption_condition ?? '—',
                    'valid_from' => $rule->effective_from->format('d/m/Y'),
                    'valid_to' => $rule->effective_to?->format('d/m/Y') ?? '—',
                    'status' => $rule->validation_status->value,
                    'active' => $rule->is_active ? 'Oui' : 'Non',
                    'reference' => $rule->fiscalReference?->label() ?? '—',
                    'legal_reference' => $rule->legal_reference,
                ])->values()->all(),
            'example' => $this->example($asOf),
        ];
    }

    /**
     * @return array{lines: list<array<string, string>>, totals: list<array<string, string>>, error: string|null}
     */
    private function example(DateTimeInterface $asOf): array
    {
        $labels = TaxCode::query()->whereIn('code', array_keys(self::EXAMPLE))->pluck('label', 'code');
        $lines = [];

        foreach (self::EXAMPLE as $code => $amount) {
            $lines[] = ['code' => $code, 'label' => (string) ($labels[$code] ?? $code), 'amount' => Money::of($amount)->format()];
        }

        try {
            $result = $this->calculator->calculate(
                array_map(fn (string $code, string $amount): TaxLine => new TaxLine($code, Money::of($amount)), array_keys(self::EXAMPLE), self::EXAMPLE),
                $asOf,
            );
        } catch (TaxRuleUnavailable $exception) {
            return ['lines' => $lines, 'totals' => [], 'error' => $exception->getMessage()];
        }

        $totals = [['label' => 'Total HT', 'amount' => $result->totalExcludingTax->format()]];

        foreach ($result->bases as $base) {
            if ($base['rate'] !== null) {
                $totals[] = ['label' => 'TVA '.str_replace('.', ',', $base['rate']).' %', 'amount' => $base['vat']->format()];
            }
        }

        return ['lines' => $lines, 'totals' => [
            ...$totals,
            ['label' => 'Total TTC', 'amount' => $result->totalIncludingTax->format()],
            ['label' => 'Timbre fiscal', 'amount' => $result->stampDuty->format()],
            ['label' => 'Net à payer', 'amount' => $result->netToPay->format()],
        ], 'error' => null];
    }
}
