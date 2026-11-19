<?php

declare(strict_types=1);

namespace App\Domain\Tax\Actions;

use App\Domain\Tax\Exceptions\FiscalSetupException;
use App\Enums\FiscalValidationStatus;
use App\Models\FiscalReference;
use App\Models\StampDutyRule;
use App\Models\User;
use App\Support\Money\AmountInput;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Ajoute une version datée d'une règle de timbre existante (référentiel v1.0, §3.4) :
 * nouveau montant, statut ou référence, mêmes description et conditions. Mêmes
 * principes que AddTaxRuleVersion : super admin, versions sans chevauchement sous
 * verrou, version ouverte close à la veille, PENDING_DOCUMENTATION créée inactive,
 * CONFIRMED avec référence, journal « stamp_duty_rule_version_added ».
 */
final class AddStampDutyRuleVersion
{
    /**
     * @param  array<string, mixed>  $data  amount, effective_from (AAAA-MM-JJ), validation_status, fiscal_reference_id
     */
    public function handle(string $ruleCode, array $data, User $actor): StampDutyRule
    {
        if (! $actor->can('create', StampDutyRule::class)) {
            throw FiscalSetupException::notAllowed();
        }

        $status = FiscalValidationStatus::tryFrom(is_string($data['validation_status'] ?? null) ? $data['validation_status'] : '')
            ?? throw FiscalSetupException::invalidStatus();

        try {
            $amount = AmountInput::parse($data['amount'] ?? null) ?? throw FiscalSetupException::invalidAmount();
        } catch (InvalidArgumentException) {
            throw FiscalSetupException::invalidAmount();
        }

        $fromValue = $data['effective_from'] ?? null;
        $from = is_string($fromValue) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromValue) === 1
            ? CarbonImmutable::createFromFormat('Y-m-d', $fromValue)?->startOfDay()
            : null;

        if ($from === null || $from->format('Y-m-d') !== $fromValue) {
            throw FiscalSetupException::invalidDate();
        }

        $referenceValue = $data['fiscal_reference_id'] ?? null;
        $referenceId = is_numeric($referenceValue) && FiscalReference::query()->whereKey((int) $referenceValue)->exists()
            ? (int) $referenceValue
            : null;

        if ($status === FiscalValidationStatus::Confirmed && $referenceId === null) {
            throw FiscalSetupException::referenceRequired();
        }

        return DB::transaction(function () use ($ruleCode, $actor, $status, $amount, $from, $referenceId): StampDutyRule {
            $versions = StampDutyRule::query()->where('rule_code', $ruleCode)->orderByDesc('effective_from')->lockForUpdate()->get();
            $latest = $versions->first() ?? throw FiscalSetupException::unknownStampRule();

            if ($latest->effective_from->greaterThanOrEqualTo($from)) {
                throw FiscalSetupException::notAfterExisting($latest->effective_from->format('d/m/Y'));
            }

            if ($latest->effective_to === null || $latest->effective_to->greaterThanOrEqualTo($from)) {
                $latest->effective_to = $from->subDay();
                $latest->save();
            }

            $rule = StampDutyRule::query()->create([
                'rule_code' => $ruleCode,
                'description' => $latest->description,
                'amount' => $amount,
                'applicability' => $latest->applicability,
                'exemption_condition' => $latest->exemption_condition,
                'legal_reference' => $latest->legal_reference,
                'effective_from' => $from,
                'validation_status' => $status,
                'fiscal_reference_id' => $referenceId,
                'is_active' => $status->isApplicable(),
                'created_by' => $actor->id,
            ]);

            activity('audit')
                ->causedBy($actor)
                ->performedOn($rule)
                ->event('stamp_duty_rule_version_added')
                ->withProperties([
                    'rule_code' => $ruleCode,
                    'old' => [
                        'amount' => $latest->amount->toDecimalString(),
                        'validation_status' => $latest->validation_status->value,
                        'effective_from' => $latest->effective_from->toDateString(),
                    ],
                    'attributes' => [
                        'amount' => $amount->toDecimalString(),
                        'validation_status' => $status->value,
                        'effective_from' => $from->toDateString(),
                    ],
                ])
                ->log("Nouvelle version de la règle de timbre {$ruleCode}");

            return $rule;
        });
    }
}
