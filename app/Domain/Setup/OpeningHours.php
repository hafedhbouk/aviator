<?php

declare(strict_types=1);

namespace App\Domain\Setup;

use App\Domain\Setup\Exceptions\SetupException;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Horaires d'ouverture d'un lieu de prise en charge ou de restitution (A-02).
 *
 * Forme stockée (colonne JSON agency_locations.opening_hours) : un tableau par jour
 * de la semaine (monday … sunday), chacun une liste de plages {open, close} au
 * format HH:MM, triées, sans chevauchement. Un jour sans plage est fermé ; null
 * signifie « horaires non renseignés ». Les heures s'entendent en heure de Tunis
 * (fuseau de l'application), l'ouverture incluse et la fermeture exclue.
 */
final readonly class OpeningHours
{
    public const DAYS = [
        'monday' => 'lundi',
        'tuesday' => 'mardi',
        'wednesday' => 'mercredi',
        'thursday' => 'jeudi',
        'friday' => 'vendredi',
        'saturday' => 'samedi',
        'sunday' => 'dimanche',
    ];

    /** Plages par jour au plus (journée continue ou coupée, avec une marge). */
    public const MAX_RANGES_PER_DAY = 3;

    /**
     * @param  array<string, list<array{open: string, close: string}>>|null  $days
     */
    private function __construct(private ?array $days) {}

    /**
     * Valide et normalise une saisie (formulaire ou colonne JSON).
     *
     * @throws SetupException horaires invalides, avec le jour en cause
     */
    public static function fromInput(mixed $input): self
    {
        if ($input === null) {
            return new self(null);
        }

        if (! is_array($input)) {
            throw SetupException::invalidOpeningHours('horaires', 'format inattendu');
        }

        $unknown = array_diff(array_keys($input), array_keys(self::DAYS));

        if ($unknown !== []) {
            throw SetupException::invalidOpeningHours('horaires', 'jour inconnu');
        }

        $days = [];

        foreach (self::DAYS as $day => $label) {
            $days[$day] = self::normalizeDay($input[$day] ?? [], $label);
        }

        return new self($days);
    }

    public function isDefined(): bool
    {
        return $this->days !== null;
    }

    /**
     * Le lieu est-il ouvert à cet instant (heure de Tunis) ? Faux si les horaires
     * ne sont pas renseignés.
     */
    public function isOpenAt(DateTimeInterface $at): bool
    {
        if ($this->days === null) {
            return false;
        }

        $local = CarbonImmutable::instance($at)->setTimezone((string) config('app.timezone'));
        $time = $local->format('H:i');

        foreach ($this->days[strtolower($local->englishDayOfWeek)] as $range) {
            if ($time >= $range['open'] && $time < $range['close']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, list<array{open: string, close: string}>>|null
     */
    public function toArray(): ?array
    {
        return $this->days;
    }

    /**
     * Résumé lisible, jours consécutifs identiques regroupés :
     * « lundi – vendredi : 08:00 – 12:00, 14:00 – 18:00 ; samedi : 08:00 – 12:00 ; dimanche : fermé ».
     */
    public function summary(): string
    {
        if ($this->days === null) {
            return 'Horaires non renseignés';
        }

        $groups = [];

        foreach ($this->days as $day => $ranges) {
            $text = $ranges === []
                ? 'fermé'
                : implode(', ', array_map(fn (array $r): string => "{$r['open']} – {$r['close']}", $ranges));

            $last = array_key_last($groups);

            if ($last !== null && $groups[$last]['text'] === $text) {
                $groups[$last]['to'] = self::DAYS[$day];
            } else {
                $groups[] = ['from' => self::DAYS[$day], 'to' => null, 'text' => $text];
            }
        }

        return implode(' ; ', array_map(
            fn (array $g): string => ($g['to'] === null ? $g['from'] : "{$g['from']} – {$g['to']}").' : '.$g['text'],
            $groups,
        ));
    }

    /**
     * @return list<array{open: string, close: string}>
     */
    private static function normalizeDay(mixed $ranges, string $label): array
    {
        if ($ranges === null) {
            return [];
        }

        if (! is_array($ranges)) {
            throw SetupException::invalidOpeningHours($label, 'format inattendu');
        }

        $normalized = [];

        foreach ($ranges as $range) {
            if (! is_array($range)) {
                throw SetupException::invalidOpeningHours($label, 'format inattendu');
            }

            $open = self::time($range['open'] ?? null, $label);
            $close = self::time($range['close'] ?? null, $label);

            if ($open >= $close) {
                throw SetupException::invalidOpeningHours($label, "l'ouverture ({$open}) doit précéder la fermeture ({$close})");
            }

            $normalized[] = ['open' => $open, 'close' => $close];
        }

        if (count($normalized) > self::MAX_RANGES_PER_DAY) {
            throw SetupException::invalidOpeningHours($label, self::MAX_RANGES_PER_DAY.' plages au plus');
        }

        usort($normalized, fn (array $a, array $b): int => strcmp($a['open'], $b['open']));

        for ($i = 1, $n = count($normalized); $i < $n; $i++) {
            if ($normalized[$i]['open'] < $normalized[$i - 1]['close']) {
                throw SetupException::invalidOpeningHours($label, 'deux plages se chevauchent');
            }
        }

        return $normalized;
    }

    /**
     * Heure HH:MM (les secondes éventuelles du sélecteur sont ignorées si nulles).
     */
    private static function time(mixed $value, string $label): string
    {
        if (! is_string($value) || preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:00)?$/', $value) !== 1) {
            throw SetupException::invalidOpeningHours($label, 'heure manquante ou invalide (HH:MM)');
        }

        return substr($value, 0, 5);
    }
}
