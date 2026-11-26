<?php

use App\Domain\Crm\Actions\AddRecommendation;
use App\Domain\Rental\Actions\CancelContract;
use App\Domain\Rental\Actions\OpenContract;
use App\Domain\Rental\Actions\RemoveContractDriver;
use App\Domain\Rental\Actions\SaveContractDriver;
use App\Domain\Rental\ContractDrivers;
use App\Domain\Rental\Exceptions\ContractException;
use App\Domain\Setup\Actions\SaveRatePlan;
use App\Domain\Setup\Actions\SaveSeason;
use App\Filament\Resources\Contracts\Pages\ViewContract;
use App\Models\Agency;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\VehicleDocument;
use App\Support\Authorization\Role;
use App\Support\Sequences\SequenceAllocator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * Conducteurs d'un contrat (phase 5, lot L3, D-02, D-03, Q42, Q47, Q48) : âge et
 * ancienneté du permis au départ (réglage général 21 ans et 2 ans, surcharge de la
 * catégorie), permis valide jusqu'au retour, permis étranger signalé, un seul
 * conducteur principal, liste noire contrôlée par le permis et la pièce.
 * Contrat du 10/03/2027 09:00 au 13/03/2027 09:00.
 */

beforeEach(function () {
    CarbonImmutable::setTestNow('2027-03-01 08:00');

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

    $vehicle = Vehicle::factory()->create(['agency_id' => $this->sfax->id, 'vehicle_category_id' => $this->category->id]);
    foreach (['insurance', 'technical_inspection'] as $type) {
        VehicleDocument::query()->create(['vehicle_id' => $vehicle->id, 'type' => $type, 'valid_from' => '2027-01-01', 'valid_until' => '2027-12-31']);
    }

    $this->customer = Customer::factory()->create(['origin_agency_id' => $this->sfax->id]);
    app(SequenceAllocator::class)->prepare(OpenContract::sequence());
    $this->contract = app(OpenContract::class)->atCounter([
        'customer_id' => $this->customer->id, 'agency_id' => $this->sfax->id, 'vehicle_id' => $vehicle->id,
        'starts_at' => '2027-03-10 09:00', 'ends_at' => '2027-03-13 09:00',
    ], $this->agent);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * Conducteur conforme par défaut : 30 ans, permis de 10 ans valable jusqu'en 2030.
 */
function contractDriverData(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Amel', 'last_name' => 'Trabelsi', 'license_number' => '12/345678',
        'birth_date' => '1997-01-15', 'license_issued_on' => '2017-01-15', 'license_expires_on' => '2030-01-15',
        'license_country' => 'TN', 'is_primary' => true,
    ], $overrides);
}

function addContractDriver(array $overrides = [], ?Contract $contract = null): Driver
{
    return app(SaveContractDriver::class)->handle($contract ?? test()->contract, contractDriverData($overrides), test()->agent);
}

it('ajoute le conducteur principal, rattaché au client du contrat, avec le résultat des contrôles', function () {
    $driver = addContractDriver();
    $pivot = $this->contract->drivers()->sole()->getRelationValue('pivot');
    $checks = json_decode((string) $pivot->getAttribute('checks'), true);

    expect($driver->customer_id)->toBe($this->customer->id)
        ->and((bool) $pivot->getAttribute('is_primary'))->toBeTrue()
        ->and($checks['refusals'])->toBe([])
        ->and($checks['age'])->toBe(30)
        ->and($checks['license_years'])->toBe(10)
        ->and($checks['warnings'])->toBe([]);
});

it('applique l\'âge minimal au jour du départ, jour anniversaire compris (Q42)', function () {
    expect(addContractDriver(['birth_date' => '2006-03-10'])->id)->toBeInt();

    expect(fn () => addContractDriver(['birth_date' => '2006-03-11', 'license_number' => '99/000001', 'license_issued_on' => '2024-01-01']))
        ->toThrow(ContractException::class, 'âge minimal de 21 ans');
});

it('applique l\'ancienneté minimale du permis au jour du départ', function () {
    expect(addContractDriver(['license_issued_on' => '2025-03-10'])->id)->toBeInt();

    expect(fn () => addContractDriver(['license_issued_on' => '2025-03-11', 'license_number' => '99/000002']))
        ->toThrow(ContractException::class, 'permis de moins de 2 an(s)');
});

it('applique la surcharge de la catégorie (Q42)', function () {
    $this->category->forceFill(['min_driver_age' => 25, 'min_license_years' => 5])->save();

    expect(fn () => addContractDriver(['birth_date' => '2003-01-01']))->toThrow(ContractException::class, 'âge minimal de 25 ans')
        ->and(fn () => addContractDriver(['license_issued_on' => '2023-01-01', 'license_number' => '99/000003']))->toThrow(ContractException::class, 'moins de 5 an(s)');
});

it('refuse un permis expiré ou qui expire avant le retour prévu (D-03)', function () {
    expect(fn () => addContractDriver(['license_expires_on' => '2027-02-28']))->toThrow(ContractException::class, 'permis expiré')
        ->and(fn () => addContractDriver(['license_expires_on' => '2027-03-12', 'license_number' => '99/000004']))->toThrow(ContractException::class, 'avant le retour prévu');

    expect(addContractDriver(['license_expires_on' => '2027-03-13', 'license_number' => '99/000005'])->id)->toBeInt();
});

it('accepte un permis étranger ou sans échéance en le signalant', function () {
    addContractDriver(['license_country' => 'fr', 'license_expires_on' => null]);
    $checks = json_decode((string) $this->contract->drivers()->sole()->getRelationValue('pivot')->getAttribute('checks'), true);

    expect($checks['warnings'])->toHaveCount(2)
        ->and(implode(' ', $checks['warnings']))->toContain('Permis étranger (FR)')->toContain('non renseignée');
});

