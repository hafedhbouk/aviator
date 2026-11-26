<?php

use App\Domain\Approvals\Actions\DecideApproval;
use App\Domain\Approvals\Exceptions\ApprovalException;
use App\Domain\Availability\Actions\SaveReservation;
use App\Domain\Availability\Exceptions\ReservationException;
use App\Domain\Crm\Actions\AddRecommendation;
use App\Domain\Crm\Exceptions\CustomerBlocked;
use App\Domain\Fleet\Exceptions\VehicleNotRentable;
use App\Domain\Rental\Actions\CancelContract;
use App\Domain\Rental\Actions\OpenContract;
use App\Domain\Rental\Exceptions\ContractException;
use App\Domain\Setup\Actions\SaveRatePlan;
use App\Domain\Setup\Actions\SaveSeason;
use App\Enums\ApprovalStatus;
use App\Enums\ContractStatus;
use App\Enums\ReservationStatus;
use App\Enums\VehicleStatus;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Reservations\Pages\CreateReservation;
use App\Filament\Resources\Reservations\Pages\ViewReservation;
use App\Models\Agency;
use App\Models\ApprovalRequest;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Option;
use App\Models\Reservation;
use App\Models\TaxCode;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleBlock;
use App\Models\VehicleCategory;
use App\Models\VehicleDocument;
use App\Support\Authorization\Role;
use App\Support\Documents\NumberedDocumentException;
use App\Support\Sequences\SequenceAllocator;
use Carbon\CarbonImmutable;
use Database\Seeders\FiscalReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * Contrats (phase 5, lot L2, D-01, RG-03, D49) : contrat depuis une réservation
 * confirmée (devis figé repris, occupation transférée) ou au comptoir (mêmes contrôles
 * qu'une réservation), conditions figées (D-05, D48), approbations (D46), annulation
 * du brouillon, droits par agence (RG-13). La conversion concurrente est dans
 * tests/Feature/Concurrency.
 */

beforeEach(function () {
    CarbonImmutable::setTestNow('2027-03-01 08:00');

    $this->superAdmin = withTwoFactor(userWithProfile(Role::SuperAdmin));
    $this->admin = withTwoFactor(userWithProfile(Role::Admin));
    $this->sfax = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->sousse = Agency::factory()->create(['code' => 'SOU', 'name' => 'Sousse']);
    $this->agent = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sfax->id]);
    $this->agentSousse = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sousse->id]);

    $this->category = VehicleCategory::factory()->create([
        'code' => 'SUV', 'name' => 'SUV', 'preparation_buffer_minutes' => 60, 'deposit_amount' => '1200.000',
        'included_km_per_day' => 300, 'extra_km_amount' => '0.250',
    ]);
    $season = app(SaveSeason::class)->handle(null, ['name' => 'Année 2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31'], $this->admin);
    app(SaveRatePlan::class)->handle(null, [
        'vehicle_category_id' => $this->category->id, 'season_id' => $season->id, 'agency_id' => null,
        'valid_from' => '2027-01-01', 'valid_to' => null, 'is_active' => true,
        'lines' => [['min_days' => 1, 'max_days' => null, 'daily_amount' => '100,000']],
    ], $this->admin);

    $this->vehicle = contractVehicle($this->sfax->id);
    $this->customer = Customer::factory()->create(['origin_agency_id' => $this->sfax->id]);

    // Compteur des contrats créé avant les numéros de réservation (voir SequenceAllocator::prepare).
    app(SequenceAllocator::class)->prepare(OpenContract::sequence());
});

afterEach(fn () => CarbonImmutable::setTestNow());

function contractVehicle(int $agencyId): Vehicle
{
    $vehicle = Vehicle::factory()->create(['agency_id' => $agencyId, 'vehicle_category_id' => test()->category->id])->refresh();
    foreach (['insurance', 'technical_inspection'] as $type) {
        VehicleDocument::query()->create(['vehicle_id' => $vehicle->id, 'type' => $type, 'valid_from' => '2027-01-01', 'valid_until' => '2027-12-31']);
    }

    return $vehicle;
}

/**
 * Réservation de 3 jours (300 DT HT) chez Sfax.
 */
function contractReservation(array $overrides = [], ?User $actor = null): Reservation
{
    return app(SaveReservation::class)->handle(null, array_merge([
        'customer_id' => test()->customer->id, 'agency_id' => test()->sfax->id, 'vehicle_category_id' => test()->category->id,
        'vehicle_id' => test()->vehicle->id, 'starts_at' => '2027-03-10 09:00', 'ends_at' => '2027-03-13 09:00',
    ], $overrides), $actor ?? test()->agent);
}

