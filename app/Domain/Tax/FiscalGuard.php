<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use App\Enums\FiscalValidationStatus;
use App\Models\Company;
use App\Models\FiscalReference;
use App\Models\StampDutyRule;
use App\Models\TaxRule;

/**
 * Garde-fou fiscal de « aviator:check-environment » (plan de la phase 3, §3.5 ;
 * phase 10). Problèmes bloquants pour la production :
 *
 * 1. aucune référence fiscale validée enregistrée ;
 * 2. une règle active est PENDING_DOCUMENTATION ;
 * 3. une règle CONFIRMED n'a pas de référence ;
 * 4. les mentions obligatoires des factures sont vides (décision du 19/11/2026).
 *
 * Une règle CONDITIONAL est admise : son justificatif est exigé ligne par ligne en
 * phase 6. Les règles en cause sont citées par leur code seulement.
 */
final class FiscalGuard
{
    /**
     * @return list<string> Problèmes constatés, vide si tout est en ordre
     */
    public function problems(): array
    {
        $problems = [];

        if (FiscalReference::query()->doesntExist()) {
            $problems[] = 'aucune référence fiscale validée enregistrée';
        }

        $pending = [
            ...TaxRule::query()->with('taxCode')
                ->where('is_active', true)
                ->where('validation_status', FiscalValidationStatus::PendingDocumentation->value)
                ->get()->map(fn (TaxRule $rule): string => $rule->taxCode->code)->all(),
            ...StampDutyRule::query()
                ->where('is_active', true)
                ->where('validation_status', FiscalValidationStatus::PendingDocumentation->value)
                ->pluck('rule_code')->all(),
        ];

        if ($pending !== []) {
            $problems[] = 'règle(s) active(s) en attente de pièce (PENDING_DOCUMENTATION) : '.implode(', ', array_unique($pending));
        }

        $unreferenced = [
            ...TaxRule::query()->with('taxCode')
                ->where('validation_status', FiscalValidationStatus::Confirmed->value)
                ->whereNull('fiscal_reference_id')
                ->get()->map(fn (TaxRule $rule): string => $rule->taxCode->code)->all(),
            ...StampDutyRule::query()
                ->where('validation_status', FiscalValidationStatus::Confirmed->value)
                ->whereNull('fiscal_reference_id')
                ->pluck('rule_code')->all(),
        ];

        if ($unreferenced !== []) {
            $problems[] = 'règle(s) CONFIRMED sans référence validée : '.implode(', ', array_unique($unreferenced));
        }

        if (blank(Company::current()?->invoice_legal_mentions)) {
            $problems[] = 'mentions obligatoires des factures vides (fiche société)';
        }

        return $problems;
    }
}
