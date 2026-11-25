<?php

use App\Domain\Availability\BlockRegistry;
use App\Domain\Availability\Exceptions\InvalidPeriod;
use App\Domain\Availability\Exceptions\VehicleUnavailable;
use App\Domain\Availability\Period;
use App\Domain\Availability\VehicleLock;
use App\Enums\VehicleStatus;
use App\Models\Agency;
use App\Models\Vehicle;
use App\Models\VehicleBlock;
use App\Models\VehicleCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Models\AgencyDocument;

uses(RefreshDatabase::class);

/*
 * Anti-chevauchement (RG-01, lot L9, plan §5.2) : bornes, délai de préparation,
 * minuit, fin de mois et d'année, libération, déplacement d'une occupation.
 * La concurrence (RG-02) est dans tests/Feature/Concurrency/VehicleBlockConcurrencyTest.php.
 */

beforeEach(function () {
    $this->agency = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->vehicle = Vehicle::factory()->create([
        'agency_id' => $this->agency->id,
        'vehicle_category_id' => VehicleCategory::factory()->create()->id,
    ])->refresh();
    $this->registry = app(BlockRegistry::class);
});

function blockSource(): AgencyDocument
{
    return AgencyDocument::query()->create(['agency_id' => test()->agency->id, 'title' => 'Source']);
}

function occupy(string $from, string $to, int $buffer = 60, ?AgencyDocument $source = null): VehicleBlock
{
    return test()->registry->occupy(test()->vehicle->id, Period::between($from, $to), $source ?? blockSource(), test()->agency->id, $buffer);
}

it('enregistre la fin délai de préparation compris', function () {
    $block = occupy('2026-12-03 09:00', '2026-12-05 10:00', 60);

    expect($block->starts_at->format('Y-m-d H:i'))->toBe('2026-12-03 09:00')
        ->and($block->ends_at->format('Y-m-d H:i'))->toBe('2026-12-05 11:00')
        ->and($block->buffer_minutes)->toBe(60)
        ->and($block->active)->toBeTrue();
});

it('accepte un départ à la fin du délai de préparation, le refuse une minute avant', function () {
    occupy('2026-12-03 09:00', '2026-12-05 10:00', 60);

    expect(fn () => occupy('2026-12-05 10:59', '2026-12-06 10:00'))->toThrow(VehicleUnavailable::class, 'vient d\'être réservé');

    $next = occupy('2026-12-05 11:00', '2026-12-06 10:00');
    expect($next->exists)->toBeTrue();
});

it('refuse une demande dont le délai de préparation empiète sur l\'occupation suivante', function () {
    occupy('2026-12-10 09:00', '2026-12-12 10:00', 60);

    // Retour à 08:30 + 60 min de préparation = 09:30 : chevauche le départ de 09:00.
    expect(fn () => occupy('2026-12-08 09:00', '2026-12-10 08:30', 60))->toThrow(VehicleUnavailable::class);

    // Retour à 08:00 + 60 min = 09:00 : accepté.
    expect(occupy('2026-12-08 09:00', '2026-12-10 08:00', 60)->exists)->toBeTrue();
});

it('détecte un chevauchement par-dessus minuit, la fin du mois et la fin de l\'année', function (string $existingFrom, string $existingTo, string $from, string $to) {
    occupy($existingFrom, $existingTo, 0);

    expect(fn () => occupy($from, $to, 0))->toThrow(VehicleUnavailable::class);
})->with([
    'minuit' => ['2026-12-03 22:00', '2026-12-04 02:00', '2026-12-04 01:59', '2026-12-04 08:00'],
    'fin de mois' => ['2026-11-30 18:00', '2026-12-01 09:00', '2026-12-01 08:00', '2026-12-02 08:00'],
    'fin d\'année' => ['2026-12-31 20:00', '2027-01-02 10:00', '2027-01-01 00:00', '2027-01-01 12:00'],
    'demande qui englobe' => ['2026-12-10 10:00', '2026-12-11 10:00', '2026-12-09 10:00', '2026-12-15 10:00'],
]);

it('libère une occupation : la période redevient disponible, rien n\'est supprimé (scénario 2)', function () {
    $source = blockSource();
    occupy('2026-12-03 09:00', '2026-12-05 10:00', 60, $source);

    expect($this->registry->release($source))->toBeTrue()
        ->and($this->registry->release($source))->toBeFalse();

    expect(occupy('2026-12-03 09:00', '2026-12-05 10:00')->exists)->toBeTrue()
        ->and(VehicleBlock::query()->count())->toBe(2)
        ->and(VehicleBlock::query()->where('active', false)->sole()->released_at)->not->toBeNull();
});

it('déplace l\'occupation d\'une source sans buter sur elle-même', function () {
    $source = blockSource();
    occupy('2026-12-03 09:00', '2026-12-05 10:00', 60, $source);

    $moved = occupy('2026-12-04 09:00', '2026-12-07 10:00', 60, $source);

    expect(VehicleBlock::query()->count())->toBe(1)
        ->and($moved->starts_at->format('Y-m-d H:i'))->toBe('2026-12-04 09:00');
});

it('ne déplace pas une occupation sur une période déjà prise', function () {
    $source = blockSource();
    occupy('2026-12-03 09:00', '2026-12-05 10:00', 60, $source);
    occupy('2026-12-10 09:00', '2026-12-12 10:00', 60);

    expect(fn () => occupy('2026-12-11 09:00', '2026-12-13 10:00', 60, $source))->toThrow(VehicleUnavailable::class);

    expect(VehicleBlock::query()->where('source_id', $source->id)->sole()->starts_at->format('Y-m-d'))->toBe('2026-12-03');
});

it('ignore les occupations des autres véhicules', function () {
    occupy('2026-12-03 09:00', '2026-12-05 10:00');
    $other = Vehicle::factory()->create(['agency_id' => $this->agency->id, 'vehicle_category_id' => $this->vehicle->vehicle_category_id]);

    $block = $this->registry->occupy($other->id, Period::between('2026-12-03 09:00', '2026-12-05 10:00'), blockSource(), $this->agency->id, 60);

    expect($block->vehicle_id)->toBe($other->id);
});

it('refuse un véhicule sorti du parc', function () {
    $this->vehicle->forceFill(['status' => VehicleStatus::Retired])->saveQuietly();

    expect(fn () => occupy('2026-12-03 09:00', '2026-12-05 10:00'))->toThrow(VehicleUnavailable::class, 'sorti du parc');
});

it('refuse une période dont le retour précède le départ', function () {
    expect(fn () => Period::between('2026-12-05 10:00', '2026-12-05 10:00'))->toThrow(InvalidPeriod::class)
        ->and(fn () => Period::between('2026-12-05 10:00', '2026-12-04 10:00'))->toThrow(InvalidPeriod::class);
});

it('n\'écrit, ne supprime ni ne verrouille une occupation hors des règles', function () {
    $block = occupy('2026-12-03 09:00', '2026-12-05 10:00');

    expect(fn () => $block->update(['active' => false]))->toThrow(LogicException::class, 'BlockRegistry')
        ->and(fn () => $block->delete())->toThrow(LogicException::class, 'libère');

    // Hors transaction, un verrou serait rendu aussitôt : refusé.
    $level = DB::transactionLevel();
    for ($i = 0; $i < $level; $i++) {
        DB::rollBack();
    }

    try {
        expect(fn () => app(VehicleLock::class)->vehicle($this->vehicle->id))->toThrow(LogicException::class, 'hors transaction');
    } finally {
        for ($i = 0; $i < $level; $i++) {
            DB::beginTransaction();
        }
    }
});
