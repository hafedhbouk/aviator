<?php

declare(strict_types=1);

namespace App\Support\Sequences;

use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Format d'une numérotation : préfixe, période et longueur du numéro.
 * Exemple : new SequenceDefinition('RES', SequencePeriod::Year, 5) → RES-2026-00001.
 *
 * Les numéros de facture et d'avoir n'ont pas de définition ici : leur format et
 * leur période viendront des règles fiscales validées (Q11, Q30, Q16).
 */
final readonly class SequenceDefinition
{
    public function __construct(
        public string $prefix,
        public SequencePeriod $period,
        public int $length,
        public string $separator = '-',
    ) {
        if (preg_match('/^[A-Z0-9][A-Z0-9-]{0,23}$/', $prefix) !== 1) {
            throw new InvalidArgumentException("Préfixe de séquence invalide : « {$prefix} » (majuscules, chiffres et tirets, 24 caractères au plus).");
        }

        if ($length < 1 || $length > 12) {
            throw new InvalidArgumentException('La longueur du numéro doit être comprise entre 1 et 12.');
        }
    }

    /**
     * Clé de la ligne de compteur : un compteur par préfixe et par période.
     */
    public function keyFor(CarbonInterface $date): string
    {
        $period = $this->period->labelFor($date);

        return $period === null ? $this->prefix : $this->prefix.':'.$period;
    }

    public function format(int $value, CarbonInterface $date): string
    {
        $period = $this->period->labelFor($date);
        $number = str_pad((string) $value, $this->length, '0', STR_PAD_LEFT);

        return implode($this->separator, array_filter([$this->prefix, $period, $number], fn (?string $part): bool => $part !== null));
    }

    /**
     * Plus grand numéro qui tient dans la longueur choisie.
     */
    public function maxValue(): int
    {
        return (int) str_repeat('9', $this->length);
    }
}
