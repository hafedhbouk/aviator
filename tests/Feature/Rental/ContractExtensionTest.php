<?php

use App\Domain\Approvals\Actions\DecideApproval;
use App\Domain\Approvals\Exceptions\ApprovalException;
use App\Domain\Availability\Actions\SaveReservation;
use App\Domain\Availability\Exceptions\ReservationException;
use App\Domain\Availability\Exceptions\VehicleUnavailable;
use App\Domain\Rental\Actions\ExtendContract;
use App\Domain\Rental\Actions\OpenContract;
use App\Domain\Rental\Actions\RecordInspection;
use App\Domain\Rental\Actions\SaveContractDriver;
use App\Domain\Rental\Actions\StartRental;
use App\Domain\Rental\Exceptions\ContractException;
use App\Domain\Setup\Actions\SaveRatePlan;
use App\Domain\Setup\Actions\SaveSeason;
use App\Enums\ApprovalStatus;
use App\Enums\InspectionType;
use App\Filament\Resources\Contracts\Pages\ViewContract;
use App\Models\Agency;
use App\Models\ApprovalRequest;
use App\Models\Contract;
use App\Models\ContractExtension;
use App\Models\Customer;
use App\Models\Option;
use App\Models\TaxCode;
use App\Models\Vehicle;
use App\Models\VehicleBlock;
use App\Models\VehicleCategory;
use App\Models\VehicleDocument;
use App\Support\Authorization\Role;
use App\Support\Files\PrivateFiles;
use App\Support\Sequences\SequenceAllocator;
use Carbon\CarbonImmutable;
use Database\Seeders\FiscalReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * Prolongation (phase 5, lot L7, D-07, D48, Q93, Q94) et **scénario 3** : contrat prolongé
 * avec contrôle de disponibilité sur la nouvelle période, délai de préparation compris
 * (60 min, réglage général). Contrat du 10/03/2027 09:00 au 13/03/2027 09:00, 100 DT par
 * jour, parti le 10/03 à 09:00. La concurrence est dans tests/Feature/Concurrency.
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

/**
 * Contrat parti : conducteur, état des lieux de départ, départ validé.
 *
 * @param  array<int, int>  $options
 */
function activeContract(array $options = []): Contract
{
    $contract = app(OpenContract::class)->atCounter([
        'customer_id' => test()->customer->id, 'agency_id' => test()->sfax->id, 'vehicle_id' => test()->vehicle->id,
        'starts_at' => '2027-03-10 09:00', 'ends_at' => '2027-03-13 09:00', 'options' => $options,
    ], test()->agent);
    app(SaveContractDriver::class)->handle($contract, [
        'first_name' => 'Amel', 'last_name' => 'Trabelsi', 'license_number' => '12/345678',
        'birth_date' => '1990-01-15', 'license_issued_on' => '2010-01-15', 'license_expires_on' => '2032-01-15', 'is_primary' => true,
    ], test()->agent);

    $record = app(RecordInspection::class);
    $inspection = $record->open($contract, InspectionType::Departure, test()->agent);
    $record->update($inspection, ['mileage' => 42100, 'fuel_eighths' => 8], test()->agent);
    $image = imagecreatetruecolor(200, 80);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
    imageline($image, 5, 70, 190, 10, (int) imagecolorallocate($image, 0, 0, 0));
    ob_start();
    imagepng($image);
    $record->sign($inspection, 'data:image/png;base64,'.base64_encode((string) ob_get_clean()), test()->agent);

    return app(StartRental::class)->handle($contract, test()->agent);
}

function extend(Contract $contract, string $endsAt, array $extra = []): ContractExtension
{
    return app(ExtendContract::class)->handle($contract, ['ends_at' => $endsAt] + $extra, test()->agent);
}

function competingReservation(string $from, string $to): mixed
{
    return app(SaveReservation::class)->handle(null, [
        'customer_id' => Customer::factory()->create(['origin_agency_id' => test()->sfax->id])->id, 'agency_id' => test()->sfax->id,
        'vehicle_category_id' => test()->category->id, 'vehicle_id' => test()->vehicle->id, 'starts_at' => $from, 'ends_at' => $to,
    ], test()->agent);
}

it('prolonge un contrat sur une période libre, au tarif journalier figé (scénario 3, D48)', function () {
    $contract = activeContract();
    $extension = extend($contract, '2027-03-15 09:00');
    $contract->refresh();

    expect($extension->added_days)->toBe(2)
        ->and((string) $extension->added_rental_amount)->toBe('200.000')
        ->and((string) $extension->total_amount)->toBe('200.000')
        ->and($extension->previous_ends_at->equalTo(CarbonImmutable::parse('2027-03-13 09:00')))->toBeTrue()
        ->and($contract->ends_at->equalTo(CarbonImmutable::parse('2027-03-15 09:00')))->toBeTrue()
        ->and((string) $contract->rental_amount)->toBe('500.000')
        ->and((string) $contract->total_amount)->toBe('500.000');

    // L'occupation couvre la nouvelle période et le délai de préparation (60 min).
    $block = VehicleBlock::query()->where('active', true)->sole();
    expect($block->ends_at->equalTo(CarbonImmutable::parse('2027-03-15 10:00')))->toBeTrue();

    // Une réservation sur la période ajoutée est désormais refusée.
    expect(fn () => competingReservation('2027-03-14 09:00', '2027-03-16 09:00'))->toThrow(ReservationException::class);
});

