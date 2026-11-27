<?php

use App\Domain\Availability\Actions\SaveReservation;
use App\Domain\Fleet\Actions\EndMaintenance;
use App\Domain\Fleet\Exceptions\FleetException;
use App\Domain\Rental\Actions\OpenContract;
use App\Domain\Rental\Actions\RecordInspection;
use App\Domain\Rental\Actions\RegisterReturn;
use App\Domain\Rental\Actions\SaveContractDriver;
use App\Domain\Rental\Actions\StartRental;
use App\Domain\Rental\Exceptions\ContractException;
use App\Domain\Setup\Actions\SaveRatePlan;
use App\Domain\Setup\Actions\SaveSeason;
use App\Domain\Setup\Actions\UpdateSettings;
use App\Enums\ChargeNature;
use App\Enums\ContractStatus;
use App\Enums\InspectionType;
use App\Enums\VehicleStatus;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Contracts\Pages\InspectContract;
use App\Models\Agency;
use App\Models\Contract;
use App\Models\ContractCharge;
use App\Models\Customer;
use App\Models\Inspection;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\VehicleDocument;
use App\Settings\PricingSettings;
use App\Support\Authorization\Role;
use App\Support\Files\PrivateFiles;
use App\Support\Sequences\SequenceAllocator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * Retour et frais (phase 5, lot L8, D-08, D-09, RG-04, RG-09, Q14, Q43 à Q45, Q73, D47)
 * et **scénario 4** : restitution en retard, frais calculés selon les paramètres
 * (tolérance de 60 min, puis une journée au tarif du contrat). Contrat du 10/03/2027 09:00
 * au 13/03/2027 09:00, 100 DT par jour, parti à 42 100 km, plein (8/8).
 */

beforeEach(function () {
    CarbonImmutable::setTestNow('2027-03-10 09:00');
    Storage::fake(PrivateFiles::DISK);

    $this->superAdmin = withTwoFactor(userWithProfile(Role::SuperAdmin));
    $this->admin = withTwoFactor(userWithProfile(Role::Admin));
    $this->sfax = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->sousse = Agency::factory()->create(['code' => 'SOU', 'name' => 'Sousse']);
    $this->agent = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sfax->id]);
    $this->agentSousse = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sousse->id]);

    $this->category = VehicleCategory::factory()->create(['code' => 'CIT', 'name' => 'Citadine']);
    $season = app(SaveSeason::class)->handle(null, ['name' => 'Année 2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31'], $this->admin);
    app(SaveRatePlan::class)->handle(null, [
        'vehicle_category_id' => $this->category->id, 'season_id' => $season->id, 'agency_id' => null,
        'valid_from' => '2027-01-01', 'valid_to' => null, 'is_active' => true,
        'lines' => [['min_days' => 1, 'max_days' => null, 'daily_amount' => '100,000']],
    ], $this->admin);

    $this->vehicle = Vehicle::factory()->create(['agency_id' => $this->sfax->id, 'vehicle_category_id' => $this->category->id, 'mileage' => 42000, 'fuel_tank_liters' => 45])->refresh();
    foreach (['insurance', 'technical_inspection'] as $type) {
        VehicleDocument::query()->create(['vehicle_id' => $this->vehicle->id, 'type' => $type, 'valid_from' => '2027-01-01', 'valid_until' => '2027-12-31']);
    }

    $this->customer = Customer::factory()->create(['origin_agency_id' => $this->sfax->id]);
    app(SequenceAllocator::class)->prepare(OpenContract::sequence());
});

afterEach(fn () => CarbonImmutable::setTestNow());

function returnSignature(): string
{
    $image = imagecreatetruecolor(200, 80);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
    imageline($image, 5, 70, 190, 10, (int) imagecolorallocate($image, 0, 0, 0));
    ob_start();
    imagepng($image);

    return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
}

/**
 * Contrat ouvert, conducteur, état des lieux de départ (42 100 km, plein), départ validé.
 */
