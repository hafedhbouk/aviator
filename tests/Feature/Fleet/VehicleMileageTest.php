<?php

use App\Domain\Fleet\Actions\CorrectMileage;
use App\Domain\Fleet\Actions\RecordMileage;
use App\Domain\Fleet\Actions\SaveVehicle;
use App\Domain\Fleet\Exceptions\FleetException;
use App\Domain\Fleet\VehicleHistory;
use App\Domain\Fleet\VehicleStatusService;
use App\Enums\VehicleStatus;
use App\Filament\Resources\Vehicles\Pages\ViewVehicle;
use App\Models\Agency;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\VehicleMileageLog;
use App\Support\Authorization\Role;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * Kilométrage tracé (B-03) et historique consolidé (B-06), lot L4.
 */

beforeEach(function () {
    $this->admin = withTwoFactor(userWithProfile(Role::Admin));
    $this->sfax = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->agent = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sfax->id]);
    $this->category = VehicleCategory::factory()->create();
    $this->vehicle = Vehicle::factory()->create(['agency_id' => $this->sfax->id, 'vehicle_category_id' => $this->category->id])->refresh();
    $this->record = app(RecordMileage::class);
    $this->correct = app(CorrectMileage::class);
});

it('enregistre un relevé, met à jour le véhicule et garde la valeur précédente', function () {
    $this->record->handle($this->vehicle, 12_500, $this->admin);
    $log = $this->record->handle($this->vehicle, 13_000, $this->admin);

    expect($this->vehicle->fresh()?->mileage)->toBe(13_000)
        ->and($log->previous_mileage)->toBe(12_500)
        ->and($log->mileage)->toBe(13_000)
        ->and($log->is_correction)->toBeFalse()
        ->and($log->user_id)->toBe($this->admin->id);
});

it('accepte un relevé égal au précédent et refuse une baisse (B-03)', function () {
    $this->record->handle($this->vehicle, 20_000, $this->admin);
    $this->record->handle($this->vehicle, 20_000, $this->admin);

    expect(fn () => $this->record->handle($this->vehicle, 19_999, $this->admin))
        ->toThrow(FleetException::class, 'inférieur au dernier relevé (20 000 km)');

    expect($this->vehicle->fresh()?->mileage)->toBe(20_000)
        ->and(VehicleMileageLog::query()->count())->toBe(2);
});

it('refuse un relevé dans le futur ou antérieur au dernier relevé', function () {
    $this->record->handle($this->vehicle, 1_000, $this->admin, recordedAt: CarbonImmutable::parse('2026-11-20 10:00'));

    expect(fn () => $this->record->handle($this->vehicle, 1_100, $this->admin, recordedAt: CarbonImmutable::now()->addHour()))
        ->toThrow(FleetException::class, 'futur')
        ->and(fn () => $this->record->handle($this->vehicle, 1_100, $this->admin, recordedAt: CarbonImmutable::parse('2026-11-19 10:00')))
        ->toThrow(FleetException::class, 'antérieur');
});

it('refuse un kilométrage hors bornes', function (int $mileage) {
    expect(fn () => $this->record->handle($this->vehicle, $mileage, $this->admin))->toThrow(FleetException::class, 'invalide');
})->with([-1, 2_000_001]);

it('réserve le relevé ponctuel au parc ; un relevé avec document source est accepté', function () {
    expect(fn () => $this->record->handle($this->vehicle, 500, $this->agent))->toThrow(FleetException::class);

    // Phase 5 : le départ d'un contrat appelle RecordMileage avec le contrat en source.
    $log = $this->record->handle($this->vehicle, 500, $this->agent, source: $this->sfax);

    expect($log->source_type)->toBe($this->sfax->getMorphClass())
        ->and($log->source_id)->toBe($this->sfax->id);
});

it('corrige à la baisse avec un motif, en gardant l\'ancienne valeur', function () {
    $this->record->handle($this->vehicle, 150_000, $this->admin);

    $log = $this->correct->handle($this->vehicle, 15_000, 'Zéro en trop au relevé initial', $this->admin);

    expect($this->vehicle->fresh()?->mileage)->toBe(15_000)
        ->and($log->is_correction)->toBeTrue()
        ->and($log->previous_mileage)->toBe(150_000)
        ->and($log->reason)->toBe('Zéro en trop au relevé initial');

    // Après la correction, un relevé repart de la nouvelle valeur.
    $this->record->handle($this->vehicle, 15_200, $this->admin);
    expect($this->vehicle->fresh()?->mileage)->toBe(15_200);
});