function counterContract(array $overrides = [], ?User $actor = null): Contract
{
    return app(OpenContract::class)->atCounter(array_merge([
        'customer_id' => test()->customer->id, 'agency_id' => test()->sfax->id, 'vehicle_id' => test()->vehicle->id,
        'starts_at' => '2027-03-01 09:00', 'ends_at' => '2027-03-04 09:00',
    ], $overrides), $actor ?? test()->agent);
}

// --- Depuis une réservation ---------------------------------------------------------------

it('crée le contrat d\'une réservation confirmée : devis repris, occupation transférée sans trou', function () {
    $this->seed(FiscalReferenceSeeder::class);
    $gps = Option::query()->create([
        'code' => 'GPS', 'name' => 'GPS', 'pricing_unit' => Option::PER_RENTAL, 'amount' => '40.000',
        'tax_code_id' => TaxCode::query()->where('code', 'GPS')->value('id'), 'is_active' => true,
    ]);

    $reservation = contractReservation(['options' => [$gps->id => 1]]);
    $contract = app(OpenContract::class)->fromReservation($reservation, null, $this->agent);

    expect($contract->number)->toBe('CTR-2027-00001')
        ->and($contract->status)->toBe(ContractStatus::Draft)
        ->and($contract->reservation_id)->toBe($reservation->id)
        ->and($contract->vehicle_id)->toBe($this->vehicle->id)
        ->and($contract->agency_id)->toBe($this->sfax->id)
        ->and($contract->starts_at->equalTo($reservation->starts_at))->toBeTrue()
        ->and((string) $contract->rental_amount)->toBe('300.000')
        ->and((string) $contract->options_amount)->toBe('40.000')
        ->and((string) $contract->total_amount)->toBe('340.000')
        ->and($contract->quote)->toBe($reservation->quote)
        ->and($contract->options()->sole()->option_id)->toBe($gps->id)
        ->and($reservation->fresh()?->status)->toBe(ReservationStatus::Converted);

    $blocks = VehicleBlock::query()->where('vehicle_id', $this->vehicle->id)->get();

    expect($blocks->where('active', true))->toHaveCount(1)
        ->and($blocks->firstWhere('active', true)?->source_type)->toBe((new Contract)->getMorphClass())
        ->and($blocks->firstWhere('active', true)?->source_id)->toBe($contract->id);

    // Le véhicule reste occupé : aucune autre réservation sur la période.
    expect(fn () => contractReservation(['customer_id' => Customer::factory()->create(['origin_agency_id' => $this->sfax->id])->id]))
        ->toThrow(ReservationException::class, 'pas disponible');
});

it('fige les conditions de kilométrage, de carburant, le tarif journalier et la caution prévue (D-05, D48)', function () {
    $contract = app(OpenContract::class)->fromReservation(contractReservation(), null, $this->agent);

    expect($contract->mileage_policy)->toBe(Contract::MILEAGE_DAILY_ALLOWANCE)
        ->and($contract->included_km_per_day)->toBe(300)
        ->and((string) $contract->extra_km_amount)->toBe('0.250')
        ->and($contract->fuel_policy)->toBe(Contract::FUEL_SAME_LEVEL)
        ->and((string) $contract->daily_rate_amount)->toBe('100.000')
        ->and((string) $contract->deposit_expected_amount)->toBe('1200.000');

    // Une catégorie modifiée ensuite ne change pas le contrat.
    $this->category->forceFill(['included_km_per_day' => null, 'extra_km_amount' => null])->save();
    expect($contract->fresh()?->included_km_per_day)->toBe(300);
});

it('ne convertit qu\'une réservation confirmée, une seule fois', function () {
    $draft = contractReservation(['status' => 'draft', 'vehicle_id' => null]);

    expect(fn () => app(OpenContract::class)->fromReservation($draft, $this->vehicle->id, $this->agent))
        ->toThrow(ContractException::class, 'confirmée');

    $reservation = contractReservation();
    app(OpenContract::class)->fromReservation($reservation, null, $this->agent);

    expect(fn () => app(OpenContract::class)->fromReservation($reservation, null, $this->agent))
        ->toThrow(ContractException::class, 'Convertie')
        ->and(Contract::query()->count())->toBe(1);
});

it('demande le véhicule d\'une réservation sans véhicule et le contrôle comme une affectation', function () {
    $reservation = contractReservation(['vehicle_id' => null]);

    expect(fn () => app(OpenContract::class)->fromReservation($reservation, null, $this->agent))
        ->toThrow(ContractException::class, 'Choisissez le véhicule');

    $sousseVehicle = contractVehicle($this->sousse->id);
    expect(fn () => app(OpenContract::class)->fromReservation($reservation, $sousseVehicle->id, $this->agent))
        ->toThrow(ReservationException::class, 'pas disponible');

    $contract = app(OpenContract::class)->fromReservation($reservation, $this->vehicle->id, $this->agent);

    expect($contract->vehicle_id)->toBe($this->vehicle->id)
        ->and($reservation->fresh()?->vehicle_id)->toBe($this->vehicle->id);
});