function rentedContract(): Contract
{
    $contract = app(OpenContract::class)->atCounter([
        'customer_id' => test()->customer->id, 'agency_id' => test()->sfax->id, 'vehicle_id' => test()->vehicle->id,
        'starts_at' => '2027-03-10 09:00', 'ends_at' => '2027-03-13 09:00',
    ], test()->agent);
    app(SaveContractDriver::class)->handle($contract, [
        'first_name' => 'Amel', 'last_name' => 'Trabelsi', 'license_number' => '12/345678',
        'birth_date' => '1990-01-15', 'license_issued_on' => '2010-01-15', 'license_expires_on' => '2032-01-15', 'is_primary' => true,
    ], test()->agent);
    $record = app(RecordInspection::class);
    $inspection = $record->open($contract, InspectionType::Departure, test()->agent);
    $record->update($inspection, ['mileage' => 42100, 'fuel_eighths' => 8], test()->agent);
    $record->sign($inspection, returnSignature(), test()->agent);

    return app(StartRental::class)->handle($contract, test()->agent);
}

/**
 * État des lieux de retour saisi, puis retour validé à l'heure donnée.
 *
 * @param  array<string, mixed>  $data
 */
function returnAt(Contract $contract, string $at, int $mileage = 42500, int $fuel = 8, array $data = [], bool $sign = true): Contract
{
    CarbonImmutable::setTestNow($at);
    $record = app(RecordInspection::class);
    $inspection = $record->open($contract->fresh(), InspectionType::Return, test()->agent);
    $record->update($inspection, ['mileage' => $mileage, 'fuel_eighths' => $fuel], test()->agent);

    if ($sign) {
        $record->sign($inspection, returnSignature(), test()->agent);
    }

    return app(RegisterReturn::class)->handle($contract->fresh(), $data, test()->agent);
}

/**
 * @return array<string, string> nature => montant
 */
function chargesOf(Contract $contract): array
{
    return ContractCharge::query()->where('contract_id', $contract->id)->get()
        ->mapWithKeys(fn (ContractCharge $charge): array => [$charge->nature->value => (string) $charge->amount])->all();
}

// --- Scénario 4 : retard ------------------------------------------------------------------

it('ne facture aucun retard à la tolérance pile (60 min, scénario 4)', function () {
    $contract = returnAt(rentedContract(), '2027-03-13 10:00');

    expect($contract->status)->toBe(ContractStatus::Returned)
        ->and($contract->returned_at?->equalTo(CarbonImmutable::parse('2027-03-13 10:00')))->toBeTrue()
        ->and(chargesOf($contract))->toBe([]);
});

it('facture une journée au tarif du contrat une minute après la tolérance (scénario 4)', function () {
    $contract = returnAt(rentedContract(), '2027-03-13 10:01');
    $charge = ContractCharge::query()->sole();

    expect($charge->nature)->toBe(ChargeNature::LateReturn)
        ->and((string) $charge->amount)->toBe('100.000')
        ->and($charge->quantity)->toBe('1.000')
        ->and($charge->calculation)->toMatchArray(['late_minutes' => 61, 'tolerance_minutes' => 60, 'unit' => 'day', 'planned_days' => 3, 'actual_days' => 4]);
});

it('facture plusieurs journées de retard (scénario 4)', function () {
    $contract = returnAt(rentedContract(), '2027-03-14 10:30');

    expect(chargesOf($contract))->toBe(['late_return' => '200.000']);
});

it('facture le retard à l\'heure avec le tarif horaire figé sur le contrat (D51)', function () {
    $this->category->forceFill(['late_hour_amount' => '15.000'])->save();
    app(UpdateSettings::class)->handle(app(PricingSettings::class), ['late_unit' => 'hour'], $this->superAdmin);

    // 150 min de retard au-delà de 60 de tolérance : 3 heures entamées.
    $contract = returnAt(rentedContract(), '2027-03-13 11:30');

    expect(chargesOf($contract))->toBe(['late_return' => '45.000'])
        ->and(ContractCharge::query()->sole()->calculation['hours'])->toBe(3);
});

// --- Kilomètres, carburant, dommages --------------------------------------------------------

it('facture les kilomètres au-delà du forfait figé (Q43)', function () {
    $this->category->forceFill(['included_km_per_day' => 300, 'extra_km_amount' => '0.250'])->save();

    // 3 jours × 300 km = 900 km inclus ; 1 000 km parcourus : 100 km à 0,250.
    $contract = returnAt(rentedContract(), '2027-03-13 09:00', mileage: 43100);

    expect(chargesOf($contract))->toBe(['extra_km' => '25.000'])
        ->and(ContractCharge::query()->sole()->calculation)->toMatchArray(['driven_km' => 1000, 'allowance_km' => 900]);
});

