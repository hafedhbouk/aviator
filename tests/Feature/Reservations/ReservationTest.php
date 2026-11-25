<?php

use App\Domain\Availability\Actions\AssignVehicle;
use App\Domain\Availability\Actions\CloseReservation;
use App\Domain\Availability\Actions\SaveReservation;
use App\Domain\Availability\Exceptions\ReservationException;
use App\Domain\Availability\Period;
use App\Domain\Crm\Actions\AddRecommendation;
use App\Domain\Crm\Exceptions\CustomerBlocked;
use App\Domain\Fleet\Exceptions\VehicleNotRentable;
use App\Domain\Setup\Actions\SaveRatePlan;
use App\Domain\Setup\Actions\SaveSeason;
use App\Enums\ReservationStatus;
use App\Enums\VehicleStatus;
use App\Models\Agency;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleBlock;
use App\Models\VehicleCategory;
use App\Models\VehicleDocument;
use App\Settings\ReservationSettings;
use App\Support\Authorization\Role;
use App\Support\Documents\NumberedDocumentException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Réservations (module C, lot L11) : création, capacité d'une catégorie (Q36),
 * affectation (C-05, scénario 10), annulation (scénario 2), non-présentation (Q39),
 * expiration (C-09), statut « Réservé » (Q28), droits par agence (RG-13, scénario 12),
 * liste noire (RG-15). La concurrence est dans tests/Feature/Concurrency.
 */

beforeEach(function () {
    CarbonImmutable::setTestNow('2027-03-01 08:00');

    $this->superAdmin = withTwoFactor(userWithProfile(Role::SuperAdmin));
    $this->admin = withTwoFactor(userWithProfile(Role::Admin));
    $this->sfax = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->sousse = Agency::factory()->create(['code' => 'SOU', 'name' => 'Sousse']);
    $this->agentSfax = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sfax->id]);
    $this->agentSousse = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sousse->id]);

    $this->category = VehicleCategory::factory()->create(['code' => 'CIT', 'name' => 'Citadine', 'preparation_buffer_minutes' => 60]);
    $season = app(SaveSeason::class)->handle(null, ['name' => 'Année 2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31'], $this->admin);
    app(SaveRatePlan::class)->handle(null, [
        'vehicle_category_id' => $this->category->id, 'season_id' => $season->id, 'agency_id' => null,
        'valid_from' => '2027-01-01', 'valid_to' => null, 'is_active' => true,
        'lines' => [['min_days' => 1, 'max_days' => null, 'daily_amount' => '100,000']],
    ], $this->admin);

    $this->vehicleA = reservationVehicle($this->sfax->id);
    $this->vehicleB = reservationVehicle($this->sfax->id);
    $this->customer = Customer::factory()->create(['origin_agency_id' => $this->sfax->id]);
    $this->save = app(SaveReservation::class);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function reservationVehicle(int $agencyId, string $insuranceUntil = '2027-12-31', ?int $categoryId = null): Vehicle
{
    $vehicle = Vehicle::factory()->create(['agency_id' => $agencyId, 'vehicle_category_id' => $categoryId ?? test()->category->id])->refresh();
    VehicleDocument::query()->create(['vehicle_id' => $vehicle->id, 'type' => 'insurance', 'valid_from' => '2027-01-01', 'valid_until' => $insuranceUntil]);
    VehicleDocument::query()->create(['vehicle_id' => $vehicle->id, 'type' => 'technical_inspection', 'valid_from' => '2027-01-01', 'valid_until' => '2027-12-31']);

    return $vehicle;
}

function reservationData(array $overrides = []): array
{
    return array_merge([
        'customer_id' => test()->customer->id,
        'agency_id' => test()->sfax->id,
        'vehicle_category_id' => test()->category->id,
        'vehicle_id' => test()->vehicleA->id,
        'starts_at' => '2027-03-10 09:00',
        'ends_at' => '2027-03-13 09:00',
    ], $overrides);
}

