<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Tax\TaxBreakdown;
use App\Enums\TaxNature;
use App\Models\Invoice;
use App\Models\InvoiceTax;
use App\Models\TaxRule;
use App\Support\Money\Money;
use Brick\Math\RoundingMode;

/**
 * Taxes enregistrées d'une facture ou d'un avoir (référentiel §5, §6) : une taxe par
 * code fiscal, avec la copie figée de sa règle. La TVA de chaque taux (calculée une
 * fois sur la base agrégée par TaxCalculator) est répartie entre ses codes au prorata
 * de leur base, le millime restant au code de plus forte base : la somme par taux
 * reste celle du calcul.
 */
final class InvoiceTaxWriter
{
    /**
     * Bases regroupées par taux, pour TaxCalculator::fromBases().
     *
     * @param  array<string, Money>  $bases  par code fiscal
     * @param  array<string, TaxRule>  $rules  par code fiscal
     * @return list<array{nature: string, rate: string|null, base: Money}>
     */
    public static function groups(array $bases, array $rules): array
    {
        $groups = [];

        foreach ($bases as $code => $base) {
            $rule = $rules[$code];
            $key = self::rateKey($rule);
            $groups[$key] ??= ['nature' => $rule->taxCode->nature->value, 'rate' => self::rate($rule), 'base' => Money::zero()];
            $groups[$key]['base'] = $groups[$key]['base']->plus($base);
        }

        return array_values($groups);
    }

    /**
     * @param  array<string, Money>  $bases  par code fiscal
     * @param  array<string, TaxRule>  $rules  par code fiscal
     * @param  array<string, array<string, mixed>>  $snapshots  copie figée à reprendre telle quelle (avoir : celle de la facture d'origine)
     */
    public function write(Invoice $invoice, array $bases, array $rules, TaxBreakdown $breakdown, array $snapshots = []): void
    {
        /** @var array<string, list<string>> $byRate */
        $byRate = [];

        foreach ($bases as $code => $base) {
            $byRate[self::rateKey($rules[$code])][] = $code;
        }

        $vat = [];

        foreach ($breakdown->bases as $group) {
            $key = $group['rate'] === null ? null : 'rate:'.$group['rate'];

            if ($key === null || ! isset($byRate[$key])) {
                continue;
            }

            $codes = $byRate[$key];
            usort($codes, fn (string $a, string $b): int => $bases[$b]->compareTo($bases[$a]));
            $allocated = Money::zero();

            foreach (array_slice($codes, 1) as $code) {
                $vat[$code] = $group['base']->isZero() ? Money::zero()
                    : $group['vat']->multipliedBy($bases[$code]->toBigDecimal(), RoundingMode::Unnecessary)
                        ->dividedBy($group['base']->toBigDecimal(), RoundingMode::HalfUp);
                $allocated = $allocated->plus($vat[$code]);
            }

            $vat[$codes[0]] = $group['vat']->minus($allocated);
        }

        // Un brouillon n'a pas de taxes. Suppression seulement s'il en existe : un DELETE
        // sans ligne trouvée verrouille l'intervalle de l'index (gap lock) et bloque
        // l'insertion des taxes d'une autre facture validée au même moment (interblocage).
        if (InvoiceTax::query()->where('invoice_id', $invoice->id)->exists()) {
            InvoiceTax::query()->where('invoice_id', $invoice->id)->delete();
        }

        foreach ($bases as $code => $base) {
            $rule = $rules[$code];
            InvoiceTax::query()->create([
                'invoice_id' => $invoice->id,
                'tax_rule_id' => $rule->id,
                'tax_code_id' => $rule->tax_code_id,
                'code' => $code,
                'nature' => $rule->taxCode->nature,
                'rate' => $rule->taxCode->nature === TaxNature::Taxable ? $rule->rate : null,
                'base_amount' => $base,
                'tax_amount' => $vat[$code] ?? Money::zero(),
                'validation_status' => $rule->validation_status,
                'rule_snapshot' => $snapshots[$code] ?? self::snapshot($rule, $code),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function snapshot(TaxRule $rule, string $code): array
    {
        return [
            'tax_rule_id' => $rule->id,
            'code' => $code,
            'label' => $rule->taxCode->label,
            'nature' => $rule->taxCode->nature->value,
            'rate' => $rule->rate,
            'requires_justification' => $rule->taxCode->requires_justification,
            'legal_reference' => $rule->taxCode->legal_reference,
            'valid_from' => $rule->valid_from->format('Y-m-d'),
            'valid_to' => $rule->valid_to?->format('Y-m-d'),
            'validation_status' => $rule->validation_status->value,
            'fiscal_reference_id' => $rule->fiscal_reference_id,
        ];
    }

    /** Même clé de regroupement que TaxCalculator : « rate:19 », ou la nature sans taux. */
    public static function rateKey(TaxRule $rule): string
    {
        $rate = self::rate($rule);

        return $rate !== null ? 'rate:'.$rate : 'nature:'.$rule->taxCode->nature->value;
    }

    /** « 19.0000 » → « 19 », sans float ; nul hors TVA. */
    private static function rate(TaxRule $rule): ?string
    {
        return $rule->taxCode->nature === TaxNature::Taxable && $rule->rate !== null
            ? rtrim(rtrim($rule->rate, '0'), '.')
            : null;
    }
}
