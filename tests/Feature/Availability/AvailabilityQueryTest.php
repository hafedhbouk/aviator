<?php

use App\Domain\Availability\AvailabilityQuery;
use App\Domain\Availability\BlockRegistry;
use App\Domain\Availability\Period;
use App\Domain\Fleet\DocumentStatusSync;
use App\Domain\Fleet\VehicleStatusService;
use App\Enums\VehicleDocumentType;
use App\Enums\VehicleStatus;
use App\Models\Agency;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\VehicleDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Models\AgencyDocument;

uses(RefreshDatabase::class);

/*
 * Recherche de disponibilité (C-01, lot L9) : agence de départ (localisation),
 * catégorie, période, délai de préparation de la catégorie ; véhicules hors service
 * exclus ; documents manquants signalés ; nombre de requêtes constant.
 */

beforeEach(function () {
    $this->sfax = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->sousse = Agency::factory()->create(['code' => 'SOU', 'name' => 'Sousse']);
    $this->citadine = VehicleCategory::factory()->create(['code' => 'CIT', 'preparation_buffer_minutes' => 60]);
    $this->suv = VehicleCategory::factory()->create(['code' => 'SUV', 'preparation_buffer_minutes' => 120]);
    $this->query = app(AvailabilityQuery::class);
    $this->period = Period::between('2026-12-10 09:00', '2026-12-12 09:00');
});

function fleetVehicle(int $agencyId, int $categoryId, bool $documents = true): Vehicle
{
    $vehicle = Vehicle::factory()->create(['agency_id' => $agencyId, 'vehicle_category_id' => $categoryId])->refresh();

    if ($documents) {
        foreach (['insurance', 'technical_inspection'] as $type) {
            VehicleDocument::query()->create(['vehicle_id' => $vehicle->id, 'type' => $type, 'valid_from' => '2026-01-01', 'valid_until' => '2027-12-31']);
        }
    }

    return $vehicle;
}

function availableIds(array $results): array
{
    return array_column($results, 'vehicle_id');
}

it('ne propose que les véhicules localisés dans l\'agence de départ, et de la catégorie demandée', function () {
    $here = fleetVehicle($this->sfax->id, $this->citadine->id);
    $suv = fleetVehicle($this->sfax->id, $this->suv->id);
    fleetVehicle($this->sousse->id, $this->citadine->id);

    expect(availableIds($this->query->vehicles($this->sfax->id, $this->citadine->id, $this->period)))->toBe([$here->id])
        ->and(availableIds($this->query->vehicles($this->sfax->id, null, $this->period)))->toEqualCanonicalizing([$here->id, $suv->id]);
});

it('écarte un véhicule occupé, délai de préparation de sa catégorie compris', function () {
    $citadine = fleetVehicle($this->sfax->id, $this->citadine->id);
    $suv = fleetVehicle($this->sfax->id, $this->suv->id);
    $registry = app(BlockRegistry::class);
    $source = fn () => AgencyDocument::query()->create(['agency_id' => $this->sfax->id, 'title' => 'Source']);

    // Les deux repartent le 12/12 à 10:30 : la citadine (60 min) est prête, le SUV (120 min) non.
    $registry->occupy($citadine->id, Period::between('2026-12-12 10:30', '2026-12-14 10:00'), $source(), $this->sfax->id, 60);
    $registry->occupy($suv->id, Period::between('2026-12-12 10:30', '2026-12-14 10:00'), $source(), $this->sfax->id, 120);

    expect(availableIds($this->query->vehicles($this->sfax->id, null, $this->period)))->toBe([$citadine->id]);
});

it('remet un véhicule dans la liste quand son occupation est libérée', function () {
    $vehicle = fleetVehicle($this->sfax->id, $this->citadine->id);
    $source = AgencyDocument::query()->create(['agency_id' => $this->sfax->id, 'title' => 'Source']);
    app(BlockRegistry::class)->occupy($vehicle->id, $this->period, $source, $this->sfax->id, 60);

    expect($this->query->vehicles($this->sfax->id, null, $this->period))->toBe([]);

    app(BlockRegistry::class)->release($source);

    expect(availableIds($this->query->vehicles($this->sfax->id, null, $this->period)))->toBe([$vehicle->id]);
});

it('écarte les véhicules sortis, en maintenance ou immobilisés, pas ceux qui attendent leurs documents', function () {
    $retired = fleetVehicle($this->sfax->id, $this->citadine->id);
    $retired->forceFill(['status' => VehicleStatus::Retired])->saveQuietly();
    $maintenance = fleetVehicle($this->sfax->id, $this->citadine->id);
    $maintenance->forceFill(['status' => VehicleStatus::Maintenance])->saveQuietly();
    $accident = fleetVehicle($this->sfax->id, $this->citadine->id);
    app(VehicleStatusService::class)->transition($accident, VehicleStatus::Unavailable, reason: 'Sinistre');
    $waiting = fleetVehicle($this->sfax->id, $this->citadine->id, documents: false);
    app(DocumentStatusSync::class)->sync($waiting);

    $results = $this->query->vehicles($this->sfax->id, null, $this->period);

    expect($waiting->fresh()?->status)->toBe(VehicleStatus::Unavailable)
        ->and(availableIds($results))->toBe([$waiting->id])
        ->and($results[0]['missing_documents'])->toBe([VehicleDocumentType::Insurance, VehicleDocumentType::TechnicalInspection]);
});

it('signale les documents non valides à la date de départ, pas à la date du jour', function () {
    $vehicle = fleetVehicle($this->sfax->id, $this->citadine->id, documents: false);
    VehicleDocument::query()->create(['vehicle_id' => $vehicle->id, 'type' => 'insurance', 'valid_from' => '2026-01-01', 'valid_until' => '2026-12-09']);
    VehicleDocument::query()->create(['vehicle_id' => $vehicle->id, 'type' => 'technical_inspection', 'valid_from' => '2026-01-01', 'valid_until' => '2027-12-31']);

    [$result] = $this->query->vehicles($this->sfax->id, null, $this->period);

    expect($result['missing_documents'])->toBe([VehicleDocumentType::Insurance])
        ->and($result['buffer_minutes'])->toBe(60)
        ->and($result)->not->toHaveKey('customer');
});

it('garde un nombre de requêtes constant, quel que soit le nombre de véhicules', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->query->vehicles($this->sfax->id, null, $this->period);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    fleetVehicle($this->sfax->id, $this->citadine->id);
    fleetVehicle($this->sfax->id, $this->suv->id);
    $count(); // premier appel : chargement des réglages de réservation, mis en cache ensuite
    $few = $count();

    foreach (range(1, 8) as $i) {
        fleetVehicle($this->sfax->id, $i % 2 === 0 ? $this->citadine->id : $this->suv->id);
    }

    // Véhicules, catégories, une requête d'occupations par catégorie, documents.
    expect($few)->toBe(5)
        ->and($count())->toBe($few);
});