it('complète la fiche d\'un conducteur déjà connu, et refuse sans dates', function () {
    $known = Driver::query()->create(['customer_id' => $this->customer->id, 'first_name' => 'Amel', 'last_name' => 'Trabelsi', 'license_number' => '12/999999']);

    expect(fn () => app(SaveContractDriver::class)->handle($this->contract, ['driver_id' => $known->id, 'is_primary' => true], $this->agent))
        ->toThrow(ContractException::class, 'date de naissance non renseignée');

    app(SaveContractDriver::class)->handle($this->contract, [
        'driver_id' => $known->id, 'birth_date' => '1990-05-05', 'license_issued_on' => '2010-05-05', 'is_primary' => true,
    ], $this->agent);

    expect($known->fresh()?->birth_date?->toDateString())->toBe('1990-05-05')
        ->and($this->contract->drivers()->sole()->id)->toBe($known->id);
});

it('refuse le conducteur d\'un autre client', function () {
    $other = Driver::query()->create([
        'customer_id' => Customer::factory()->create(['origin_agency_id' => $this->sfax->id])->id,
        'first_name' => 'Sami', 'last_name' => 'Gharbi', 'license_number' => '12/111111', 'birth_date' => '1990-01-01', 'license_issued_on' => '2010-01-01',
    ]);

    expect(fn () => app(SaveContractDriver::class)->handle($this->contract, ['driver_id' => $other->id], $this->agent))
        ->toThrow(ContractException::class, 'n\'appartient pas au client');
});

it('ne garde qu\'un conducteur principal et retire un conducteur', function () {
    $first = addContractDriver();
    $second = addContractDriver(['first_name' => 'Sami', 'last_name' => 'Gharbi', 'license_number' => '12/222222']);
    $primaries = fn () => $this->contract->drivers()->get()->filter(fn (Driver $d): bool => (bool) $d->getRelationValue('pivot')->getAttribute('is_primary'))->pluck('id')->all();

    expect($primaries())->toBe([$second->id]);

    app(RemoveContractDriver::class)->handle($this->contract, $second, $this->agent);

    expect($this->contract->drivers()->pluck('drivers.id')->all())->toBe([$first->id])
        ->and($second->fresh())->not->toBeNull();
});

it('refuse un conducteur dont le permis ou la pièce figure chez un client sur liste noire (Q48)', function () {
    $blocked = Customer::factory()->create(['origin_agency_id' => $this->sousse->id]);
    Driver::query()->create([
        'customer_id' => $blocked->id, 'first_name' => 'Nom', 'last_name' => 'Différent',
        'license_number' => '77/777777', 'id_document_number' => '07777777',
    ]);
    app(AddRecommendation::class)->handle($blocked, ['level' => 'blacklist', 'reason_code' => 'fraude', 'comment' => 'Motif confidentiel'], $this->superAdmin);

    expect(fn () => addContractDriver(['license_number' => '77/777777']))->toThrow(ContractException::class, 'Contactez le super admin')
        ->and(fn () => addContractDriver(['license_number' => '12/000000', 'id_document_number' => '07777777']))->toThrow(ContractException::class, 'Contactez le super admin')
        ->and($this->contract->drivers()->count())->toBe(0);

    try {
        addContractDriver(['license_number' => '77/777777']);
    } catch (ContractException $exception) {
        expect($exception->getMessage())->not->toContain('fraude')->not->toContain('confidentiel');
    }
});

it('exige un conducteur principal au départ et contrôle de nouveau chaque conducteur à la date réelle', function () {
    $ready = app(ContractDrivers::class);
    $departure = CarbonImmutable::parse('2027-03-10 09:00');

    expect(fn () => $ready->assertReadyForDeparture($this->contract, $departure))->toThrow(ContractException::class, 'conducteur principal');

    addContractDriver(['is_primary' => false]);
    expect(fn () => $ready->assertReadyForDeparture($this->contract, $departure))->toThrow(ContractException::class, 'conducteur principal');

    $driver = addContractDriver(['license_number' => '12/333333']);
    expect(fn () => $ready->assertReadyForDeparture($this->contract, $departure))->not->toThrow(ContractException::class);

    // Le permis a expiré entre-temps : refus au départ.
    $driver->forceFill(['license_expires_on' => '2027-03-05'])->save();
    expect(fn () => $ready->assertReadyForDeparture($this->contract, $departure))->toThrow(ContractException::class, 'permis expiré');
});

it('ne modifie les conducteurs que d\'un brouillon de son agence (RG-13)', function () {
    expect(fn () => app(SaveContractDriver::class)->handle($this->contract, contractDriverData(), $this->agentSousse))
        ->toThrow(ContractException::class, 'non autorisée');

    app(CancelContract::class)->handle($this->contract, 'Le client a renoncé', $this->agent);

    expect(fn () => addContractDriver(contract: $this->contract->fresh()))->toThrow(ContractException::class, 'Annulé');
});

it('ajoute un conducteur depuis la fiche du contrat', function () {
    $this->actingAs($this->agent);

    Livewire::test(ViewContract::class, ['record' => $this->contract->getRouteKey()])
        ->callAction('addDriver', contractDriverData())
        ->assertHasNoActionErrors()
        ->assertNotified('Conducteur enregistré.');

    Livewire::test(ViewContract::class, ['record' => $this->contract->getRouteKey()])
        ->callAction('addDriver', contractDriverData(['first_name' => 'Jeune', 'birth_date' => '2010-01-01', 'license_number' => '99/999999']))
        ->assertNotified('Conducteur Jeune Trabelsi refusé : âge minimal de 21 ans non atteint au départ (17 ans).');

    expect($this->contract->drivers()->count())->toBe(1);
});
