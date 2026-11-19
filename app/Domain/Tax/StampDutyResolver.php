<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use App\Domain\Tax\Exceptions\TaxRuleUnavailable;
use App\Enums\FiscalValidationStatus;
use App\Models\StampDutyRule;
use App\Support\Money\Money;
use DateTimeInterface;

/**
 * Timbre fiscal d'une facture ordinaire à une date (référentiel v1.0, §3) : la règle
 * de base active, applicable (jamais PENDING_DOCUMENTATION). Aucune exonération n'est
 * appliquée ici : une exonération de l'article 118 ne sera jamais automatique (une
 * facture « impayée » garde son timbre) ; son application manuelle, avec motif et
 * justificatif, relève de la phase 6.
 */
final class StampDutyResolver
{
    public function standardAmount(DateTimeInterface $date): Money
    {
        $day = $date->format('Y-m-d');

        $rule = StampDutyRule::query()
            ->where('rule_code', StampDutyRule::STANDARD)
            ->where('is_active', true)
            ->where('validation_status', '!=', FiscalValidationStatus::PendingDocumentation->value)
            ->where('effective_from', '<=', $day)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $day))
            ->orderByDesc('effective_from')
            ->first();

        if ($rule === null) {
            throw TaxRuleUnavailable::noStampRule($date->format('d/m/Y'));
        }

        return $rule->amount;
    }
}