it('refuse la prolongation qui chevauche une réservation, délai de préparation compris (scénario 3)', function () {
    $contract = activeContract();
    competingReservation('2027-03-14 09:00', '2027-03-16 09:00');

    expect(fn () => extend($contract, '2027-03-15 09:00'))->toThrow(VehicleUnavailable::class)
        // 08:01 + 60 min de préparation dépasse 09:00 : refus.
        ->and(fn () => extend($contract, '2027-03-14 08:01'))->toThrow(VehicleUnavailable::class)
        ->and($contract->fresh()?->ends_at->equalTo(CarbonImmutable::parse('2027-03-13 09:00')))->toBeTrue()
        ->and(ContractExtension::query()->count())->toBe(0);

    // 08:00 + 60 min = 09:00 : le véhicule est prêt pour la réservation.
    expect(extend($contract, '2027-03-14 08:00')->added_days)->toBe(1);
});

it('refuse une date de retour antérieure et un contrat qui n\'est pas en cours', function () {
    $draft = app(OpenContract::class)->atCounter([
        'customer_id' => $this->customer->id, 'agency_id' => $this->sfax->id, 'vehicle_id' => $this->vehicle->id,
        'starts_at' => '2027-04-10 09:00', 'ends_at' => '2027-04-12 09:00',
    ], $this->agent);

    expect(fn () => extend($draft, '2027-04-15 09:00'))->toThrow(ContractException::class, 'Brouillon');

    $contract = activeContract();
    expect(fn () => extend($contract, '2027-03-13 09:00'))->toThrow(ContractException::class, 'postérieure')
        ->and(fn () => extend($contract, '2027-03-12 09:00'))->toThrow(ContractException::class, 'postérieure');
});

it('prolonge les options par jour au prix figé, dans la limite du plafond (Q94)', function () {
    $this->seed(FiscalReferenceSeeder::class);
    $seat = Option::query()->create([
        'code' => 'CHILD_SEAT', 'name' => 'Siège enfant', 'pricing_unit' => Option::PER_DAY, 'amount' => '8.000',
        'max_amount_per_rental' => '40.000', 'tax_code_id' => TaxCode::query()->where('code', 'CHILD_SEAT')->value('id'), 'is_active' => true,
    ]);
    $contract = activeContract([$seat->id => 1]);

    expect((string) $contract->options_amount)->toBe('24.000');

    // +1 jour : 8 DT. Puis +3 jours : 32 → 56, plafonné à 40, donc 8 DT seulement.
    expect((string) extend($contract, '2027-03-14 09:00')->added_options_amount)->toBe('8.000')
        ->and((string) extend($contract->fresh(), '2027-03-17 09:00')->added_options_amount)->toBe('8.000')
        ->and((string) $contract->fresh()?->options_amount)->toBe('40.000');
});

it('soumet au super admin une remise sur la prolongation au-delà du seuil, et la retire en cas de refus (Q93)', function () {
    $contract = activeContract();

    expect(fn () => extend($contract, '2027-03-15 09:00', ['discount_amount' => '20,000']))->toThrow(ApprovalException::class, 'Motif obligatoire')
        ->and(ContractExtension::query()->count())->toBe(0);

    $extension = extend($contract, '2027-03-15 09:00', ['discount_amount' => '20,000', 'discount_reason' => 'Prolongation pour panne du vol retour']);
    $request = ApprovalRequest::query()->sole();

    expect((string) $extension->total_amount)->toBe('180.000')
        ->and($extension->discount_approval_id)->toBe($request->id)
        ->and((string) $contract->fresh()?->total_amount)->toBe('480.000');

    app(DecideApproval::class)->reject($request, $this->superAdmin, 'Remise non justifiée');

    expect($request->fresh()?->status)->toBe(ApprovalStatus::Rejected)
        ->and((string) $extension->fresh()?->discount_amount)->toBe('0.000')
        ->and((string) $extension->fresh()?->total_amount)->toBe('200.000')
        ->and((string) $contract->fresh()?->total_amount)->toBe('500.000')
        ->and((string) $contract->fresh()?->discount_amount)->toBe('0.000');
});

it('fige une prolongation et n\'autorise que l\'agence du contrat (RG-13)', function () {
    $contract = activeContract();

    expect(fn () => app(ExtendContract::class)->handle($contract, ['ends_at' => '2027-03-15 09:00'], $this->agentSousse))->toThrow(ContractException::class, 'non autorisée');

    $extension = extend($contract, '2027-03-15 09:00');

    expect(fn () => $extension->forceFill(['added_days' => 9])->save())->toThrow(LogicException::class)
        ->and(fn () => $extension->refresh()->delete())->toThrow(LogicException::class);
});

it('prolonge depuis la fiche du contrat', function () {
    $contract = activeContract();
    $this->actingAs($this->agent);

    Livewire::test(ViewContract::class, ['record' => $contract->getRouteKey()])
        ->callAction('extend', ['ends_at' => '2027-03-15 09:00'])
        ->assertHasNoActionErrors()
        ->assertNotified('Contrat prolongé jusqu\'au 15/03/2027 09:00 : 2 jour(s) ajouté(s), 200,000 DT HT.');

    expect($contract->fresh()?->ends_at->equalTo(CarbonImmutable::parse('2027-03-15 09:00')))->toBeTrue();
});
