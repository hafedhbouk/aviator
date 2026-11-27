<?php

use App\Domain\Billing\Exceptions\NumberingException;
use App\Domain\Billing\Numbering\ChangeSeriesFormat;
use App\Models\FiscalReference;
use App\Models\Sequence;
use App\Models\SequenceSeries;
use App\Models\SequenceSeriesChange;
use App\Support\Sequences\SequenceAllocator;
use App\Support\Sequences\SequenceOverflowException;
use App\Support\Sequences\SequencePeriod;
use App\Support\Sequences\Series;
use App\Support\Sequences\SeriesFormat;
use Carbon\CarbonImmutable;
use Database\Seeders\FiscalReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
 * Numérotation par série (D27, tests de référence de l'annexe B du plan de la
 * phase 6) : format par modèle, compteur attaché à la série, passage d'année, verrous
 * du format avec l'année et de la remise à zéro.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(FiscalReferenceSeeder::class);
});

function issue(Series $series, string $at): string
{
    return app(SequenceAllocator::class)->nextInSeries($series, CarbonImmutable::parse($at, 'Africa/Tunis'));
}

function setCounter(Series $series, int $value, ?string $year = null): void
{
    $key = 'series:'.$series->value.($year === null ? '' : ':'.$year);
    app(SequenceAllocator::class)->ensureCounterExists($key);
    Sequence::query()->where('key', $key)->update(['last_value' => $value]);
}

/**
 * @param  array<string, mixed>  $changes
 */
function changeFormat(Series $series, array $changes, ?string $confirmation = null, string $at = '2026-12-15 10:00'): ?SequenceSeriesChange
{
    return app(ChangeSeriesFormat::class)->handle(
        $series,
        $changes['prefix'] ?? null,
        $changes['template'] ?? null,
        $changes['padding'] ?? null,
        $changes['reset'] ?? null,
        'Faouzi Bannour, directeur général',
        $confirmation,
        'test',
        'poste',
        CarbonImmutable::parse($at, 'Africa/Tunis'),
    );
}

/** Nouvelle version du référentiel, confirmée par le cabinet après la v1.0. */
function laterReference(string $version = '1.1'): FiscalReference
{
    return FiscalReference::query()->create([
        'version' => $version,
        'title' => "Référentiel v{$version}",
        'file_name' => "referentiel_v{$version}.md",
        'sha256' => str_repeat('a', 64),
        'validation_source' => 'Confirmation écrite du cabinet',
        'validated_at' => '2026-12-10',
    ]);
}

it('applique le réglage initial du référentiel validé : AVI-, AV- et CAU-AAAA-', function () {
    expect(issue(Series::Invoice, '2026-12-01 10:00'))->toBe('AVI-000001')
        ->and(issue(Series::CreditNote, '2026-12-01 10:00'))->toBe('AV-000001')
        ->and(issue(Series::DepositReceipt, '2026-12-01 10:00'))->toBe('CAU-2026-00001')
        ->and(SequenceSeries::ensure(Series::Invoice)->format())->toEqual(new SeriesFormat('AVI', '{PREFIX}-{SEQUENCE}', 6, SequencePeriod::None));
});

it('tient un compteur par série : un avoir n\'avance pas le compteur des factures', function () {
    issue(Series::Invoice, '2026-12-01 10:00');
    issue(Series::CreditNote, '2026-12-01 10:01');
    issue(Series::CreditNote, '2026-12-01 10:02');

    expect(issue(Series::Invoice, '2026-12-01 10:03'))->toBe('AVI-000002')
        ->and(issue(Series::CreditNote, '2026-12-01 10:04'))->toBe('AV-000003');
});

it('ne remet pas le compteur à zéro au passage d\'année avec le format initial', function (Series $series, string $prefix) {
    // Compteurs de 2027 créés d'avance, comme avant la transaction d'un document :
    // ce test tient tout dans une seule transaction (RefreshDatabase).
    app(SequenceAllocator::class)->prepareSeries($series, CarbonImmutable::parse('2027-01-01 00:00', 'Africa/Tunis'));
    setCounter($series, 124);

    expect(issue($series, '2026-12-31 23:59'))->toBe("{$prefix}-000125")
        ->and(issue($series, '2027-01-01 00:00'))->toBe("{$prefix}-000126");
})->with([
    'factures' => [Series::Invoice, 'AVI'],
    'avoirs' => [Series::CreditNote, 'AV'],
]);