function reserve(array $overrides = [], ?User $actor = null): Reservation
{
    return test()->save->handle(null, reservationData($overrides), $actor ?? test()->agentSfax);
}

// --- Création ------------------------------------------------------------------------

it('crée une réservation confirmée, numérotée, au devis figé, qui occupe son véhicule', function () {
    $reservation = reserve();

    expect($reservation->number)->toBe('RES-2027-00001')
        ->and($reservation->status)->toBe(ReservationStatus::Confirmed)
        ->and($reservation->agency_id)->toBe($this->sfax->id)
        ->and($reservation->return_agency_id)->toBe($this->sfax->id)
        ->and($reservation->quote['billable_days'])->toBe(3)
        ->and((string) $reservation->estimated_total_amount)->toBe('300.000')
        ->and($reservation->created_by)->toBe($this->agentSfax->id);

    $block = VehicleBlock::query()->sole();
    expect($block->vehicle_id)->toBe($this->vehicleA->id)
        ->and($block->ends_at->format('Y-m-d H:i'))->toBe('2027-03-13 10:00');
});

it('refuse le même véhicule sur une période qui chevauche, délai de préparation compris', function () {
    reserve();

    expect(fn () => reserve(['starts_at' => '2027-03-13 09:59', 'ends_at' => '2027-03-15 09:00']))
        ->toThrow(ReservationException::class, 'pas disponible');

    expect(reserve(['starts_at' => '2027-03-13 10:00', 'ends_at' => '2027-03-15 09:00'])->number)->toBe('RES-2027-00002');
});

it('ne consomme aucun numéro quand la réservation est refusée', function () {
    reserve();
    try {
        reserve();
    } catch (ReservationException) {
    }

    expect(reserve(['vehicle_id' => $this->vehicleB->id])->number)->toBe('RES-2027-00002');
});

// --- Capacité d'une catégorie (Q36) ------------------------------------------------------

it('accepte autant de réservations sans véhicule que de véhicules disponibles, pas une de plus', function () {
    reserve(['vehicle_id' => null]);
    reserve(['vehicle_id' => null]);

    expect(fn () => reserve(['vehicle_id' => null]))->toThrow(ReservationException::class, 'Plus aucun véhicule Citadine');
});

it('refuse de prendre un véhicule précis quand il priverait une réservation sans véhicule', function () {
    reserve(['vehicle_id' => null]);
    reserve(['vehicle_id' => $this->vehicleA->id]);

    // Reste B, mais une réservation sans véhicule l'attend.
    expect(fn () => reserve(['vehicle_id' => $this->vehicleB->id]))->toThrow(ReservationException::class, 'Plus aucun véhicule');
});

it('affecte plus tard un véhicule à une réservation sans véhicule (C-05), sans recalculer le prix', function () {
    $reservation = reserve(['vehicle_id' => null]);
    $quote = $reservation->quote;

    $assigned = app(AssignVehicle::class)->handle($reservation, $this->vehicleB->id, $this->agentSfax);

    expect($assigned->vehicle_id)->toBe($this->vehicleB->id)
        ->and($assigned->quote)->toBe($quote)
        ->and(VehicleBlock::query()->where('vehicle_id', $this->vehicleB->id)->where('active', true)->count())->toBe(1);
});

// --- Véhicule (scénario 10) ----------------------------------------------------------------

it('refuse un véhicule dont l\'assurance ne couvre pas le départ (scénario 10)', function () {
    $expiring = reservationVehicle($this->sfax->id, insuranceUntil: '2027-03-09');

    expect(fn () => reserve(['vehicle_id' => $expiring->id]))->toThrow(VehicleNotRentable::class, 'assurance non valide');
});

it('refuse un véhicule d\'une autre agence ou d\'une autre catégorie', function () {
    $elsewhere = reservationVehicle($this->sousse->id);
    $suv = reservationVehicle($this->sfax->id, categoryId: VehicleCategory::factory()->create(['code' => 'SUV'])->id);

    expect(fn () => reserve(['vehicle_id' => $elsewhere->id]))->toThrow(ReservationException::class, 'pas disponible')
        ->and(fn () => reserve(['vehicle_id' => $suv->id]))->toThrow(ReservationException::class, 'catégorie');
});

