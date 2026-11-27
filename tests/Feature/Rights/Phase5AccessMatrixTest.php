<?php

use App\Domain\Rental\Actions\AmendContract;
use App\Domain\Rental\Actions\CancelContract;
use App\Domain\Rental\Actions\CloseContract;
use App\Domain\Rental\Actions\ExtendContract;
use App\Domain\Rental\Actions\ManageDamageFile;
use App\Domain\Rental\Actions\OpenContract;
use App\Domain\Rental\Actions\RecordInspection;
use App\Domain\Rental\Actions\RegisterReturn;
use App\Domain\Rental\Actions\SaveContractDriver;
use App\Domain\Rental\Actions\StartRental;
use App\Domain\Rental\ContractCustomerPhone;
use App\Domain\Rental\Exceptions\ContractException;
use App\Domain\Setup\Actions\SaveRatePlan;
use App\Domain\Setup\Actions\SaveSeason;
use App\Enums\InspectionType;
use App\Filament\Pages\DailyMovements;
use App\Filament\Pages\Settings\ContractSettingsPage;
use App\Filament\Pages\Settings\InspectionSettingsPage;
use App\Filament\Resources\ApprovalRequests\ApprovalRequestResource;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Contracts\Pages\InspectContract;
use App\Filament\Resources\Contracts\Pages\ViewContract;
use App\Models\Agency;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\VehicleDocument;
use App\Support\Authorization\Role;
use App\Support\Files\PrivateFiles;
use App\Support\Sequences\SequenceAllocator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * Droits de la phase 5 (lot L13) : matrice par profil sur les nouveaux écrans, et
 * **scénario 12** rejoué sur le contrat (un agent de Sfax agit sur un contrat de Sousse) à
 * trois niveaux : policy, action de domaine, requête directe (page, Livewire, document).
 * Chaque domaine est aussi couvert par les tests de son lot.
 */

beforeEach(function () {
    CarbonImmutable::setTestNow('2027-03-01 08:00');
    Storage::fake(PrivateFiles::DISK);

    $this->sfax = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->sousse = Agency::factory()->create(['code' => 'SOU', 'name' => 'Sousse']);
    $this->users = [
        'super admin' => withTwoFactor(userWithProfile(Role::SuperAdmin)),
        'admin' => withTwoFactor(userWithProfile(Role::Admin)),
        'agent de Sfax' => userWithProfile(Role::AgencyUser, ['agency_id' => $this->sfax->id]),
    ];
    $this->agentSousse = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sousse->id]);

    $this->category = VehicleCategory::factory()->create(['code' => 'CIT', 'name' => 'Citadine']);
    $season = app(SaveSeason::class)->handle(null, ['name' => 'Année 2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31'], $this->users['admin']);
    app(SaveRatePlan::class)->handle(null, [
        'vehicle_category_id' => $this->category->id, 'season_id' => $season->id, 'agency_id' => null,
        'valid_from' => '2027-01-01', 'valid_to' => null, 'is_active' => true,
        'lines' => [['min_days' => 1, 'max_days' => null, 'daily_amount' => '100,000']],
    ], $this->users['admin']);
    app(SequenceAllocator::class)->prepare(OpenContract::sequence());

    $this->sfaxContract = matrixContract($this->sfax, $this->users['agent de Sfax']);
    $this->sousseContract = matrixContract($this->sousse, $this->agentSousse);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function matrixContract(Agency $agency, User $agent): Contract
{
    $vehicle = Vehicle::factory()->create(['agency_id' => $agency->id, 'vehicle_category_id' => test()->category->id, 'mileage' => 1000]);
    foreach (['insurance', 'technical_inspection'] as $type) {
        VehicleDocument::query()->create(['vehicle_id' => $vehicle->id, 'type' => $type, 'valid_from' => '2027-01-01', 'valid_until' => '2027-12-31']);
    }

    return app(OpenContract::class)->atCounter([
        'customer_id' => Customer::factory()->create(['origin_agency_id' => $agency->id])->id, 'agency_id' => $agency->id,
        'vehicle_id' => $vehicle->id, 'starts_at' => '2027-03-10 09:00', 'ends_at' => '2027-03-13 09:00',
    ], $agent);
}

/**
 * Code HTTP attendu pour chaque écran : super admin, admin, agent de Sfax.
 *
 * @return array<string, array{callable(): string, int, int, int}>
 */
