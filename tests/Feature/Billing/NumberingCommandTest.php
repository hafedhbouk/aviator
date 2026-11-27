<?php

use App\Models\FiscalReference;
use App\Models\SequenceSeries;
use App\Models\SequenceSeriesChange;
use App\Support\Sequences\SequenceAllocator;
use App\Support\Sequences\Series;
use Database\Seeders\FiscalReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

/*
 * Commande de réglage du format (D27-3), réservée au terminal.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(FiscalReferenceSeeder::class);
});

it('affiche le réglage et les verrous sans rien changer', function () {
    $this->artisan('aviator:numbering', ['series' => 'invoice'])
        ->expectsOutputToContain('AVI-000001')
        ->expectsOutputToContain('verrouillé')
        ->assertSuccessful();

    expect(SequenceSeriesChange::query()->count())->toBe(0);
});

it('journalise le changement (avant, après, qui, quand) et l\'applique aux prochains numéros seulement', function () {
    $allocator = app(SequenceAllocator::class);
    $issued = $allocator->nextInSeries(Series::Invoice);

    $this->artisan('aviator:numbering', ['series' => 'invoice', '--prefix' => 'FAC', '--by' => 'Faouzi Bannour, directeur général'])
        ->expectsOutputToContain('AVI-000001 → FAC-000001')
        ->assertSuccessful();

    $change = SequenceSeriesChange::query()->sole();
    $audit = Activity::query()->where('event', 'numbering_format_changed')->sole();

    expect($issued)->toBe('AVI-000001')
        ->and($allocator->nextInSeries(Series::Invoice))->toBe('FAC-000002')
        ->and($change->before['prefix'])->toBe('AVI')
        ->and($change->after['prefix'])->toBe('FAC')
        ->and($change->decided_by)->toBe('Faouzi Bannour, directeur général')
        ->and($change->operator)->not->toBeNull()
        ->and($change->created_at)->not->toBeNull()
        ->and($audit->log_name)->toBe('audit')
        ->and($audit->properties['decided_by'])->toBe('Faouzi Bannour, directeur général');
});

it('refuse de changer le format en production sans --force', function () {
    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan('aviator:numbering', ['series' => 'invoice', '--prefix' => 'FAC', '--by' => 'Direction'])
        ->expectsOutputToContain('--force')
        ->assertFailed();

    expect(SequenceSeries::ensure(Series::Invoice)->prefix)->toBe('AVI');

    $this->artisan('aviator:numbering', ['series' => 'invoice', '--prefix' => 'FAC', '--by' => 'Direction', '--force' => true])
        ->assertSuccessful();

    expect(SequenceSeries::ensure(Series::Invoice)->prefix)->toBe('FAC');
});

it('refuse un format sans {SEQUENCE}, un préfixe de facture remplacé par AV et le format avec l\'année verrouillé', function (array $options, string $message) {
    $this->artisan('aviator:numbering', ['series' => 'invoice', '--by' => 'Direction', ...$options])
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(SequenceSeriesChange::query()->count())->toBe(0);
})->with([
    'sans {SEQUENCE}' => [['--template' => '{PREFIX}-X'], '{SEQUENCE} une fois exactement'],
    'préfixe AV' => [['--prefix' => 'AV'], 'série « Avoirs »'],
    'année verrouillée' => [['--template' => '{PREFIX}-{YEAR}-{SEQUENCE}'], 'confirmation écrite du cabinet'],
    'remise à zéro invalide' => [['--reset' => 'month'], 'none ou year'],
    'série inconnue' => [['series' => 'quote'], 'Série inconnue'],
]);

it('ouvre le format avec l\'année avec une version ultérieure du référentiel et l\'affiche', function () {
    FiscalReference::query()->create([
        'version' => '1.1',
        'title' => 'Référentiel v1.1',
        'file_name' => 'referentiel_v1.1.md',
        'sha256' => str_repeat('b', 64),
        'validation_source' => 'Confirmation écrite du cabinet',
        'validated_at' => '2026-12-10',
    ]);

    $this->artisan('aviator:numbering', ['series' => 'invoice', '--template' => '{PREFIX}-{YEAR}-{SEQUENCE}', '--confirmation' => '1.1', '--by' => 'Direction'])
        ->expectsOutputToContain('ouvert par le référentiel v1.1')
        ->assertSuccessful();
});
