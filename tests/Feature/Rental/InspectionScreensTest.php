<?php

use App\Domain\Availability\Actions\SaveReservation;
use App\Domain\Rental\Actions\OpenContract;
use App\Domain\Rental\Actions\SaveContractDriver;
use App\Domain\Setup\Actions\SaveRatePlan;
use App\Domain\Setup\Actions\SaveSeason;
use App\Enums\ContractStatus;
use App\Enums\VehicleStatus;
use App\Filament\Pages\DailyMovements;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Contracts\Pages\InspectContract;
use App\Models\Agency;
use App\Models\Customer;
use App\Models\Inspection;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\VehicleDocument;
use App\Support\Authorization\Role;
use App\Support\Files\PrivateFiles;
use App\Support\Sequences\SequenceAllocator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * Écrans de la tablette (phase 5, lot L6) : départs et retours du jour, état des lieux
 * pas à pas (relevés, dommages sur le schéma, photo, signature, validation du départ),
 * lecture seule après validation, portée par agence (RG-13).
 */

beforeEach(function () {
    CarbonImmutable::setTestNow('2027-03-10 08:30');
    Storage::fake(PrivateFiles::DISK);
    Storage::fake('local');

    $admin = withTwoFactor(userWithProfile(Role::Admin));
    $this->sfax = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->sousse = Agency::factory()->create(['code' => 'SOU', 'name' => 'Sousse']);
    $this->agent = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sfax->id]);
    $this->agentSousse = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sousse->id]);

    $this->category = VehicleCategory::factory()->create(['code' => 'CIT', 'name' => 'Citadine']);
    $season = app(SaveSeason::class)->handle(null, ['name' => 'Année 2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31'], $admin);
    app(SaveRatePlan::class)->handle(null, [
        'vehicle_category_id' => $this->category->id, 'season_id' => $season->id, 'agency_id' => null,
        'valid_from' => '2027-01-01', 'valid_to' => null, 'is_active' => true,
        'lines' => [['min_days' => 1, 'max_days' => null, 'daily_amount' => '100,000']],
    ], $admin);

    $this->vehicle = screenVehicle($this->sfax->id);
    $this->customer = Customer::factory()->create(['origin_agency_id' => $this->sfax->id, 'first_name' => 'Amel', 'last_name' => 'Trabelsi']);
    app(SequenceAllocator::class)->prepare(OpenContract::sequence());
    $this->contract = app(OpenContract::class)->atCounter([
        'customer_id' => $this->customer->id, 'agency_id' => $this->sfax->id, 'vehicle_id' => $this->vehicle->id,
        'starts_at' => '2027-03-10 09:00', 'ends_at' => '2027-03-13 09:00',
    ], $this->agent);
    app(SaveContractDriver::class)->handle($this->contract, [
        'first_name' => 'Amel', 'last_name' => 'Trabelsi', 'license_number' => '12/345678',
        'birth_date' => '1990-01-15', 'license_issued_on' => '2010-01-15', 'license_expires_on' => '2032-01-15', 'is_primary' => true,
    ], $this->agent);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function screenVehicle(int $agencyId): Vehicle
{
    $vehicle = Vehicle::factory()->create(['agency_id' => $agencyId, 'vehicle_category_id' => test()->category->id, 'mileage' => 42000])->refresh();
    foreach (['insurance', 'technical_inspection'] as $type) {
        VehicleDocument::query()->create(['vehicle_id' => $vehicle->id, 'type' => $type, 'valid_from' => '2027-01-01', 'valid_until' => '2027-12-31']);
    }

    return $vehicle;
}

function screenSignature(): string
{
    $image = imagecreatetruecolor(300, 120);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
    imageline($image, 10, 100, 290, 20, (int) imagecolorallocate($image, 0, 0, 0));
    ob_start();
    imagepng($image);

    return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
}

it('liste les départs et retours du jour de l\'agence, avec leurs badges', function () {
    screenVehicle($this->sfax->id);
    $reservation = app(SaveReservation::class)->handle(null, [
        'customer_id' => $this->customer->id, 'agency_id' => $this->sfax->id, 'vehicle_category_id' => $this->category->id,
        'vehicle_id' => null, 'starts_at' => '2027-03-10 15:00', 'ends_at' => '2027-03-12 15:00',
    ], $this->agent);
    $sousseVehicle = screenVehicle($this->sousse->id);
    $sousseContract = app(OpenContract::class)->atCounter([
        'customer_id' => $this->customer->id, 'agency_id' => $this->sousse->id, 'vehicle_id' => $sousseVehicle->id,
        'starts_at' => '2027-03-10 10:00', 'ends_at' => '2027-03-12 10:00',
    ], $this->agentSousse);

    $this->actingAs($this->agent);

    Livewire::test(DailyMovements::class)
        ->assertSee($this->contract->number)
        ->assertSee($reservation->number)
        ->assertSee('Véhicule à affecter')
        ->assertSee('Amel Trabelsi')
        ->assertDontSee($sousseContract->number);
});

