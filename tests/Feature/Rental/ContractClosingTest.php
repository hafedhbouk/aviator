<?php

use App\Domain\Approvals\Actions\DecideApproval;
use App\Domain\Crm\Actions\AddRecommendation;
use App\Domain\Rental\Actions\AmendContract;
use App\Domain\Rental\Actions\CloseContract;
use App\Domain\Rental\Actions\ExtendContract;
use App\Domain\Rental\Actions\OpenContract;
use App\Domain\Rental\Actions\RecordInspection;
use App\Domain\Rental\Actions\RegisterReturn;
use App\Domain\Rental\Actions\SaveContractDriver;
use App\Domain\Rental\Actions\StartRental;
use App\Domain\Rental\Exceptions\ContractException;
use App\Domain\Setup\Actions\SaveRatePlan;
use App\Domain\Setup\Actions\SaveSeason;
use App\Enums\ContractStatus;
use App\Enums\InspectionType;
use App\Filament\Resources\Contracts\Pages\ViewContract;
use App\Models\Agency;
use App\Models\ApprovalRequest;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\VehicleDocument;
use App\Support\Authorization\Role;
use App\Support\Files\PrivateFiles;
use App\Support\Sequences\SequenceAllocator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * Clôture et avenants (phase 5, lot L10, D-11, D49, RG-11) : clôture d'un contrat
 * restitué sans approbation en attente ; contrat clôturé non modifiable (domaine, modèle,
 * écran) ; avenant motivé, en ajout seul, limité aux mentions et aux conducteurs
 * additionnels ; portée par agence (RG-13).
 */

beforeEach(function () {
    CarbonImmutable::setTestNow('2027-03-10 09:00');
    Storage::fake(PrivateFiles::DISK);

    $this->superAdmin = withTwoFactor(userWithProfile(Role::SuperAdmin));
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
    $this->vehicle = Vehicle::factory()->create(['agency_id' => $this->sfax->id, 'vehicle_category_id' => $this->category->id, 'mileage' => 42000])->refresh();
    foreach (['insurance', 'technical_inspection'] as $type) {
        VehicleDocument::query()->create(['vehicle_id' => $this->vehicle->id, 'type' => $type, 'valid_from' => '2027-01-01', 'valid_until' => '2027-12-31']);
    }
    $this->customer = Customer::factory()->create(['origin_agency_id' => $this->sfax->id]);
    app(SequenceAllocator::class)->prepare(OpenContract::sequence());
});

afterEach(fn () => CarbonImmutable::setTestNow());

function clsSignature(): string
{
    $image = imagecreatetruecolor(200, 80);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
    imageline($image, 5, 70, 190, 10, (int) imagecolorallocate($image, 0, 0, 0));
    ob_start();
    imagepng($image);

    return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
}

/**
 * Contrat parti le 10/03 à 09:00 (retour prévu le 13/03), éventuellement restitué.
 */
function clsContract(bool $returned = true, ?callable $beforeReturn = null): Contract
{
    $agent = test()->agent;
    $record = app(RecordInspection::class);
    $contract = app(OpenContract::class)->atCounter([
        'customer_id' => test()->customer->id, 'agency_id' => test()->sfax->id, 'vehicle_id' => test()->vehicle->id,
        'starts_at' => '2027-03-10 09:00', 'ends_at' => '2027-03-13 09:00',
    ], $agent);
    app(SaveContractDriver::class)->handle($contract, [
        'first_name' => 'Amel', 'last_name' => 'Trabelsi', 'license_number' => '12/345678',
        'birth_date' => '1990-01-15', 'license_issued_on' => '2010-01-15', 'license_expires_on' => '2032-01-15', 'is_primary' => true,
    ], $agent);
    $departure = $record->open($contract, InspectionType::Departure, $agent);
    $record->update($departure, ['mileage' => 42100, 'fuel_eighths' => 8], $agent);
    $record->sign($departure, clsSignature(), $agent);
    $contract = app(StartRental::class)->handle($contract, $agent);

    if ($beforeReturn !== null) {
        $beforeReturn($contract);
    }

    if (! $returned) {
        return $contract->fresh();
    }

    CarbonImmutable::setTestNow('2027-03-13 09:00');
    $return = $record->open($contract->fresh(), InspectionType::Return, $agent);
    $record->update($return, ['mileage' => 42500, 'fuel_eighths' => 8], $agent);
    $record->sign($return, clsSignature(), $agent);

    return app(RegisterReturn::class)->handle($contract->fresh(), [], $agent);
}

