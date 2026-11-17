<?php

declare(strict_types=1);

namespace App\Support\Money;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Cast Eloquent d'une colonne DECIMAL(15,3) vers Money, et inversement.
 * Refuse un float : un montant s'écrit en Money, en chaîne décimale ou en entier.
 *
 * Type d'entrée « mixed » : à l'exécution, n'importe quelle valeur peut être affectée
 * à l'attribut, et c'est ce cast qui refuse un float ou un type inattendu.
 *
 * @implements CastsAttributes<Money|null, mixed>
 */
final class MoneyCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) || is_int($value)) {
            return Money::of($value);
        }

        throw new InvalidArgumentException("Valeur inattendue en base pour le montant « {$key} ».");
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_float($value)) {
            throw new InvalidArgumentException("Montant « {$key} » fourni en float : utilisez Money ou une chaîne décimale.");
        }

        if ($value instanceof Money) {
            return $value->toDecimalString();
        }

        if (is_string($value) || is_int($value)) {
            return Money::of($value)->toDecimalString();
        }

        throw new InvalidArgumentException("Type invalide pour le montant « {$key} ».");
    }
}
