<?php

declare(strict_types=1);

namespace App\Domain\Setup\Actions;

use App\Domain\Setup\Exceptions\SetupException;
use App\Domain\Tax\Exceptions\TaxRuleUnavailable;
use App\Domain\Tax\TaxRuleResolver;
use App\Models\Option;
use App\Models\TaxCode;
use App\Models\User;
use App\Support\Money\AmountInput;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Crée ou modifie une option facturable (A-05), avec les règles suivantes.
 *
 * - Super admin et admin du siège seulement (OptionPolicy) ; lecture pour l'agence.
 * - Code fiscal **choisi** dans la liste du référentiel, jamais déduit du libellé :
 *   une option « assurance » sans code reste sans code.
 * - Une option ne s'active qu'avec un code fiscal dont la règle est applicable
 *   aujourd'hui (ni inactive, ni PENDING_DOCUMENTATION) et un prix HT > 0 (Q34).
 *   « Livraison » reste donc inactive tant que Q85 n'a pas de réponse.
 * - Plafond par location facultatif, au moins égal au prix ; prix par jour ou par location.
 * - Aucune option supprimée (désactivation). Modification journalisée (audit).
 */
final class SaveOption
{
    public function __construct(private readonly TaxRuleResolver $rules) {}

    /**
     * @param  array<string, mixed>  $data  code, name, pricing_unit, amount, max_amount_per_rental, tax_code_id, is_active
     */
    public function handle(?Option $option, array $data, User $actor): Option
    {
        if (! $actor->can($option === null ? 'create' : 'update', $option ?? Option::class)) {
            throw SetupException::notAllowed('les options');
        }

        $text = fn (string $key): ?string => is_scalar($data[$key] ?? null) && trim((string) $data[$key]) !== '' ? trim((string) $data[$key]) : null;

        $values = Validator::make([
            'code' => ($code = $text('code')) === null ? null : mb_strtoupper($code),
            'name' => $text('name'),
            'pricing_unit' => $text('pricing_unit'),
            'tax_code_id' => $text('tax_code_id'),
        ], [
            'code' => ['required', 'string', 'regex:/^[A-Z0-9_-]{2,32}$/', 'unique:options,code'.($option !== null ? ','.$option->id : '')],
            'name' => ['required', 'string', 'max:100'],
            'pricing_unit' => ['required', 'in:'.Option::PER_DAY.','.Option::PER_RENTAL],
            'tax_code_id' => ['nullable', 'integer', 'exists:tax_codes,id'],
        ], attributes: ['name' => 'nom', 'pricing_unit' => 'unité de prix', 'tax_code_id' => 'code fiscal'])->validate();

        $amount = self::amount($data['amount'] ?? null, 'amount', 'prix');
        $max = self::amount($data['max_amount_per_rental'] ?? null, 'max_amount_per_rental', 'plafond');
        $isActive = (bool) ($data['is_active'] ?? false);
        $taxCode = $values['tax_code_id'] === null ? null : TaxCode::query()->find((int) $values['tax_code_id']);

        if ($max !== null && $amount !== null && $max->isLessThan($amount)) {
            throw ValidationException::withMessages(['max_amount_per_rental' => 'Le plafond est inférieur au prix.']);
        }

        if ($isActive) {
            $this->assertActivable($taxCode, $amount);
        }

        return DB::transaction(function () use ($option, $values, $amount, $max, $taxCode, $isActive): Option {
            $option ??= new Option;
            $option->fill([
                'code' => $values['code'],
                'name' => $values['name'],
                'pricing_unit' => $values['pricing_unit'],
                'amount' => $amount,
                'max_amount_per_rental' => $max,
                'tax_code_id' => $taxCode?->id,
                'is_active' => $isActive,
            ]);
            $option->save();

            return $option;
        });
    }

    private function assertActivable(?TaxCode $taxCode, ?Money $amount): void
    {
        if ($taxCode === null) {
            throw SetupException::optionWithoutTaxCode();
        }

        try {
            $this->rules->resolve($taxCode->code, now());
        } catch (TaxRuleUnavailable $exception) {
            throw SetupException::optionTaxRuleUnavailable($exception->getMessage());
        }

        if ($amount === null || ! $amount->isPositive()) {
            throw SetupException::optionWithoutPrice();
        }
    }

    private static function amount(mixed $value, string $key, string $label): ?Money
    {
        try {
            return AmountInput::parse($value);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([$key => "Le champ {$label} : {$exception->getMessage()}"]);
        }
    }
}