// --- Client et droits -----------------------------------------------------------------------

it('refuse un client sur liste noire, sans motif (RG-15)', function () {
    app(AddRecommendation::class)->handle($this->customer, ['level' => 'blacklist', 'reason_code' => 'fraude'], $this->superAdmin);

    expect(fn () => reserve())->toThrow(CustomerBlocked::class)
        ->and(Reservation::query()->count())->toBe(0);
});

it('n\'autorise l\'agent qu\'à réserver et modifier dans son agence (RG-13, scénario 12)', function () {
    $sousseVehicle = reservationVehicle($this->sousse->id);

    expect(fn () => reserve(['agency_id' => $this->sousse->id, 'vehicle_id' => $sousseVehicle->id], $this->agentSfax))
        ->toThrow(ReservationException::class, 'Action non autorisée');

    $sousseReservation = reserve(['agency_id' => $this->sousse->id, 'vehicle_id' => $sousseVehicle->id], $this->agentSousse);

    expect(fn () => $this->save->handle($sousseReservation, ['ends_at' => '2027-03-14 09:00'], $this->agentSfax))->toThrow(ReservationException::class)
        ->and(fn () => app(CloseReservation::class)->cancel($sousseReservation, 'Annulation non autorisée', $this->agentSfax))->toThrow(ReservationException::class);

    $this->actingAs($this->agentSfax);
    expect(Reservation::query()->pluck('id')->all())->toBe([]);
});

it('ne supprime jamais une réservation numérotée (RG-11)', function () {
    $reservation = reserve();

    expect(fn () => $reservation->delete())->toThrow(NumberedDocumentException::class);
});

// --- Modification ------------------------------------------------------------------------------

it('prolonge une réservation sans buter sur elle-même, et refuse une prolongation sur la suivante', function () {
    $reservation = reserve();
    reserve(['starts_at' => '2027-03-16 09:00', 'ends_at' => '2027-03-18 09:00']);

    $longer = $this->save->handle($reservation, ['ends_at' => '2027-03-15 09:00'], $this->agentSfax);

    expect($longer->quote['billable_days'])->toBe(5)
        ->and($longer->vehicle_id)->toBe($this->vehicleA->id)
        ->and(VehicleBlock::query()->where('source_id', $reservation->id)->sole()->ends_at->format('Y-m-d H:i'))->toBe('2027-03-15 10:00');

    expect(fn () => $this->save->handle($reservation, ['ends_at' => '2027-03-16 08:30'], $this->agentSfax))
        ->toThrow(ReservationException::class);
});

// --- Fin de réservation (scénario 2, Q39, C-09) -------------------------------------------------

it('libère le véhicule à l\'annulation, motif obligatoire : la période redevient disponible (scénario 2)', function () {
    $reservation = reserve();

    expect(fn () => app(CloseReservation::class)->cancel($reservation, ' non ', $this->agentSfax))->toThrow(ReservationException::class, 'motif');

    $cancelled = app(CloseReservation::class)->cancel($reservation, 'Le client a changé ses dates', $this->agentSfax);

    expect($cancelled->status)->toBe(ReservationStatus::Cancelled)
        ->and($cancelled->cancellation_reason)->toBe('Le client a changé ses dates')
        ->and(VehicleBlock::query()->where('active', true)->count())->toBe(0)
        ->and(reserve()->status)->toBe(ReservationStatus::Confirmed);

    expect(fn () => app(CloseReservation::class)->cancel($cancelled, 'Deuxième annulation', $this->agentSfax))
        ->toThrow(ReservationException::class, 'Annulée');
});