// --- Au comptoir ---------------------------------------------------------------------------

it('crée un contrat au comptoir avec les contrôles d\'une réservation, sans numéro perdu sur un refus', function () {
    $contract = counterContract();

    expect($contract->number)->toBe('CTR-2027-00001')
        ->and($contract->reservation_id)->toBeNull()
        ->and((string) $contract->total_amount)->toBe('300.000')
        ->and(VehicleBlock::query()->where('active', true)->sole()->source_id)->toBe($contract->id);

    // Même véhicule, période qui chevauche (délai de préparation compris) : refus.
    expect(fn () => counterContract(['starts_at' => '2027-03-04 09:30', 'ends_at' => '2027-03-06 09:00']))
        ->toThrow(ReservationException::class);

    expect(counterContract(['vehicle_id' => contractVehicle($this->sfax->id)->id])->number)->toBe('CTR-2027-00002');
});

it('refuse un départ dans le passé ou un véhicule à l\'assurance expirée (scénario 10)', function () {
    expect(fn () => counterContract(['starts_at' => '2027-02-27 09:00']))->toThrow(ContractException::class, 'passé');

    VehicleDocument::query()->where('vehicle_id', $this->vehicle->id)->where('type', 'insurance')->update(['valid_until' => '2027-02-28']);

    expect(fn () => counterContract())->toThrow(VehicleNotRentable::class, 'assurance')
        ->and(Contract::query()->count())->toBe(0);
});

// --- Approbations (D46) --------------------------------------------------------------------

it('soumet au super admin une remise au comptoir au-delà du seuil, et la retire en cas de refus', function () {
    expect(fn () => counterContract(['discount_amount' => '30,000']))->toThrow(ApprovalException::class, 'Motif obligatoire');

    $contract = counterContract(['discount_amount' => '30,000', 'discount_reason' => 'Client entreprise fidèle']);
    $request = ApprovalRequest::query()->sole();

    expect((string) $contract->total_amount)->toBe('270.000')
        ->and($contract->discount_approval_id)->toBe($request->id)
        ->and($request->subject_type)->toBe((new Contract)->getMorphClass());

    app(DecideApproval::class)->reject($request, $this->superAdmin, 'Remise non justifiée');
    $contract->refresh();

    expect((string) $contract->discount_amount)->toBe('0.000')
        ->and((string) $contract->total_amount)->toBe('300.000')
        ->and($contract->discount_approval_id)->toBeNull()
        ->and($contract->status)->toBe(ContractStatus::Draft);
});

it('retire du contrat la remise d\'une réservation convertie quand elle est refusée ensuite', function () {
    $reservation = contractReservation(['discount_amount' => '30,000', 'discount_reason' => 'Geste commercial']);
    $contract = app(OpenContract::class)->fromReservation($reservation, null, $this->agent);

    expect($contract->discount_approval_id)->toBe($reservation->discount_approval_id);

    app(DecideApproval::class)->reject(ApprovalRequest::query()->sole(), $this->superAdmin, 'Remise non justifiée');

    expect((string) $contract->fresh()?->discount_amount)->toBe('0.000')
        ->and((string) $contract->fresh()?->total_amount)->toBe('300.000');
});

it('n\'ouvre un contrat au comptoir pour un client sur liste noire qu\'avec une demande de dérogation', function () {
    app(AddRecommendation::class)->handle($this->customer, ['level' => 'blacklist', 'reason_code' => 'fraude'], $this->superAdmin);

    expect(fn () => counterContract())->toThrow(CustomerBlocked::class)
        ->and(Contract::query()->count())->toBe(0);

    $contract = counterContract(['override_reason' => 'Dette réglée, justificatif présenté']);
    $request = ApprovalRequest::query()->sole();

    expect($contract->status)->toBe(ContractStatus::Draft)
        ->and($contract->override_approval_id)->toBe($request->id);

    app(DecideApproval::class)->reject($request, $this->superAdmin, 'Justificatif insuffisant');

    expect($contract->fresh()?->status)->toBe(ContractStatus::Cancelled)
        ->and(VehicleBlock::query()->where('active', true)->count())->toBe(0);
});

it('refuse la conversion d\'une réservation d\'un client mis sur liste noire après sa confirmation', function () {
    $reservation = contractReservation();
    app(AddRecommendation::class)->handle($this->customer, ['level' => 'blacklist', 'reason_code' => 'fraude'], $this->superAdmin);

    expect(fn () => app(OpenContract::class)->fromReservation($reservation, null, $this->agent))->toThrow(CustomerBlocked::class);
});