/**
 * @return array<string, mixed>
 */
function clsDriverData(array $overrides = []): array
{
    return array_merge([
        'type' => ContractAmendment::ADDITIONAL_DRIVER, 'reason' => 'Conducteur déclaré par le client au retour',
        'first_name' => 'Sami', 'last_name' => 'Gharbi', 'license_number' => '12/999111',
        'birth_date' => '1985-04-02', 'license_issued_on' => '2005-06-01', 'license_expires_on' => '2031-01-01', 'license_country' => 'TN',
    ], $overrides);
}

// --- Clôture --------------------------------------------------------------------------------

it('clôture un contrat restitué, puis refuse toute modification (D-11)', function () {
    $contract = app(CloseContract::class)->handle(clsContract(), $this->agent);

    expect($contract->status)->toBe(ContractStatus::Closed)
        ->and($contract->closed_at)->not->toBeNull()
        ->and(fn () => $contract->forceFill(['total_amount' => '1.000'])->save())->toThrow(LogicException::class)
        ->and(fn () => $contract->refresh()->forceFill(['status' => ContractStatus::Returned])->save())->toThrow(LogicException::class)
        ->and(fn () => app(CloseContract::class)->handle($contract->fresh(), $this->agent))->toThrow(ContractException::class, 'Clôturé')
        ->and(fn () => app(SaveContractDriver::class)->handle($contract->fresh(), ['first_name' => 'X', 'last_name' => 'Y', 'license_number' => '1', 'birth_date' => '1990-01-01', 'license_issued_on' => '2010-01-01'], $this->agent))
        ->toThrow(ContractException::class, 'Clôturé')
        ->and(fn () => app(ExtendContract::class)->handle($contract->fresh(), ['ends_at' => '2027-03-20 09:00'], $this->agent))->toThrow(ContractException::class, 'Clôturé');
});

it('ne clôture qu\'un contrat restitué', function () {
    $contract = clsContract(returned: false);

    expect(fn () => app(CloseContract::class)->handle($contract, $this->agent))->toThrow(ContractException::class, 'En cours');
});

it('refuse la clôture tant qu\'une approbation attend le super admin (D49)', function () {
    $contract = clsContract(beforeReturn: fn (Contract $c) => app(ExtendContract::class)->handle($c, [
        'ends_at' => '2027-03-14 09:00', 'discount_amount' => '20,000', 'discount_reason' => 'Geste commercial pour le retard du vol',
    ], test()->agent));

    expect(fn () => app(CloseContract::class)->handle($contract, $this->agent))->toThrow(ContractException::class, 'approbation');

    app(DecideApproval::class)->approve(ApprovalRequest::query()->sole(), $this->superAdmin);

    expect(app(CloseContract::class)->handle($contract->fresh(), $this->agent)->status)->toBe(ContractStatus::Closed);
});

it('masque les actions de modification sur la fiche d\'un contrat clôturé', function () {
    $contract = app(CloseContract::class)->handle(clsContract(), $this->agent);
    $this->actingAs($this->agent);

    Livewire::test(ViewContract::class, ['record' => $contract->getRouteKey()])
        ->assertActionHidden('extend')
        ->assertActionHidden('cancel')
        ->assertActionHidden('addDriver')
        ->assertActionHidden('close')
        ->assertActionVisible('amend');
});

it('clôture depuis la fiche du contrat', function () {
    $contract = clsContract();
    $this->actingAs($this->agent);

    Livewire::test(ViewContract::class, ['record' => $contract->getRouteKey()])
        ->callAction('close')
        ->assertHasNoActionErrors()
        ->assertNotified('Contrat clôturé : il n\'est plus modifiable.');

    expect($contract->fresh()?->status)->toBe(ContractStatus::Closed);
});