it('accepte la non-présentation après le délai, pas avant (Q39)', function () {
    $reservation = reserve();
    $close = app(CloseReservation::class);

    expect(fn () => $close->markNoShow($reservation, $this->agentSfax, CarbonImmutable::parse('2027-03-10 10:59')))
        ->toThrow(ReservationException::class, 'à partir du 10/03/2027 à 11:00');

    expect($close->markNoShow($reservation, $this->agentSfax, CarbonImmutable::parse('2027-03-10 11:00'))->status)->toBe(ReservationStatus::NoShow)
        ->and(VehicleBlock::query()->where('active', true)->count())->toBe(0);
});

it('enregistre un brouillon sans retenir ni véhicule ni capacité, et l\'expire quand le délai est fixé (C-09)', function () {
    $settings = app(ReservationSettings::class);
    $settings->draft_expiry_hours = 2;
    $settings->save();

    $draft = reserve(['status' => 'draft']);

    expect($draft->status)->toBe(ReservationStatus::Draft)
        ->and(VehicleBlock::query()->count())->toBe(0)
        ->and($draft->expires_at?->format('Y-m-d H:i'))->toBe('2027-03-01 10:00');

    expect(app(CloseReservation::class)->expireDue(CarbonImmutable::parse('2027-03-01 09:59')))->toBe(0)
        ->and(app(CloseReservation::class)->expireDue(CarbonImmutable::parse('2027-03-01 10:00')))->toBe(1)
        ->and($draft->fresh()?->status)->toBe(ReservationStatus::Expired);
});

it('n\'expire pas un brouillon quand aucun délai n\'est fixé (Q90)', function () {
    $draft = reserve(['status' => 'draft']);

    expect($draft->expires_at)->toBeNull()
        ->and(app(CloseReservation::class)->expireDue(CarbonImmutable::parse('2030-01-01')))->toBe(0);
});

// --- Statut « Réservé » (Q28) -----------------------------------------------------------------

it('passe le véhicule « Réservé » quand le départ est à moins de 24 h, et le libère à l\'annulation', function () {
    CarbonImmutable::setTestNow('2027-03-09 10:00');

    $reservation = reserve();
    expect($this->vehicleA->fresh()?->status)->toBe(VehicleStatus::Reserved);

    app(CloseReservation::class)->cancel($reservation, 'Annulation par le client', $this->agentSfax);
    expect($this->vehicleA->fresh()?->status)->toBe(VehicleStatus::Available);
});

it('laisse le véhicule disponible quand le départ est loin', function () {
    reserve();

    expect($this->vehicleA->fresh()?->status)->toBe(VehicleStatus::Available);
});

// --- Règles facultatives (Q90) ------------------------------------------------------------------

it('applique la durée minimale et le préavis seulement quand ils sont fixés', function () {
    $settings = app(ReservationSettings::class);
    $settings->min_rental_days = 4;
    $settings->min_notice_hours = 48;
    $settings->save();

    expect(fn () => reserve())->toThrow(ReservationException::class, 'Durée minimale')
        ->and(fn () => reserve(['starts_at' => '2027-03-02 07:00', 'ends_at' => '2027-03-07 09:00']))->toThrow(ReservationException::class, 'Préavis');

    expect(reserve(['ends_at' => '2027-03-14 09:00'])->status)->toBe(ReservationStatus::Confirmed);
});

it('refuse un départ dans le passé', function () {
    expect(fn () => reserve(['starts_at' => '2027-02-28 09:00', 'ends_at' => '2027-03-02 09:00']))
        ->toThrow(ReservationException::class, 'passé');
});

it('lit une période sans heure d\'été : départ et retour à l\'heure de Tunis', function () {
    $reservation = reserve(['starts_at' => '2027-03-28 01:30', 'ends_at' => '2027-03-29 01:30']);

    expect($reservation->starts_at->timezone('Africa/Tunis')->format('Y-m-d H:i'))->toBe('2027-03-28 01:30')
        ->and(new Period($reservation->starts_at, $reservation->ends_at))->toBeInstanceOf(Period::class);
});
