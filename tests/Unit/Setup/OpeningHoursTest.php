<?php

use App\Domain\Setup\Exceptions\SetupException;
use App\Domain\Setup\OpeningHours;
use Carbon\CarbonImmutable;

/*
 * Horaires d'ouverture des lieux (A-02) : validation, normalisation, ouverture à un
 * instant donné en heure de Tunis, résumé lisible.
 */

function weekHours(array $overrides = []): array
{
    $split = [['open' => '08:00', 'close' => '12:00'], ['open' => '14:00', 'close' => '18:00']];

    return array_merge([
        'monday' => $split, 'tuesday' => $split, 'wednesday' => $split, 'thursday' => $split, 'friday' => $split,
        'saturday' => [['open' => '08:00', 'close' => '12:00']],
        'sunday' => [],
    ], $overrides);
}

it('normalise les plages : HH:MM, triées, jours absents fermés', function () {
    $hours = OpeningHours::fromInput([
        'monday' => [['open' => '14:00:00', 'close' => '18:00:00'], ['open' => '08:00', 'close' => '12:00']],
    ]);

    expect($hours->toArray())->toBe([
        'monday' => [['open' => '08:00', 'close' => '12:00'], ['open' => '14:00', 'close' => '18:00']],
        'tuesday' => [], 'wednesday' => [], 'thursday' => [], 'friday' => [], 'saturday' => [], 'sunday' => [],
    ]);
});

it('distingue horaires non renseignés et lieu fermé', function () {
    expect(OpeningHours::fromInput(null)->isDefined())->toBeFalse()
        ->and(OpeningHours::fromInput(null)->summary())->toBe('Horaires non renseignés')
        ->and(OpeningHours::fromInput([])->isDefined())->toBeTrue()
        ->and(OpeningHours::fromInput([])->summary())->toBe('lundi – dimanche : fermé');
});

it('refuse les horaires incohérents, en citant le jour', function (array $input, string $message) {
    expect(fn () => OpeningHours::fromInput($input))->toThrow(SetupException::class, $message);
})->with([
    'fermeture avant ouverture' => [['monday' => [['open' => '18:00', 'close' => '08:00']]], 'lundi'],
    'ouverture = fermeture' => [['tuesday' => [['open' => '08:00', 'close' => '08:00']]], 'mardi'],
    'chevauchement' => [['friday' => [['open' => '08:00', 'close' => '13:00'], ['open' => '12:00', 'close' => '18:00']]], 'se chevauchent'],
    'heure invalide' => [['sunday' => [['open' => '25:00', 'close' => '26:00']]], 'dimanche'],
    'heure manquante' => [['saturday' => [['open' => '08:00']]], 'samedi'],
    'jour inconnu' => [['funday' => []], 'jour inconnu'],
    'trop de plages' => [['monday' => [
        ['open' => '06:00', 'close' => '07:00'], ['open' => '08:00', 'close' => '09:00'],
        ['open' => '10:00', 'close' => '11:00'], ['open' => '12:00', 'close' => '13:00'],
    ]], '3 plages au plus'],
]);

it('accepte deux plages qui se touchent', function () {
    $hours = OpeningHours::fromInput(['monday' => [['open' => '08:00', 'close' => '12:00'], ['open' => '12:00', 'close' => '18:00']]]);

    expect($hours->isOpenAt(CarbonImmutable::parse('2026-11-23 12:00', 'Africa/Tunis')))->toBeTrue();
});

it('dit si le lieu est ouvert à un instant, en heure de Tunis, ouverture incluse et fermeture exclue', function (string $at, string $tz, bool $open) {
    expect(OpeningHours::fromInput(weekHours())->isOpenAt(CarbonImmutable::parse($at, $tz)))->toBe($open);
})->with([
    'lundi 08:00, ouverture' => ['2026-11-23 08:00', 'Africa/Tunis', true],
    'lundi 07:59' => ['2026-11-23 07:59', 'Africa/Tunis', false],
    'lundi 12:00, fermeture du matin' => ['2026-11-23 12:00', 'Africa/Tunis', false],
    'lundi 13:00, pause' => ['2026-11-23 13:00', 'Africa/Tunis', false],
    'lundi 17:59' => ['2026-11-23 17:59', 'Africa/Tunis', true],
    'samedi 11:00' => ['2026-11-28 11:00', 'Africa/Tunis', true],
    'samedi 15:00' => ['2026-11-28 15:00', 'Africa/Tunis', false],
    'dimanche 10:00, fermé' => ['2026-11-29 10:00', 'Africa/Tunis', false],
    'lundi 07:30 UTC = 08:30 à Tunis' => ['2026-11-23 07:30', 'UTC', true],
    'dimanche 23:30 UTC = lundi 00:30 à Tunis' => ['2026-11-22 23:30', 'UTC', false],
]);

it('n\'est jamais ouvert quand les horaires ne sont pas renseignés', function () {
    expect(OpeningHours::fromInput(null)->isOpenAt(CarbonImmutable::parse('2026-11-23 10:00', 'Africa/Tunis')))->toBeFalse();
});

it('résume les horaires en regroupant les jours identiques', function () {
    expect(OpeningHours::fromInput(weekHours())->summary())
        ->toBe('lundi – vendredi : 08:00 – 12:00, 14:00 – 18:00 ; samedi : 08:00 – 12:00 ; dimanche : fermé');
});
