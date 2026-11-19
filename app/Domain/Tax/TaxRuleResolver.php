<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use App\Domain\Tax\Exceptions\TaxRuleUnavailable;
use App\Enums\FiscalValidationStatus;
use App\Models\TaxCode;
use App\Models\TaxRule;
use DateTimeInterface;

/**
 * Règle de TVA en vigueur pour un code fiscal à une date (plan de la phase 3, §3.3).
 *
 * Recherche par **code exact** uniquement : aucun libellé n'est jamais lu. Refuse
 * explicitement un code inconnu, une date sans règle, une règle inactive et une règle
 * PENDING_DOCUMENTATION. Une règle CONDITIONAL est renvoyée : son justificatif est
 * exigé ligne par ligne à la facturation (phase 6).
 */
final class TaxRuleResolver
{
    public function resolve(string $code, DateTimeInterface $date): TaxRule
    {
        $taxCode = TaxCode::query()->where('code', $code)->first();

        if ($taxCode === null) {
            throw TaxRuleUnavailable::unknownCode($code);
        }

        $day = $date->format('Y-m-d');

        $rule = TaxRule::query()
            ->with('taxCode')
            ->where('tax_code_id', $taxCode->id)
            ->where('valid_from', '<=', $day)
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $day))
            ->orderByDesc('valid_from')
            ->first();

        if ($rule?->validation_status === FiscalValidationStatus::PendingDocumentation) {
            throw TaxRuleUnavailable::pendingDocumentation($code);
        }

        if ($rule === null || ! $rule->is_active) {
            throw TaxRuleUnavailable::noRule($code, $date->format('d/m/Y'));
        }

        return $rule;
    }
}