// --- Avenants -------------------------------------------------------------------------------

it('enregistre une mention corrective par avenant motivé, en ajout seul', function () {
    $contract = app(CloseContract::class)->handle(clsContract(), $this->agent);

    expect(fn () => app(AmendContract::class)->handle($contract, ['type' => 'note', 'reason' => 'court', 'note' => 'x'], $this->agent))->toThrow(ContractException::class, 'Motif')
        ->and(fn () => app(AmendContract::class)->handle($contract, ['type' => 'price', 'reason' => 'Baisse du prix demandée'], $this->agent))->toThrow(ContractException::class, 'facture complémentaire')
        ->and(fn () => app(AmendContract::class)->handle($contract, ['type' => 'note', 'reason' => 'Correction de la saisie', 'note' => ''], $this->agent))->toThrow(ValidationException::class);

    $amendment = app(AmendContract::class)->handle($contract, [
        'type' => 'note', 'reason' => 'Erreur de saisie de l\'adresse de livraison', 'note' => 'Le véhicule a été remis à l\'hôtel Sangho, Zarzis.',
    ], $this->agent);

    expect($amendment->changes)->toBe(['note' => 'Le véhicule a été remis à l\'hôtel Sangho, Zarzis.'])
        ->and($amendment->created_by)->toBe($this->agent->id)
        ->and(fn () => $amendment->forceFill(['reason' => 'Autre'])->save())->toThrow(LogicException::class)
        ->and(fn () => $amendment->refresh()->delete())->toThrow(LogicException::class)
        ->and($contract->fresh()?->status)->toBe(ContractStatus::Closed);
});

it('n\'accepte d\'avenant que sur un contrat clôturé', function () {
    $contract = clsContract();

    expect(fn () => app(AmendContract::class)->handle($contract, ['type' => 'note', 'reason' => 'Correction de la saisie', 'note' => 'Texte'], $this->agent))
        ->toThrow(ContractException::class, 'non clôturé');
});

it('ajoute par avenant un conducteur additionnel contrôlé, jamais principal (Q48)', function () {
    $contract = app(CloseContract::class)->handle(clsContract(), $this->agent);

    $amendment = app(AmendContract::class)->handle($contract, clsDriverData(), $this->agent);
    $driver = Driver::query()->findOrFail($amendment->changes['driver']['driver_id']);
    $pivot = $contract->drivers()->where('drivers.id', $driver->id)->sole()->getRelationValue('pivot');

    expect($driver->customer_id)->toBe($this->customer->id)
        ->and((bool) $pivot->getAttribute('is_primary'))->toBeFalse()
        ->and($contract->drivers()->count())->toBe(2);

    expect(fn () => app(AmendContract::class)->handle($contract, clsDriverData(['license_number' => '12/888111', 'birth_date' => '2010-01-01']), $this->agent))
        ->toThrow(ContractException::class, 'âge minimal');

    $blocked = Customer::factory()->create(['origin_agency_id' => $this->sousse->id]);
    Driver::query()->create(['customer_id' => $blocked->id, 'first_name' => 'X', 'last_name' => 'Y', 'license_number' => '77/777777']);
    app(AddRecommendation::class)->handle($blocked, ['level' => 'blacklist', 'reason_code' => 'fraude'], $this->superAdmin);

    expect(fn () => app(AmendContract::class)->handle($contract, clsDriverData(['license_number' => '77/777777']), $this->agent))
        ->toThrow(ContractException::class, 'Contactez le super admin')
        ->and(ContractAmendment::query()->count())->toBe(1);
});

it('réserve la clôture et les avenants à l\'agence du contrat (RG-13)', function () {
    $contract = clsContract();

    expect(fn () => app(CloseContract::class)->handle($contract, $this->agentSousse))->toThrow(ContractException::class, 'non autorisée');

    app(CloseContract::class)->handle($contract, $this->agent);

    expect(fn () => app(AmendContract::class)->handle($contract->fresh(), ['type' => 'note', 'reason' => 'Correction de la saisie', 'note' => 'x'], $this->agentSousse))
        ->toThrow(ContractException::class, 'non autorisée');
});
