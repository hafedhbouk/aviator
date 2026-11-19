<?php

declare(strict_types=1);

namespace App\Support\Money;

use Brick\Math\Exception\MathException;
use InvalidArgumentException;

/**
 * Lecture d'un montant saisi dans un formulaire : « 150 », « 150,5 », « 1 500,250 »
 * ou « 150.500 ». Virgule ou point décimal, espaces de milliers ignorées ; au plus
 * 3 décimales (millime), jamais de float.
 */
final class AmountInput
{
    /** Motif accepté par les champs de montant (validation côté formulaire). */
    public const PATTERN = '/^\s*\d{1,3}(?:[ \x{00A0}\x{202F}]?\d{3})*(?:[.,]\d{1,3})?\s*$/u';

    /**
     * @return Money|null null pour une saisie vide
     *
     * @throws InvalidArgumentException saisie qui n'est pas un montant positif ou nul au millime
     */
    public static function parse(mixed $value): ?Money
    {
        if ($value instanceof Money) {
            return $value;
        }

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException('Montant invalide : chiffres, virgule décimale, 3 décimales au plus.');
        }

        $normalized = str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], trim($value));

        try {
            return Money::of($normalized);
        } catch (MathException|InvalidArgumentException) {
            throw new InvalidArgumentException('Montant invalide : chiffres, virgule décimale, 3 décimales au plus.');
        }
    }

    /**
     * Valeur affichée dans un champ : « 150,500 ».
     */
    public static function display(mixed $value): ?string
    {
        $money = $value instanceof Money ? $value : self::parse($value);

        return $money === null ? null : str_replace('.', ',', $money->toDecimalString());
    }
}