it('refuse une correction sans motif, inchangée, ou par un agent', function () {
    $this->record->handle($this->vehicle, 1_000, $this->admin);

    expect(fn () => $this->correct->handle($this->vehicle, 900, '  erreur ', $this->admin))->toThrow(FleetException::class, 'motif')
        ->and(fn () => $this->correct->handle($this->vehicle, 1_000, 'Motif suffisamment long', $this->admin))->toThrow(FleetException::class, 'inutile')
        ->and(fn () => $this->correct->handle($this->vehicle, 900, 'Motif suffisamment long', $this->agent))->toThrow(FleetException::class);

    expect($this->vehicle->fresh()?->mileage)->toBe(1_000);
});

it('refuse tout relevé sur un véhicule sorti du parc', function () {
    $this->vehicle->forceFill(['status' => VehicleStatus::Retired])->saveQuietly();

    expect(fn () => $this->record->handle($this->vehicle->fresh(), 10, $this->admin))->toThrow(FleetException::class, 'sorti du parc');
});

it('refuse toute écriture du kilométrage hors d\'un relevé', function () {
    expect(fn () => $this->vehicle->forceFill(['mileage' => 99])->save())->toThrow(LogicException::class, 'relevé');

    expect($this->vehicle->fresh()?->mileage)->toBe(0);
});

it('garde les relevés en ajout seul', function () {
    $log = $this->record->handle($this->vehicle, 10, $this->admin);

    expect(fn () => $log->update(['mileage' => 5]))->toThrow(LogicException::class)
        ->and(fn () => $log->delete())->toThrow(LogicException::class);
});

it('ouvre l\'historique du kilométrage par un relevé initial à la création', function () {
    $vehicle = app(SaveVehicle::class)->handle(null, [
        'internal_code' => 'V-77', 'plate_number' => '77 TU 7777', 'vin' => 'VF1KM00000077',
        'vehicle_category_id' => $this->category->id, 'brand' => 'Dacia', 'model' => 'Sandero', 'year' => '2023',
        'fuel' => 'diesel', 'transmission' => 'manual', 'agency_id' => $this->sfax->id, 'financing_mode' => 'cash',
        'initial_mileage' => '12 345',
    ], $this->admin);

    expect($vehicle->mileage)->toBe(12_345)
        ->and($vehicle->mileageLogs()->sole()->reason)->toBe('Relevé initial');

    expect(fn () => app(SaveVehicle::class)->handle(null, [
        'internal_code' => 'V-78', 'plate_number' => '78 TU 7878', 'vin' => 'VF1KM00000078',
        'vehicle_category_id' => $this->category->id, 'brand' => 'Dacia', 'model' => 'Sandero', 'year' => '2023',
        'fuel' => 'diesel', 'transmission' => 'manual', 'agency_id' => $this->sfax->id, 'financing_mode' => 'cash',
        'initial_mileage' => '-5',
    ], $this->admin))->toThrow(ValidationException::class);
});

it('rassemble statuts, kilométrage et documents dans l\'historique, du plus récent au plus ancien (B-06)', function () {
    CarbonImmutable::setTestNow('2026-11-20 08:00');
    $this->record->handle($this->vehicle, 1_000, $this->admin);

    CarbonImmutable::setTestNow('2026-11-21 08:00');
    app(VehicleStatusService::class)->transition($this->vehicle, VehicleStatus::Maintenance, actor: $this->admin, reason: 'Vidange');

    CarbonImmutable::setTestNow('2026-11-22 08:00');
    $this->correct->handle($this->vehicle, 900, 'Erreur de saisie corrigée', $this->admin);
    CarbonImmutable::setTestNow();

    $history = app(VehicleHistory::class)->for($this->vehicle);

    expect(array_column($history, 'kind'))->toBe(['Correction du kilométrage', 'Statut', 'Kilométrage'])
        ->and($history[0]['title'])->toBe('1 000 km → 900 km')
        ->and($history[1]['title'])->toBe('Disponible → En maintenance')
        ->and($history[1]['detail'])->toBe('Vidange')
        ->and($history[2]['by'])->toBe($this->admin->name);
});

it('propose relevé et correction à l\'admin, pas à l\'agent, et affiche l\'historique', function () {
    $this->actingAs($this->admin);

    Livewire::test(ViewVehicle::class, ['record' => $this->vehicle->getRouteKey()])
        ->assertActionVisible('recordMileage')
        ->assertActionVisible('correctMileage')
        ->callAction('recordMileage', ['mileage' => 4_200])
        ->assertHasNoActionErrors()
        ->assertSee('Historique');

    expect($this->vehicle->fresh()?->mileage)->toBe(4_200);

    $this->actingAs($this->agent);
    Livewire::test(ViewVehicle::class, ['record' => $this->vehicle->getRouteKey()])
        ->assertActionHidden('recordMileage')
        ->assertActionHidden('correctMileage');
});
