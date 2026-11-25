<?php

declare(strict_types=1);

namespace App\Support\Text;

use Illuminate\Support\Str;

/**
 * Normalisation des valeurs utilisées pour la recherche exacte (K-04) :
 * la même saisie, avec ou sans accents, espaces ou tirets, donne la même clé.
 */
final class SearchNormalizer
{
    /** Indicatif téléphonique par défaut (Tunisie). */
    private const DEFAULT_COUNTRY_CODE = '216';

    /** Longueur d'un numéro tunisien sans indicatif. */
    private const NATIONAL_NUMBER_LENGTH = 8;

    /**
     * Nom : minuscules, sans accents, espaces simples.
     */
    public static function name(string $value): string
    {
        $ascii = Str::ascii($value);
        $lower = mb_strtolower($ascii);
        $clean = (string) preg_replace('/[^a-z0-9 ]+/', ' ', $lower);

        return trim((string) preg_replace('/\s+/', ' ', $clean));
    }

    /**
     * Clé du nom complet, mots triés : « Ben Salah Mohamed » et « Mohamed Ben Salah »
     * donnent la même clé (D24 : nom complet, dans n'importe quel ordre). Aucune
     * recherche partielle : tous les mots doivent être présents, et seulement eux.
     */
    public static function nameKey(string $value): string
    {
        $tokens = array_filter(explode(' ', self::name($value)), fn (string $token): bool => $token !== '');
        sort($tokens);

        return implode(' ', $tokens);
    }

    /**
     * Identifiant (pièce d'identité, permis) : lettres et chiffres seulement, en majuscules.
     */
    public static function identifier(string $value): string
    {
        return (string) preg_replace('/[^A-Z0-9]+/', '', mb_strtoupper(Str::ascii($value)));
    }

    /**
     * Téléphone au format international « +216XXXXXXXX » (D24) : chiffres seuls,
     * indicatif tunisien ajouté à un numéro national de 8 chiffres, « 00 » remplacé par « + ».
     */
    public static function phone(string $value): string
    {
        $digits = (string) preg_replace('/\D+/', '', $value);

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === self::NATIONAL_NUMBER_LENGTH) {
            $digits = self::DEFAULT_COUNTRY_CODE.$digits;
        }

        return $digits === '' ? '' : '+'.$digits;
    }
}