it('garde le véhicule « Réservé » après la conversion, jusqu\'au départ ou à l\'annulation (Q28)', function () {
    $reservation = contractReservation(['starts_at' => '2027-03-01 18:00', 'ends_at' => '2027-03-03 18:00']);
    expect($this->vehicle->refresh()->status)->toBe(VehicleStatus::Reserved);

    $contract = app(OpenContract::class)->fromReservation($reservation, null, $this->agent);
    expect($this->vehicle->refresh()->status)->toBe(VehicleStatus::Reserved);

    app(CancelContract::class)->handle($contract, 'Le client ne s\'est pas présenté', $this->agent);
    expect($this->vehicle->refresh()->status)->toBe(VehicleStatus::Available);
});

// --- Annulation et intégrité ---------------------------------------------------------------

it('annule un brouillon avec motif, libère le véhicule et conserve le contrat numéroté', function () {
    $contract = counterContract(['discount_amount' => '30,000', 'discount_reason' => 'Geste commercial']);

    expect(fn () => app(CancelContract::class)->handle($contract, '', $this->agent))->toThrow(ContractException::class, 'Motif');

    app(CancelContract::class)->handle($contract, 'Le client a renoncé au comptoir', $this->agent);
    $contract->refresh();

    expect($contract->status)->toBe(ContractStatus::Cancelled)
        ->and($contract->cancellation_reason)->toBe('Le client a renoncé au comptoir')
        ->and(VehicleBlock::query()->where('active', true)->count())->toBe(0)
        ->and(ApprovalRequest::query()->sole()->status)->toBe(ApprovalStatus::Cancelled)
        ->and(fn () => app(CancelContract::class)->handle($contract, 'Seconde annulation', $this->agent))->toThrow(ContractException::class, 'Annulé')
        ->and(fn () => $contract->delete())->toThrow(NumberedDocumentException::class);

    // La période est de nouveau libre.
    expect(counterContract()->status)->toBe(ContractStatus::Draft);
});

it('n\'autorise l\'agent qu\'aux contrats de son agence (RG-13, scénario 12)', function () {
    $contract = counterContract();

    expect($this->agentSousse->can('view', $contract))->toBeFalse()
        ->and($this->agentSousse->can('update', $contract))->toBeFalse()
        ->and(fn () => app(CancelContract::class)->handle($contract, 'Annulation par une autre agence', $this->agentSousse))->toThrow(ContractException::class, 'non autorisée')
        ->and(fn () => counterContract([], $this->agentSousse))->toThrow(ContractException::class, 'non autorisée')
        ->and(fn () => app(OpenContract::class)->fromReservation(contractReservation(['vehicle_id' => contractVehicle($this->sfax->id)->id, 'starts_at' => '2027-04-10 09:00', 'ends_at' => '2027-04-12 09:00']), null, $this->agentSousse))
        ->toThrow(ContractException::class, 'non autorisée');

    $this->actingAs($this->agentSousse);
    $this->get(ContractResource::getUrl('view', ['record' => $contract]))->assertNotFound();

    $this->actingAs($this->agent);
    $this->get(ContractResource::getUrl('index'))->assertOk();
    $this->get(ContractResource::getUrl('view', ['record' => $contract]))->assertOk()->assertSee($contract->number);
});

// --- Écrans --------------------------------------------------------------------------------

it('crée le contrat depuis la fiche de la réservation', function () {
    $reservation = contractReservation();
    $this->actingAs($this->agent);

    Livewire::test(ViewReservation::class, ['record' => $reservation->getRouteKey()])
        ->callAction('openContract')
        ->assertHasNoActionErrors()
        ->assertRedirect(ContractResource::getUrl('view', ['record' => Contract::query()->sole()]));
});

it('loue maintenant depuis l\'assistant du comptoir, sans réservation', function () {
    $this->actingAs($this->agent);

    Livewire::test(CreateReservation::class)
        ->set('customerResults', [['customer_id' => $this->customer->id, 'name' => 'Client', 'blacklisted' => false, 'watched' => false, 'rentals' => 0]])
        ->fillForm([
            'customer_id' => $this->customer->id, 'agency_id' => $this->sfax->id, 'vehicle_category_id' => $this->category->id,
            'starts_at' => '2027-03-01 09:00:00', 'ends_at' => '2027-03-04 09:00:00', 'vehicle_id' => $this->vehicle->id,
            'option_lines' => [], 'status' => CreateReservation::RENT_NOW,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(ContractResource::getUrl('view', ['record' => Contract::query()->sole()]));

    expect(Reservation::query()->count())->toBe(0)
        ->and(Contract::query()->sole()->created_by)->toBe($this->agent->id);
});