it('facture le carburant manquant au millime, frais de service compris (D50, Q44)', function () {
    app(UpdateSettings::class)->handle(app(PricingSettings::class), ['fuel_liter_amount' => '2,525', 'fuel_service_amount' => '10,000'], $this->superAdmin);

    // 2/8 de 45 L = 11,250 L × 2,525 = 28,40625 → 28,406 ; + 10,000.
    $contract = returnAt(rentedContract(), '2027-03-13 09:00', fuel: 6);

    expect(chargesOf($contract))->toBe(['fuel' => '38.406'])
        ->and(ContractCharge::query()->sole()->quantity)->toBe('11.250');
});

it('ne facture ni carburant ni kilomètres tant que leurs paramètres sont vides', function () {
    $contract = returnAt(rentedContract(), '2027-03-13 09:00', mileage: 49000, fuel: 0);

    expect(chargesOf($contract))->toBe([]);
});

it('plafonne le total des dommages par la franchise figée sur le contrat (Q45)', function () {
    $this->category->forceFill(['insurance_excess_amount' => '1000.000'])->save();
    $contract = rentedContract();

    CarbonImmutable::setTestNow('2027-03-13 09:00');
    $record = app(RecordInspection::class);
    $inspection = $record->open($contract->fresh(), InspectionType::Return, $this->agent);
    $record->update($inspection, ['mileage' => 42500, 'fuel_eighths' => 8], $this->agent);
    $record->sign($inspection, returnSignature(), $this->agent);
    $door = $record->addDamage($inspection, ['zone' => 'front_left', 'x' => '10', 'y' => '30', 'description' => 'Portière enfoncée', 'severity' => 'major'], $this->agent);
    $bumper = $record->addDamage($inspection, ['zone' => 'rear_bumper', 'x' => '50', 'y' => '95', 'description' => 'Pare-chocs fendu', 'severity' => 'moderate'], $this->agent);

    app(RegisterReturn::class)->handle($contract->fresh(), ['damage_amounts' => [$door->id => '700,000', $bumper->id => '600,000']], $this->agent);

    $charges = ContractCharge::query()->orderBy('id')->get();

    expect($charges->map(fn (ContractCharge $c): string => (string) $c->amount)->all())->toBe(['700.000', '300.000'])
        ->and($charges[1]->calculation)->toMatchArray(['requested_amount' => '600.000', 'excess_cap' => '1000.000', 'capped' => true])
        ->and($charges[1]->inspection_damage_id)->toBe($bumper->id);
});

it('fige les frais : un paramètre modifié après le retour ne les change pas', function () {
    $contract = returnAt(rentedContract(), '2027-03-13 10:01');
    app(UpdateSettings::class)->handle(app(PricingSettings::class), ['late_tolerance_minutes' => 600], $this->superAdmin);
    $charge = ContractCharge::query()->sole();

    expect((string) $charge->fresh()?->amount)->toBe('100.000')
        ->and(fn () => $charge->forceFill(['amount' => '1.000'])->save())->toThrow(LogicException::class)
        ->and(fn () => $charge->refresh()->delete())->toThrow(LogicException::class);
});

// --- Validation du retour -------------------------------------------------------------------

it('rend le véhicule disponible, relève le kilométrage, libère la période et fige l\'état des lieux', function () {
    $contract = returnAt(rentedContract(), '2027-03-12 09:00', mileage: 42650);
    $inspection = Inspection::query()->where('contract_id', $contract->id)->where('type', 'return')->sole();

    expect($this->vehicle->refresh()->status)->toBe(VehicleStatus::Available)
        ->and($this->vehicle->mileage)->toBe(42650)
        ->and($inspection->validated_by)->toBe($this->agent->id)
        ->and(fn () => app(RecordInspection::class)->update($inspection, ['fuel_eighths' => 1], $this->agent))->toThrow(ContractException::class)
        // Restitution anticipée (D-08, Q14) : aucun remboursement ni frais.
        ->and(chargesOf($contract))->toBe([]);

    // La période prévue restante est de nouveau réservable.
    $reservation = app(SaveReservation::class)->handle(null, [
        'customer_id' => $this->customer->id, 'agency_id' => $this->sfax->id, 'vehicle_category_id' => $this->category->id,
        'vehicle_id' => $this->vehicle->id, 'starts_at' => '2027-03-12 12:00', 'ends_at' => '2027-03-14 12:00',
    ], $this->agent);
    expect($reservation->id)->toBeInt();
});