it('remet à zéro chaque année les reçus de caution, document non fiscal (Q56)', function () {
    setCounter(Series::DepositReceipt, 41, '2026');

    expect(issue(Series::DepositReceipt, '2026-12-31 23:59'))->toBe('CAU-2026-00042')
        ->and(issue(Series::DepositReceipt, '2027-01-01 00:00'))->toBe('CAU-2027-00001');
});

it('refuse le format avec l\'année sans confirmation écrite du cabinet, et ne change rien', function (?string $confirmation, string $message) {
    expect(fn () => changeFormat(Series::Invoice, ['template' => '{PREFIX}-{YEAR}-{SEQUENCE}'], $confirmation))
        ->toThrow(NumberingException::class, $message);

    expect(SequenceSeries::ensure(Series::Invoice)->template)->toBe('{PREFIX}-{SEQUENCE}')
        ->and(SequenceSeriesChange::query()->count())->toBe(0)
        ->and(issue(Series::Invoice, '2026-12-15 10:00'))->toBe('AVI-000001');
})->with([
    'sans confirmation' => [null, 'confirmation écrite du cabinet'],
    'avec le référentiel v1.0' => ['1.0', 'pas postérieure au référentiel v1.0'],
    'version inconnue' => ['9.9', 'aucune version « 9.9 »'],
]);

it('date le format avec l\'année, une fois déverrouillé, à l\'heure de Tunis', function () {
    laterReference();
    setCounter(Series::Invoice, 124);

    $change = changeFormat(Series::Invoice, ['template' => '{PREFIX}-{YEAR}-{SEQUENCE}'], '1.1');

    expect($change?->confirmation_reference_id)->toBe(FiscalReference::query()->where('version', '1.1')->value('id'))
        ->and(issue(Series::Invoice, '2026-12-31 23:59'))->toBe('AVI-2026-000125')
        ->and(issue(Series::Invoice, '2027-01-01 00:00'))->toBe('AVI-2027-000126')
        // 31/12/2026 à 23:30 UTC = 01/01/2027 à 00:30 à Tunis.
        ->and(app(SequenceAllocator::class)->nextInSeries(Series::Invoice, CarbonImmutable::parse('2026-12-31 23:30', 'UTC')))->toBe('AVI-2027-000127');
});

it('refuse la remise à zéro annuelle tant qu\'elle n\'est pas confirmée, même avec le format avec l\'année ouvert', function () {
    laterReference();
    changeFormat(Series::Invoice, ['template' => '{PREFIX}-{YEAR}-{SEQUENCE}'], '1.1');

    expect(fn () => changeFormat(Series::Invoice, ['reset' => SequencePeriod::Year]))
        ->toThrow(NumberingException::class, 'Remise à zéro annuelle refusée')
        ->and(SequenceSeries::ensure(Series::Invoice)->reset_period)->toBe(SequencePeriod::None);
});

it('reprend le compteur dans l\'année en cours quand la remise à zéro est confirmée, puis repart à 1 l\'année suivante', function () {
    laterReference();
    setCounter(Series::Invoice, 124);

    changeFormat(Series::Invoice, ['template' => '{PREFIX}-{YEAR}-{SEQUENCE}', 'reset' => SequencePeriod::Year], '1.1');

    expect(issue(Series::Invoice, '2026-12-31 23:59'))->toBe('AVI-2026-000125')
        ->and(issue(Series::Invoice, '2027-01-01 00:00'))->toBe('AVI-2027-000001');
});

it('refuse une remise à zéro sans l\'année dans le modèle, même confirmée', function () {
    laterReference();

    expect(fn () => changeFormat(Series::Invoice, ['reset' => SequencePeriod::Year], '1.1'))
        ->toThrow(NumberingException::class, 'exige {YEAR}');
});

it('ne remet pas le compteur à zéro quand le préfixe change ; les numéros attribués restent', function () {
    $first = issue(Series::Invoice, '2026-12-01 10:00');
    issue(Series::Invoice, '2026-12-01 10:01');

    changeFormat(Series::Invoice, ['prefix' => 'FAC']);

    expect($first)->toBe('AVI-000001')
        ->and(issue(Series::Invoice, '2026-12-15 10:00'))->toBe('FAC-000003')
        ->and(Sequence::query()->where('key', 'series:invoice')->value('last_value'))->toBe(3);
});

