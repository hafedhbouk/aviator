<?php

declare(strict_types=1);

namespace App\Support\Money;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Montant en dinars tunisiens (TND), au millime : 3 décimales, comme les colonnes
 * DECIMAL(15,3). Immuable, calculé avec brick/math ; jamais de float.
 *
 * Aucun arrondi n'est implicite : une opération qui peut produire plus de 3
 * décimales (multiplication par un taux, division) exige un mode d'arrondi
 * explicite. Le mode applicable aux taxes sera fixé par la règle fiscale validée
 * (Q11) : ce code n'en suppose aucun.
 */
final class Money implements JsonSerializable, Stringable
{
    public const SCALE = 3;

    public const CURRENCY = 'TND';

    /** Plus grande valeur absolue d'une colonne DECIMAL(15,3). */
    public const MAX_ABSOLUTE = '999999999999.999';

    private function __construct(private readonly BigDecimal $amount) {}

    /**
     * Montant exact, sans arrondi : refusé s'il a plus de 3 décimales.
     *
     * @param  string|int|BigDecimal  $amount  Chaîne décimale (« 123.450 », « -0.5 ») ou entier de dinars
     *
     * @throws InvalidArgumentException si le montant n'est pas un nombre décimal valide ou dépasse DECIMAL(15,3)
     * @throws RoundingNecessaryException s'il a plus de 3 décimales
     */
    public static function of(string|int|BigDecimal $amount): self
    {
        return self::fromDecimal(self::parse($amount)->toScale(self::SCALE, RoundingMode::Unnecessary));
    }

    /**
     * Montant arrondi au millime selon un mode explicite.
     */
    public static function ofRounded(string|int|BigDecimal $amount, RoundingMode $rounding): self
    {
        return self::fromDecimal(self::parse($amount)->toScale(self::SCALE, $rounding));
    }

    /**
     * Montant exprimé en millimes (entier).
     */
    public static function ofMillimes(int|string|BigInteger $millimes): self
    {
        return self::fromDecimal(BigDecimal::ofUnscaledValue($millimes, self::SCALE));
    }

    public static function zero(): self
    {
        return new self(BigDecimal::zero()->toScale(self::SCALE));
    }

    public function plus(self $other): self
    {
        return self::fromDecimal($this->amount->plus($other->amount));
    }

    public function minus(self $other): self
    {
        return self::fromDecimal($this->amount->minus($other->amount));
    }

    /**
     * Multiplication par une quantité ou un taux, arrondie au millime selon le mode donné.
     *
     * @param  string|int|BigDecimal  $factor  Jamais un float : « 1.19 », « 4 », BigDecimal
     */
    public function multipliedBy(string|int|BigDecimal $factor, RoundingMode $rounding): self
    {
        return self::fromDecimal($this->amount->multipliedBy(self::parse($factor))->toScale(self::SCALE, $rounding));
    }

    /**
     * Division, arrondie au millime selon le mode donné.
     */
    public function dividedBy(string|int|BigDecimal $divisor, RoundingMode $rounding): self
    {
        return self::fromDecimal($this->amount->dividedBy(self::parse($divisor), self::SCALE, $rounding));
    }

    /**
     * Répartit le montant en parts égales au millime près, sans rien perdre ni créer :
     * les millimes restants vont aux premières parts (100,000 en 3 = 33,334 + 33,333 + 33,333).
     *
     * @return list<self>
     */
    public function allocate(int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('Le nombre de parts doit être au moins 1.');
        }

        $millimes = $this->amount->getUnscaledValue();
        [$quotient, $remainder] = $millimes->quotientAndRemainder($parts);
        $remainderCount = $remainder->abs()->toInt();
        $unit = $millimes->isNegative() ? -1 : 1;

        $shares = [];

        for ($i = 0; $i < $parts; $i++) {
            $share = $i < $remainderCount ? $quotient->plus($unit) : $quotient;
            $shares[] = self::ofMillimes($share);
        }

        return $shares;
    }

    public function negated(): self
    {
        return new self($this->amount->negated());
    }

    public function isZero(): bool
    {
        return $this->amount->isZero();
    }

    public function isNegative(): bool
    {
        return $this->amount->isNegative();
    }

    public function isPositive(): bool
    {
        return $this->amount->isPositive();
    }

    public function compareTo(self $other): int
    {
        return $this->amount->compareTo($other->amount);
    }

    public function equals(self $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function isLessThan(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function toBigDecimal(): BigDecimal
    {
        return $this->amount;
    }

    public function toMillimes(): BigInteger
    {
        return $this->amount->getUnscaledValue();
    }

    /**
     * Valeur pour la base de données et les échanges : toujours 3 décimales, point décimal.
     */
    public function toDecimalString(): string
    {
        return (string) $this->amount;
    }

    /**
     * Affichage en français : « 1 234,500 DT » (espace fine insécable pour les milliers).
     */
    public function format(): string
    {
        $string = $this->toDecimalString();
        $negative = str_starts_with($string, '-');
        [$integer, $decimals] = explode('.', ltrim($string, '-'));

        // Groupes de trois chiffres en partant de la droite, joints par une espace fine
        // insécable (caractère multioctet : jamais d'inversion de chaîne après insertion).
        $groups = array_map('strrev', array_reverse(str_split(strrev($integer), 3)));
        $grouped = implode("\u{202F}", $groups);

        return ($negative ? '-' : '').$grouped.','.$decimals.' DT';
    }

    public function __toString(): string
    {
        return $this->toDecimalString();
    }

    public function jsonSerialize(): string
    {
        return $this->toDecimalString();
    }

    private static function parse(string|int|BigDecimal $value): BigDecimal
    {
        if ($value instanceof BigDecimal) {
            return $value;
        }

        if (is_int($value)) {
            return BigDecimal::of($value);
        }

        $normalized = trim($value);

        if (preg_match('/^-?\d+(\.\d+)?$/', $normalized) !== 1) {
            throw new InvalidArgumentException("Montant invalide : « {$value} ». Format attendu : 1234.567 (point décimal, sans exposant).");
        }

        return BigDecimal::of($normalized);
    }

    private static function fromDecimal(BigDecimal $amount): self
    {
        if ($amount->abs()->isGreaterThan(self::MAX_ABSOLUTE)) {
            throw new InvalidArgumentException('Montant hors de la capacité d\'une colonne DECIMAL(15,3).');
        }

        return new self($amount->toScale(self::SCALE, RoundingMode::Unnecessary));
    }
}