it('met en maintenance un véhicule à réparer, puis la fin d\'intervention le rend disponible (D47)', function () {
    returnAt(rentedContract(), '2027-03-13 09:00', data: ['vehicle_to_repair' => true]);

    expect($this->vehicle->refresh()->status)->toBe(VehicleStatus::Maintenance)
        ->and(fn () => app(EndMaintenance::class)->handle($this->vehicle, 'Carrosserie réparée', $this->agent))->toThrow(FleetException::class, 'non autorisée')
        ->and(fn () => app(EndMaintenance::class)->handle($this->vehicle, '', $this->admin))->toThrow(FleetException::class, 'Motif');

    app(EndMaintenance::class)->handle($this->vehicle, 'Carrosserie réparée', $this->admin);

    expect($this->vehicle->refresh()->status)->toBe(VehicleStatus::Available)
        ->and(fn () => app(EndMaintenance::class)->handle($this->vehicle->refresh(), 'Seconde fois', $this->admin))->toThrow(FleetException::class, 'pas en maintenance');
});

it('accepte un retour en boîte à clés avec l\'heure déclarée, et refuse un état des lieux incomplet (Q73)', function () {
    $contract = rentedContract();
    CarbonImmutable::setTestNow('2027-03-13 08:00');
    $record = app(RecordInspection::class);
    $inspection = $record->open($contract->fresh(), InspectionType::Return, $this->agent);

    expect(fn () => app(RegisterReturn::class)->handle($contract->fresh(), [], $this->agent))->toThrow(ContractException::class, 'kilométrage');

    $record->update($inspection, ['mileage' => 42400, 'fuel_eighths' => 8], $this->agent);
    expect(fn () => app(RegisterReturn::class)->handle($contract->fresh(), [], $this->agent))->toThrow(ContractException::class, 'signature du client ou motif');

    $record->markCustomerAbsent($inspection, 'Clés déposées dans la boîte à 22 h', $this->agent);
    expect(fn () => app(RegisterReturn::class)->handle($contract->fresh(), ['returned_at' => '2027-03-14 09:00'], $this->agent))->toThrow(ContractException::class, 'futur')
        ->and(fn () => app(RegisterReturn::class)->handle($contract->fresh(), ['returned_at' => '2027-03-09 09:00'], $this->agent))->toThrow(ContractException::class, 'avant le départ');

    $returned = app(RegisterReturn::class)->handle($contract->fresh(), ['returned_at' => '2027-03-12 22:00'], $this->agent);

    expect($returned->returned_at?->equalTo(CarbonImmutable::parse('2027-03-12 22:00')))->toBeTrue()
        ->and($inspection->refresh()->returned_at_declared?->equalTo(CarbonImmutable::parse('2027-03-12 22:00')))->toBeTrue();
});

it('ne valide le retour que d\'un contrat en cours de son agence (RG-13)', function () {
    $contract = rentedContract();

    expect(fn () => app(RegisterReturn::class)->handle($contract, [], $this->agentSousse))->toThrow(ContractException::class, 'non autorisée');

    returnAt($contract, '2027-03-13 09:00');

    expect(fn () => app(RegisterReturn::class)->handle($contract->fresh(), [], $this->agent))->toThrow(ContractException::class, 'Restitué');
});

it('valide le retour depuis la tablette, avec l\'aperçu des frais', function () {
    $contract = rentedContract();
    CarbonImmutable::setTestNow('2027-03-13 10:01');
    $this->actingAs($this->agent);

    Livewire::test(InspectContract::class, ['record' => $contract->getRouteKey(), 'type' => 'return'])
        ->set('mileage', '42600')->call('saveReadings')
        ->call('setFuel', 8)
        ->call('saveSignature', returnSignature())
        ->assertSee('Retard : 1 journée(s) au tarif du contrat')
        ->assertSee('100,000 DT')
        ->call('validateReturn')
        ->assertRedirect(ContractResource::getUrl('view', ['record' => $contract]));

    expect($contract->fresh()?->status)->toBe(ContractStatus::Returned)
        ->and(chargesOf($contract))->toBe(['late_return' => '100.000']);
});