it('refuse un format qui peut reproduire un numéro d\'une autre série ou d\'un ancien format', function (Series $series, array $changes, string $message) {
    changeFormat(Series::Invoice, ['prefix' => 'FAC']);

    expect(fn () => changeFormat($series, $changes))->toThrow(NumberingException::class, $message);
})->with([
    'préfixe des factures remplacé par AV' => [Series::Invoice, ['prefix' => 'AV'], 'série « Avoirs »'],
    'préfixe des avoirs remplacé par AVI, ancien préfixe des factures' => [Series::CreditNote, ['prefix' => 'AVI'], 'série « Factures »'],
    'avoirs au format des reçus de caution' => [Series::CreditNote, ['prefix' => 'CAU-2026'], 'série « Reçus de caution »'],
    'reçus de caution sans l\'année, au format des factures' => [Series::DepositReceipt, ['prefix' => 'FAC', 'template' => '{PREFIX}-{SEQUENCE}', 'reset' => SequencePeriod::None], 'série « Factures »'],
    'ancien format des factures recouvert par un autre squelette' => [Series::Invoice, ['prefix' => 'AVI', 'template' => '{PREFIX}-1{SEQUENCE}'], 'ancien format'],
]);

it('accepte le retour à un ancien préfixe de la même série et un changement de longueur', function () {
    changeFormat(Series::Invoice, ['prefix' => 'FAC']);
    changeFormat(Series::Invoice, ['prefix' => 'AVI', 'padding' => 7]);

    expect(issue(Series::Invoice, '2026-12-15 10:00'))->toBe('AVI-0000001');
});

it('refuse un format sans {SEQUENCE}, une longueur trop courte et une décision anonyme', function () {
    expect(fn () => changeFormat(Series::Invoice, ['template' => '{PREFIX}-X']))->toThrow(NumberingException::class, '{SEQUENCE} une fois exactement');

    setCounter(Series::Invoice, 999);

    expect(fn () => changeFormat(Series::Invoice, ['padding' => 3]))->toThrow(NumberingException::class, 'le prochain numéro (1000)')
        ->and(fn () => app(ChangeSeriesFormat::class)->handle(Series::Invoice, 'FAC', null, null, null, '  '))->toThrow(NumberingException::class, 'obligatoire')
        ->and(SequenceSeriesChange::query()->count())->toBe(0);
});

it('ne consomme aucun numéro quand la transaction du document échoue', function () {
    issue(Series::Invoice, '2026-12-01 10:00');

    try {
        DB::transaction(function () {
            issue(Series::Invoice, '2026-12-01 10:01');
            throw new RuntimeException('Validation refusée');
        });
    } catch (RuntimeException) {
    }

    expect(issue(Series::Invoice, '2026-12-01 10:02'))->toBe('AVI-000002');
});

it('n\'allonge jamais le numéro en silence', function () {
    setCounter(Series::Invoice, 999_999);

    expect(fn () => issue(Series::Invoice, '2026-12-01 10:00'))->toThrow(SequenceOverflowException::class);
});

it('calcule exactement le recouvrement de deux formats', function (string $a, string $b, bool $expected) {
    $format = function (string $spec): SeriesFormat {
        [$prefix, $template, $padding] = explode('|', $spec);

        return new SeriesFormat($prefix, $template, (int) $padding, SequencePeriod::None);
    };

    expect($format($a)->overlaps($format($b)))->toBe($expected)
        ->and($format($b)->overlaps($format($a)))->toBe($expected);
})->with([
    ['AVI|{PREFIX}-{SEQUENCE}|6', 'AV|{PREFIX}-{SEQUENCE}|6', false],
    ['AVI|{PREFIX}-{SEQUENCE}|6', 'AVI|{PREFIX}-{SEQUENCE}|7', true],
    ['AVI|{PREFIX}-{SEQUENCE}|6', 'AVI|{PREFIX}-{YEAR}-{SEQUENCE}|6', false],
    ['AVI|{PREFIX}-{SEQUENCE}|6', 'AVI|{PREFIX}-{YEAR}{SEQUENCE}|2', true],
    ['AV|{PREFIX}-{SEQUENCE}|6', 'A|{PREFIX}V-{SEQUENCE}|3', true],
    ['DD|{PREFIX}{SEQUENCE}|2', 'D|{PREFIX}{SEQUENCE}|3', false],
    ['D1|{PREFIX}{SEQUENCE}|2', 'D|{PREFIX}{SEQUENCE}|3', true],
]);