it('mène l\'état des lieux de départ jusqu\'à la validation, depuis la tablette', function () {
    $this->actingAs($this->agent);
    $page = Livewire::test(InspectContract::class, ['record' => $this->contract->getRouteKey(), 'type' => 'departure']);
    $inspection = Inspection::query()->sole();

    $page->set('mileage', '42 150')->call('saveReadings')->assertHasNoErrors()
        ->call('setFuel', 6)
        ->set('placing', true)->set('zone', 'hood')->set('x', '40.00')->set('y', '20.00')
        ->set('description', 'Éclat sur le capot')->set('severity', 'minor')
        ->call('addDamage')->assertHasNoErrors()
        // Le fichier simulé par Livewire est vide : le serveur contrôle le contenu et le refuse.
        // L'envoi d'une vraie photo est couvert par InspectionTest et vérifié dans le navigateur.
        ->set('photo', UploadedFile::fake()->image('capot.jpg', 2000, 1500))->call('storePhoto')
        ->assertNotified('Fichier refusé : ce n\'est pas une image acceptée.')
        ->call('saveSignature', screenSignature())
        ->assertSee('Éclat sur le capot');

    $inspection->refresh();

    expect($inspection->mileage)->toBe(42150)
        ->and($inspection->fuel_eighths)->toBe(6)
        ->and($inspection->damages()->count())->toBe(1)
        ->and($inspection->photos()->count())->toBe(0)
        ->and($inspection->signature_path)->not->toBeNull()
        ->and(Storage::disk(PrivateFiles::DISK)->allFiles(PrivateFiles::UPLOAD_DIRECTORY))->toBe([]);

    $page->call('validateDeparture')
        ->assertRedirect(ContractResource::getUrl('view', ['record' => $this->contract]));

    expect($this->contract->fresh()?->status)->toBe(ContractStatus::Active)
        ->and($this->vehicle->refresh()->status)->toBe(VehicleStatus::Rented);

    // Validé : lecture seule ; une saisie forcée est refusée par le domaine.
    Livewire::test(InspectContract::class, ['record' => $this->contract->getRouteKey(), 'type' => 'departure'])
        ->assertSee('lecture seule')
        ->assertDontSee('Valider le départ ?')
        ->set('mileage', '1')->call('saveReadings')
        ->assertNotified('Contrat « En cours » : état des lieux de départ impossible.');

    expect($inspection->refresh()->mileage)->toBe(42150);
});

it('affiche le refus du domaine sans quitter la saisie (RG-04, départ incomplet)', function () {
    $this->actingAs($this->agent);

    Livewire::test(InspectContract::class, ['record' => $this->contract->getRouteKey(), 'type' => 'departure'])
        ->set('mileage', '41000')->call('saveReadings')
        ->assertNotified('Kilométrage refusé : il ne peut pas être inférieur au dernier relevé du véhicule (42 000 km).')
        ->call('validateDeparture')
        ->assertNotified('État des lieux incomplet : kilométrage.')
        ->assertNoRedirect();

    expect($this->contract->fresh()?->status)->toBe(ContractStatus::Draft);
});

it('n\'ouvre pas l\'état des lieux d\'un contrat d\'une autre agence (RG-13)', function () {
    $this->actingAs($this->agentSousse);

    $this->get(ContractResource::getUrl('inspect', ['record' => $this->contract, 'type' => 'departure']))->assertNotFound();

    expect(Inspection::query()->count())->toBe(0);
});

it('ouvre l\'état des lieux depuis la fiche du contrat', function () {
    $this->actingAs($this->agent);

    $this->get(ContractResource::getUrl('view', ['record' => $this->contract]))->assertOk()->assertSee('État des lieux de départ');
    $this->get(ContractResource::getUrl('inspect', ['record' => $this->contract, 'type' => 'departure']))->assertOk()
        ->assertSee('Schéma du véhicule vu de dessus', false)
        ->assertSee('Valider le départ');
    $this->get(ContractResource::getUrl('inspect', ['record' => $this->contract, 'type' => 'autre']))->assertNotFound();
});