function phase5Screens(): array
{
    return [
        'contrats : liste' => [fn () => ContractResource::getUrl('index'), 200, 200, 200],
        'contrats : fiche de Sfax' => [fn () => ContractResource::getUrl('view', ['record' => test()->sfaxContract]), 200, 200, 200],
        'contrats : fiche de Sousse' => [fn () => ContractResource::getUrl('view', ['record' => test()->sousseContract]), 200, 200, 404],
        'état des lieux de Sfax' => [fn () => ContractResource::getUrl('inspect', ['record' => test()->sfaxContract, 'type' => 'departure']), 200, 200, 200],
        'état des lieux de Sousse' => [fn () => ContractResource::getUrl('inspect', ['record' => test()->sousseContract, 'type' => 'departure']), 200, 200, 404],
        'départs et retours du jour' => [fn () => DailyMovements::getUrl(), 200, 200, 200],
        'approbations' => [fn () => ApprovalRequestResource::getUrl('index'), 200, 403, 403],
        'réglages : contrats' => [fn () => ContractSettingsPage::getUrl(), 200, 403, 403],
        'réglages : états des lieux' => [fn () => InspectionSettingsPage::getUrl(), 200, 403, 403],
        'PDF du contrat de Sfax' => [fn () => route('filament.admin.documents.contract', ['contract' => test()->sfaxContract->id]), 200, 200, 200],
        'PDF du contrat de Sousse' => [fn () => route('filament.admin.documents.contract', ['contract' => test()->sousseContract->id]), 200, 200, 404],
        'conditions générales' => [fn () => route('filament.admin.documents.terms'), 200, 200, 200],
    ];
}

it('applique la matrice des droits de la phase 5 à chaque profil', function (string $profile, int $column) {
    $this->actingAs($this->users[$profile]);
    $failures = [];

    foreach (phase5Screens() as $screen => $expected) {
        $status = $this->get($expected[0]())->status();

        if ($status !== $expected[$column]) {
            $failures[] = "{$screen} : {$status} au lieu de {$expected[$column]}";
        }
    }

    expect($failures)->toBe([]);
})->with([
    'super admin' => ['super admin', 1],
    'admin' => ['admin', 2],
    'agent de Sfax' => ['agent de Sfax', 3],
]);

// --- Scénario 12 : un agent de Sfax agit sur un contrat de Sousse ----------------------------

it('refuse à l\'agent de Sfax le contrat de Sousse au niveau de la policy', function () {
    $agent = $this->users['agent de Sfax'];

    expect($agent->can('view', $this->sousseContract))->toBeFalse()
        ->and($agent->can('update', $this->sousseContract))->toBeFalse()
        ->and($agent->can('delete', $this->sousseContract))->toBeFalse()
        ->and($agent->can('view', $this->sfaxContract))->toBeTrue();
});

it('refuse à l\'agent de Sfax chaque action du domaine sur le contrat de Sousse', function (Closure $action) {
    expect(fn () => $action($this->sousseContract, $this->users['agent de Sfax']))->toThrow(ContractException::class, 'non autorisée');
})->with([
    'annulation' => [fn (Contract $c, User $u) => app(CancelContract::class)->handle($c, 'Annulation par une autre agence', $u)],
    'conducteur' => [fn (Contract $c, User $u) => app(SaveContractDriver::class)->handle($c, ['first_name' => 'X', 'last_name' => 'Y', 'license_number' => '1/1', 'birth_date' => '1990-01-01', 'license_issued_on' => '2010-01-01'], $u)],
    'état des lieux' => [fn (Contract $c, User $u) => app(RecordInspection::class)->open($c, InspectionType::Departure, $u)],
    'départ' => [fn (Contract $c, User $u) => app(StartRental::class)->handle($c, $u)],
    'prolongation' => [fn (Contract $c, User $u) => app(ExtendContract::class)->handle($c, ['ends_at' => '2027-03-20 09:00'], $u)],
    'retour' => [fn (Contract $c, User $u) => app(RegisterReturn::class)->handle($c, [], $u)],
    'clôture' => [fn (Contract $c, User $u) => app(CloseContract::class)->handle($c, $u)],
    'avenant' => [fn (Contract $c, User $u) => app(AmendContract::class)->handle($c, ['type' => 'note', 'reason' => 'Correction de la saisie', 'note' => 'x'], $u)],
    'dossier de dommage' => [fn (Contract $c, User $u) => app(ManageDamageFile::class)->open($c, 1, $u)],
    'téléphone du client' => [fn (Contract $c, User $u) => app(ContractCustomerPhone::class)->reveal($c, $u)],
]);

it('refuse à l\'agent de Sfax les pages Livewire du contrat de Sousse, même appelées directement', function () {
    $this->actingAs($this->users['agent de Sfax']);

    Livewire::test(ViewContract::class, ['record' => $this->sousseContract->getRouteKey()])->assertNotFound();
    Livewire::test(InspectContract::class, ['record' => $this->sousseContract->getRouteKey(), 'type' => 'departure'])->assertNotFound();

    expect($this->sousseContract->inspections()->count())->toBe(0);
});
