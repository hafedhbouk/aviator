<?php

declare(strict_types=1);

namespace App\Support\Sequences;

use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Format d'une série de numérotation (D27-1) : un modèle (`{PREFIX}`, `{YEAR}`,
 * `{SEQUENCE}`), indépendant de la période du compteur.
 *
 * - `{PREFIX}` : le préfixe de la série, une fois exactement.
 * - `{SEQUENCE}` : le numéro, complété par des zéros jusqu'à la longueur, une fois exactement.
 * - `{YEAR}` : l'année d'émission à l'heure de Tunis, une fois au plus.
 * - Remise à zéro annuelle : seulement si le modèle contient `{YEAR}`, sinon un numéro
 *   déjà attribué reviendrait l'année suivante.
 *
 * Exemple : new SeriesFormat('AVI', '{PREFIX}-{SEQUENCE}', 6, SequencePeriod::None) → AVI-000126.
 */
final readonly class SeriesFormat
{
    public const DEFAULT_TEMPLATE = '{PREFIX}-{SEQUENCE}';

    private const TOKENS = ['{PREFIX}', '{YEAR}', '{SEQUENCE}'];

    /** Symbole interne : un chiffre (deux caractères, jamais un littéral du modèle). */
    private const DIGIT = '#d';

    /** Symbole interne : des chiffres en nombre quelconque. */
    private const DIGITS = '#*';

    public function __construct(
        public string $prefix,
        public string $template,
        public int $padding,
        public SequencePeriod $resetPeriod,
    ) {
        if (preg_match('/^[A-Z0-9][A-Z0-9-]{0,23}$/', $prefix) !== 1) {
            throw new InvalidArgumentException("Préfixe invalide : « {$prefix} » (majuscules, chiffres et tirets, 24 caractères au plus).");
        }

        if (strlen($template) > 64 || preg_match('/^(?:\{PREFIX\}|\{YEAR\}|\{SEQUENCE\}|[A-Z0-9\/-])+$/', $template) !== 1) {
            throw new InvalidArgumentException("Modèle invalide : « {$template} ». Éléments permis : {PREFIX}, {YEAR}, {SEQUENCE}, majuscules, chiffres, « - » et « / » (64 caractères au plus).");
        }

        if (substr_count($template, '{SEQUENCE}') !== 1) {
            throw new InvalidArgumentException('Le modèle doit contenir {SEQUENCE} une fois exactement.');
        }

        if (substr_count($template, '{PREFIX}') !== 1) {
            throw new InvalidArgumentException('Le modèle doit contenir {PREFIX} une fois exactement.');
        }

        if (substr_count($template, '{YEAR}') > 1) {
            throw new InvalidArgumentException('Le modèle contient {YEAR} une fois au plus.');
        }

        if ($padding < 1 || $padding > 12) {
            throw new InvalidArgumentException('La longueur du numéro doit être comprise entre 1 et 12.');
        }

        if ($resetPeriod === SequencePeriod::Month) {
            throw new InvalidArgumentException('Une série se remet à zéro chaque année au plus.');
        }

        if ($resetPeriod === SequencePeriod::Year && ! $this->hasYear()) {
            throw new InvalidArgumentException('Une remise à zéro annuelle exige {YEAR} dans le modèle : sans l\'année, un numéro déjà attribué reviendrait.');
        }
    }

    public function hasYear(): bool
    {
        return str_contains($this->template, '{YEAR}');
    }

    public function resetsYearly(): bool
    {
        return $this->resetPeriod === SequencePeriod::Year;
    }

    public function format(int $value, CarbonInterface $date): string
    {
        return strtr($this->template, [
            '{PREFIX}' => $this->prefix,
            '{YEAR}' => (string) SequencePeriod::Year->labelFor($date),
            '{SEQUENCE}' => str_pad((string) $value, $this->padding, '0', STR_PAD_LEFT),
        ]);
    }

    /**
     * Clé du compteur de la série : une clé unique si la série est continue, une par
     * année (heure de Tunis) si elle est remise à zéro chaque année.
     */
    public function counterKey(Series $series, CarbonInterface $date): string
    {
        $base = 'series:'.$series->value;

        return $this->resetsYearly() ? $base.':'.SequencePeriod::Year->labelFor($date) : $base;
    }

    /**
     * Plus grand numéro qui tient dans la longueur choisie.
     */
    public function maxValue(): int
    {
        return (int) str_repeat('9', $this->padding);
    }

    /**
     * Modèle avec le préfixe remplacé : deux formats de même squelette ne diffèrent
     * que par la longueur du numéro.
     */
    public function skeleton(): string
    {
        return str_replace('{PREFIX}', $this->prefix, $this->template);
    }

    /**
     * Vrai si une même chaîne peut être produite par les deux formats, quels que soient
     * l'année et le numéro (intersection des deux langages, calcul exact).
     */
    public function overlaps(self $other): bool
    {
        $a = $this->symbols();
        $b = $other->symbols();
        $alphabet = array_values(array_unique(array_merge(
            str_split('0123456789'),
            array_filter($a, fn (string $s): bool => strlen($s) === 1),
            array_filter($b, fn (string $s): bool => strlen($s) === 1),
        )));

        $start = [self::closure($a, [0]), self::closure($b, [0])];
        $queue = [$start];
        $seen = [self::stateKey($start) => true];

        while ($queue !== []) {
            [$left, $right] = array_shift($queue);

            if (in_array(count($a), $left, true) && in_array(count($b), $right, true)) {
                return true;
            }

            foreach ($alphabet as $symbol) {
                $nextLeft = self::closure($a, self::step($a, $left, $symbol));
                $nextRight = self::closure($b, self::step($b, $right, $symbol));

                if ($nextLeft === [] || $nextRight === []) {
                    continue;
                }

                $state = [$nextLeft, $nextRight];
                $key = self::stateKey($state);

                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $queue[] = $state;
                }
            }
        }

        return false;
    }

    /**
     * @return array{prefix: string, template: string, padding: int, reset_period: string, example: string}
     */
    public function toArray(?CarbonInterface $date = null): array
    {
        return [
            'prefix' => $this->prefix,
            'template' => $this->template,
            'padding' => $this->padding,
            'reset_period' => $this->resetPeriod->value,
            'example' => $this->format(1, $date ?? now()),
        ];
    }

    /**
     * Le format sous forme de symboles : un caractère littéral, DIGIT (un chiffre) ou
     * DIGITS (des chiffres en nombre quelconque, après les chiffres de la longueur).
     *
     * @return list<string>
     */
    private function symbols(): array
    {
        $symbols = [];

        foreach (preg_split('/(\{PREFIX\}|\{YEAR\}|\{SEQUENCE\})/', $this->template, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            $chunk = match ($part) {
                '{PREFIX}' => str_split($this->prefix),
                '{YEAR}' => array_fill(0, 4, self::DIGIT),
                '{SEQUENCE}' => [...array_fill(0, $this->padding, self::DIGIT), self::DIGITS],
                default => in_array($part, self::TOKENS, true) ? [] : str_split($part),
            };
            array_push($symbols, ...$chunk);
        }

        return $symbols;
    }

    /**
     * @param  list<string>  $symbols
     * @param  list<int>  $positions
     * @return list<int>
     */
    private static function closure(array $symbols, array $positions): array
    {
        $result = $positions;

        foreach ($positions as $position) {
            $current = $position;

            while (($symbols[$current] ?? null) === self::DIGITS) {
                $current++;
                $result[] = $current;
            }
        }

        $result = array_values(array_unique($result));
        sort($result);

        return $result;
    }

    /**
     * @param  list<string>  $symbols
     * @param  list<int>  $positions
     * @return list<int>
     */
    private static function step(array $symbols, array $positions, string $symbol): array
    {
        $isDigit = ctype_digit($symbol);
        $next = [];

        foreach ($positions as $position) {
            $expected = $symbols[$position] ?? null;

            if ($expected === self::DIGITS && $isDigit) {
                $next[] = $position;
            } elseif (($expected === self::DIGIT && $isDigit) || $expected === $symbol) {
                $next[] = $position + 1;
            }
        }

        return $next;
    }

    /**
     * @param  array{0: list<int>, 1: list<int>}  $state
     */
    private static function stateKey(array $state): string
    {
        return implode(',', $state[0]).'|'.implode(',', $state[1]);
    }
}
