<?php

use App\Domain\Rental\Actions\ExtendContract;
use App\Domain\Rental\Actions\OpenContract;
use App\Domain\Rental\Actions\RecordInspection;
use App\Domain\Rental\Actions\RegisterReturn;
use App\Domain\Rental\Actions\SaveContractDriver;
use App\Domain\Rental\Actions\StartRental;
use App\Domain\Rental\Alerts\LateReturnAlerts;
use App\Domain\Rental\ContractCustomerPhone;
use App\Domain\Rental\Exceptions\ContractException;
use App\Domain\Setup\Actions\SaveRatePlan;
use App\Domain\Setup\Actions\SaveSeason;
use App\Domain\Setup\Actions\UpdateSettings;
use App\Enums\InspectionType;
use App\Filament\Resources\Contracts\Pages\ViewContract;
use App\Models\Agency;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\VehicleDocument;
use App\Settings\ContractSettings;
use App\Settings\NotificationSettings;
use App\Support\Authorization\Role;
use App\Support\Files\PrivateFiles;
use App\Support\Sequences\SequenceAllocator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/*
 * Alerte de retard (A-09, lot L12) et téléphone du client sur un contrat en cours (Q4).
 * Contrat parti le 10/03/2027 à 09:00, retour prévu le 13/03 à 09:00, tolérance 60 min.
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
    $this->vehicle = Vehicle::factory()->create(['agency_id' => $this->sfax->id, 'vehicle_category_id' => $this->category->id, 'mileage' => 42000, 'plate_number' => '245 TU 1234'])->refresh();
    foreach (['insurance', 'technical_inspection'] as $type) {
        VehicleDocument::query()->create(['vehicle_id' => $this->vehicle->id, 'type' => $type, 'valid_from' => '2027-01-01', 'valid_until' => '2027-12-31']);
    }
    $this->customer = Customer::factory()->create(['origin_agency_id' => $this->sfax->id, 'first_name' => 'Amel', 'last_name' => 'Trabelsi', 'phone' => '20 111 222']);
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

function lateStart(Contract $contract): Contract
{
    $image = imagecreatetruecolor(200, 80);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
    imageline($image, 5, 70, 190, 10, (int) imagecolorallocate($image, 0, 0, 0));
    ob_start();
    imagepng($image);
    $signature = 'data:image/png;base64,'.base64_encode((string) ob_get_clean());

    $record = app(RecordInspection::class);
    $inspection = $record->open($contract, InspectionType::Departure, test()->agent);
    $record->update($inspection, ['mileage' => 42100, 'fuel_eighths' => 8], test()->agent);
    $record->sign($inspection, $signature, test()->agent);

    return app(StartRental::class)->handle($contract, test()->agent);
}

function lateNotifications(mixed $user): int
{
    return DatabaseNotification::query()->where('notifiable_id', $user->id)->count();
}

// --- Alerte de retard ----------------------------------------------------------------------

it('n\'alerte qu\'au-delà de la tolérance, une seule fois, l\'agence du contrat seulement', function () {
    lateStart($this->contract);
    $alerts = app(LateReturnAlerts::class);

    expect($alerts->run(CarbonImmutable::parse('2027-03-13 10:00')))->toBe(0)
        ->and($alerts->run(CarbonImmutable::parse('2027-03-13 10:01')))->toBe(1)
        ->and($alerts->run(CarbonImmutable::parse('2027-03-13 12:00')))->toBe(0)
        ->and(lateNotifications($this->agent))->toBe(1)
        ->and(lateNotifications($this->admin))->toBe(1)
        ->and(lateNotifications($this->agentSousse))->toBe(0)
        ->and(lateNotifications($this->superAdmin))->toBe(0);

    $data = json_encode(DatabaseNotification::query()->where('notifiable_id', $this->agent->id)->sole()->data, JSON_UNESCAPED_UNICODE);

    expect($data)->toContain(LateReturnAlerts::TITLE)
        ->not->toContain('Trabelsi')
        ->not->toContain('245 TU 1234')
        ->not->toContain($this->contract->number);
});

it('trace l\'échéance sans erreur quand aucun profil destinataire n\'est choisi', function () {
    lateStart($this->contract);
    $settings = app(NotificationSettings::class);
    $alerts = $settings->alerts;
    $alerts['late_return']['recipients'] = [];
    $settings->alerts = $alerts;
    $settings->save();

    expect(app(LateReturnAlerts::class)->run(CarbonImmutable::parse('2027-03-13 10:30')))->toBe(1)
        ->and(DatabaseNotification::query()->count())->toBe(0);
});

it('alerte de nouveau après une prolongation dont la nouvelle échéance est dépassée', function () {
    $contract = lateStart($this->contract);
    app(LateReturnAlerts::class)->run(CarbonImmutable::parse('2027-03-13 10:30'));

    CarbonImmutable::setTestNow('2027-03-13 11:00');
    app(ExtendContract::class)->handle($contract->fresh(), ['ends_at' => '2027-03-15 09:00'], $this->agent);

    expect(app(LateReturnAlerts::class)->run(CarbonImmutable::parse('2027-03-14 12:00')))->toBe(0)
        ->and(app(LateReturnAlerts::class)->run(CarbonImmutable::parse('2027-03-15 10:30')))->toBe(1)
        ->and(lateNotifications($this->agent))->toBe(2);
});

it('n\'alerte ni pour un contrat restitué, ni quand l\'alerte est désactivée', function () {
    $contract = lateStart($this->contract);

    $settings = app(NotificationSettings::class);
    $alerts = $settings->alerts;
    $alerts['late_return']['enabled'] = false;
    $settings->alerts = $alerts;
    $settings->save();

    expect(app(LateReturnAlerts::class)->run(CarbonImmutable::parse('2027-03-14 09:00')))->toBe(0);

    $alerts['late_return']['enabled'] = true;
    $settings->alerts = $alerts;
    $settings->save();

    CarbonImmutable::setTestNow('2027-03-13 09:00');
    $record = app(RecordInspection::class);
    $return = $record->open($contract->fresh(), InspectionType::Return, $this->agent);
    $record->update($return, ['mileage' => 42500, 'fuel_eighths' => 8], $this->agent);
    $record->markCustomerAbsent($return, 'Clés en boîte', $this->agent);
    app(RegisterReturn::class)->handle($contract->fresh(), [], $this->agent);

    expect(app(LateReturnAlerts::class)->run(CarbonImmutable::parse('2027-03-14 09:00')))->toBe(0);
});

// --- Téléphone du client (Q4) ----------------------------------------------------------------

it('montre le téléphone sur un contrat en cours de l\'agence et journalise la consultation, sans le numéro', function () {
    $phone = app(ContractCustomerPhone::class);

    expect(fn () => $phone->reveal($this->contract, $this->agent))->toThrow(ContractException::class, 'contrat en cours');

    $contract = lateStart($this->contract);

    expect($phone->reveal($contract, $this->agent))->toBe('20 111 222')
        ->and(fn () => $phone->reveal($contract, $this->agentSousse))->toThrow(ContractException::class);

    $log = Activity::query()->where('log_name', 'customer_access')->where('event', 'contract_phone_viewed')->sole();

    expect($log->causer_id)->toBe($this->agent->id)
        ->and($log->properties['customer_id'])->toBe($this->customer->id)
        ->and(json_encode($log->toArray()))->not->toContain('111 222');
});

it('masque le téléphone quand le réglage est désactivé (Q4)', function () {
    $contract = lateStart($this->contract);
    app(UpdateSettings::class)->handle(app(ContractSettings::class), ['show_phone_on_active_contract' => false], $this->superAdmin);

    expect(app(ContractCustomerPhone::class)->canReveal($contract, $this->agent))->toBeFalse()
        ->and(fn () => app(ContractCustomerPhone::class)->reveal($contract, $this->agent))->toThrow(ContractException::class);
});

it('affiche le téléphone depuis la fiche d\'un contrat en cours seulement', function () {
    $this->actingAs($this->agent);

    Livewire::test(ViewContract::class, ['record' => $this->contract->getRouteKey()])->assertActionHidden('customerPhone');

    $contract = lateStart($this->contract);

    Livewire::test(ViewContract::class, ['record' => $contract->getRouteKey()])
        ->callAction('customerPhone')
        ->assertNotified('Téléphone du client : 20 111 222');
});
