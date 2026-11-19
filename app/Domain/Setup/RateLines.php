<?php

declare(strict_types=1);

namespace App\Domain\Setup;

use App\Domain\Setup\Exceptions\SetupException;
use App\Support\Money\AmountInput;
use App\Support\Money\Money;
use InvalidArgumentException;

/**
 * Tranches de durée d'une grille tarifaire (A-04, Q33), validées et triées :
 *
 * - la première commence à 1 jour, chaque tranche commence le lendemain de la
 *   précédente (pas de trou, pas de chevauchement, pas de doublon) ;
 * - seule la dernière est ouverte (max_days vide) : toute durée a un prix ;
 * - prix journalier HT (Q34), strictement positif, au millime.
 *
 * Exemple : 1 – 6 jours, 7 – 29 jours, 30 jours et plus.
 */
final readonly class RateLines
{
    public const MAX_DAYS = 366;

    /**
     * @param  list<array{min_days: int, max_days: int|null, daily_amount: Money}>  $lines
     */
    private function __construct(public array $lines) {}

    /**
     * @throws SetupException tranches incohérentes, avec la raison
     */
    public static function fromInput(mixed $input): self
    {
        if (! is_array($input) || $input === []) {
            throw SetupException::invalidRateLines('au moins une tranche est nécessaire');
        }

        $lines = [];

        foreach ($input as $line) {
            if (! is_array($line)) {
                throw SetupException::invalidRateLines('format inattendu');
            }

            $lines[] = [
                'min_days' => self::days($line['min_days'] ?? null, 'durée minimale', required: true),
                'max_days' => self::days($line['max_days'] ?? null, 'durée maximale', required: false),
                'daily_amount' => self::amount($line['daily_amount'] ?? null),
            ];
        }

        usort($lines, fn (array $a, array $b): int => $a['min_days'] <=> $b['min_days']);

        $expected = 1;
        $last = array_key_last($lines);

        foreach ($lines as $index => $line) {
            if ($line['min_days'] !== $expected) {
                throw SetupException::invalidRateLines($expected === 1
                    ? 'la première tranche commence à 1 jour'
                    : "une tranche doit commencer à {$expected} jours (tranches continues, sans doublon)");
            }

            if ($line['max_days'] === null) {
                if ($index !== $last) {
                    throw SetupException::invalidRateLines('seule la dernière tranche peut être sans limite');
                }

                continue;
            }

            if ($line['max_days'] < $line['min_days']) {
                throw SetupException::invalidRateLines("la tranche qui commence à {$line['min_days']} jours finit avant de commencer");
            }

            if ($index === $last) {
                throw SetupException::invalidRateLines('la dernière tranche est sans limite (durée maximale vide)');
            }

            $expected = $line['max_days'] + 1;
        }

        return new self($lines);
    }

    /**
     * Prix journalier de la tranche qui contient cette durée (Q33 : il s'applique à
     * tous les jours de la location).
     */
    public function dailyAmountFor(int $days): ?Money
    {
        foreach ($this->lines as $line) {
            if ($days >= $line['min_days'] && ($line['max_days'] === null || $days <= $line['max_days'])) {
                return $line['daily_amount'];
            }
        }

        return null;
    }

    /**
     * Forme journalisée : [{min_days, max_days, daily_amount « 45.000 »}].
     *
     * @return list<array{min_days: int, max_days: int|null, daily_amount: string}>
     */
    public function toArray(): array
    {
        return array_map(fn (array $line): array => [
            'min_days' => $line['min_days'],
            'max_days' => $line['max_days'],
            'daily_amount' => $line['daily_amount']->toDecimalString(),
        ], $this->lines);
    }

    private static function days(mixed $value, string $label, bool $required): ?int
    {
        if ($value === null || $value === '') {
            if ($required) {
                throw SetupException::invalidRateLines("{$label} manquante");
            }

            return null;
        }

        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            $value = (int) trim($value);
        }

        if (! is_int($value) || $value < 1 || $value > self::MAX_DAYS) {
            throw SetupException::invalidRateLines("{$label} : nombre entier de jours, de 1 à ".self::MAX_DAYS);
        }

        return $value;
    }

    private static function amount(mixed $value): Money
    {
        try {
            $amount = AmountInput::parse($value);
        } catch (InvalidArgumentException $exception) {
            throw SetupException::invalidRateLines('prix journalier : '.$exception->getMessage());
        }

        if ($amount === null || ! $amount->isPositive()) {
            throw SetupException::invalidRateLines('prix journalier HT manquant ou nul');
        }

        return $amount;
    }
}
