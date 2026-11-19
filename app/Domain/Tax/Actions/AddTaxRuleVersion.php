<?php

declare(strict_types=1);

namespace App\Domain\Tax\Actions;

use App\Domain\Tax\Exceptions\FiscalSetupException;
use App\Enums\FiscalValidationStatus;
use App\Models\FiscalReference;
use App\Models\TaxCode;
use App\Models\TaxRule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Ajoute une version datée de la règle de TVA d'un code (plan de la phase 3, §3.1).
 *
 * - Super admin seulement (permission fiscal.manage).
 * - Taux obligatoire pour un code taxable (0 à 100 %, 4 décimales), interdit sinon.
 * - CONFIRMED : référence validée obligatoire. PENDING_DOCUMENTATION : créée inactive.
 * - La version commence après toutes les versions existantes ; la version ouverte est
 *   close à la veille. Aucune règle n'est modifiée autrement (TaxRule l'interdit), et
 *   le contrôle se fait sous verrou du code, pour que deux saisies simultanées ne
 *   créent pas de chevauchement.
 * - Journal « audit » : création et clôture (AuditsChanges), plus une entrée
 *   « tax_rule_version_added » avec la version précédente et la nouvelle.
 */
final class AddTaxRuleVersion
{
    /**
     * @param  array<string, mixed>  $data  rate, valid_from (AAAA-MM-JJ), validation_status, fiscal_reference_id
     */
    public function handle(TaxCode $taxCode, array $data, User $actor): TaxRule
    {
        if (! $actor->can('create', TaxRule::class)) {
            throw FiscalSetupException::notAllowed();
        }

        $status = FiscalValidationStatus::tryFrom(is_string($data['validation_status'] ?? null) ? $data['validation_status'] : '')
            ?? throw FiscalSetupException::invalidStatus();
        $rate = self::rate($data['rate'] ?? null, $taxCode->nature->hasRate());
        $from = self::date($data['valid_from'] ?? null);
        $referenceId = self::referenceId($data['fiscal_reference_id'] ?? null);

        if ($status === FiscalValidationStatus::Confirmed && $referenceId === null) {
            throw FiscalSetupException::referenceRequired();
        }

        return DB::transaction(function () use ($taxCode, $actor, $status, $rate, $from, $referenceId): TaxRule {
            TaxCode::query()->whereKey($taxCode->id)->lockForUpdate()->firstOrFail();

            $latest = TaxRule::query()->where('tax_code_id', $taxCode->id)->orderByDesc('valid_from')->first();

            if ($latest !== null && $latest->valid_from->greaterThanOrEqualTo($from)) {
                throw FiscalSetupException::notAfterExisting($latest->valid_from->format('d/m/Y'));
            }

            if ($latest !== null && ($latest->valid_to === null || $latest->valid_to->greaterThanOrEqualTo($from))) {
                $latest->valid_to = $from->subDay();
                $latest->save();
            }

            $rule = TaxRule::query()->create([
                'tax_code_id' => $taxCode->id,
                'rate' => $rate,
                'valid_from' => $from,
                'validation_status' => $status,
                'fiscal_reference_id' => $referenceId,
                'is_active' => $status->isApplicable(),
                'created_by' => $actor->id,
            ]);

            activity('audit')
                ->causedBy($actor)
                ->performedOn($rule)
                ->event('tax_rule_version_added')
                ->withProperties([
                    'code' => $taxCode->code,
                    'old' => $latest === null ? null : [
                        'rate' => $latest->rate,
                        'validation_status' => $latest->validation_status->value,
                        'valid_from' => $latest->valid_from->toDateString(),
                        // Toujours renseignée ici : la version précédente vient d'être close, ou l'était déjà.
                        'valid_to' => $latest->valid_to->toDateString(),
                    ],
                    'attributes' => [
                        'rate' => $rate,
                        'validation_status' => $status->value,
                        'valid_from' => $from->toDateString(),
                    ],
                ])
                ->log("Nouvelle version de la règle fiscale {$taxCode->code}");

            return $rule;
        });
    }

    private static function rate(mixed $value, bool $required): ?string
    {
        $value = is_scalar($value) ? str_replace(',', '.', trim((string) $value)) : '';

        if (! $required) {
            return $value === '' ? null : throw FiscalSetupException::rateForbidden();
        }

        if (preg_match('/^\d{1,2}(\.\d{1,4})?$|^100(\.0{1,4})?$/', $value) !== 1) {
            throw FiscalSetupException::rateRequired();
        }

        return self::scaled($value);
    }

    /**
     * « 19 » → « 19.0000 », sans passer par un float.
     */
    private static function scaled(string $value): string
    {
        [$integer, $decimals] = array_pad(explode('.', $value), 2, '');

        return $integer.'.'.str_pad($decimals, 4, '0');
    }

    private static function date(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw FiscalSetupException::invalidDate();
        }

        $date = CarbonImmutable::createFromFormat('Y-m-d', $value);

        if ($date === null || $date->format('Y-m-d') !== $value) {
            throw FiscalSetupException::invalidDate();
        }

        return $date->startOfDay();
    }

    private static function referenceId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = is_numeric($value) ? (int) $value : 0;

        return FiscalReference::query()->whereKey($id)->exists() ? $id : throw FiscalSetupException::referenceRequired();
    }
}
