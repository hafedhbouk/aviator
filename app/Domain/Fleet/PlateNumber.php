<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

/**
 * Forme canonique d'une immatriculation, pour l'unicité (B-01) : majuscules, sans
 * tiret ni point, un espace entre chaque groupe de chiffres et de lettres.
 * « 123tu4567 », « 123 TU 4567 » et « 123-TU-4567 » donnent « 123 TU 4567 ».
 * Aucun format imposé : le format tunisien n'est pas le seul en circulation.
 */
final class PlateNumber
{
    public static function canonical(string $value): string
    {
        $compact = preg_replace('/[\s.\-]+/u', '', mb_strtoupper(trim($value))) ?? '';

        return trim(preg_replace('/(?<=\d)(?=\D)|(?<=\D)(?=\d)/u', ' ', $compact) ?? '');
    }
}
