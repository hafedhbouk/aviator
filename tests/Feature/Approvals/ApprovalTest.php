<?php

use App\Domain\Approvals\Actions\DecideApproval;
use App\Domain\Approvals\ApprovalGate;
use App\Domain\Approvals\ApprovalNotifier;
use App\Domain\Approvals\Exceptions\ApprovalException;
use App\Domain\Availability\Actions\CloseReservation;
use App\Domain\Availability\Actions\SaveReservation;
use App\Domain\Crm\Actions\AddRecommendation;
use App\Domain\Crm\Exceptions\CustomerBlocked;
use App\Domain\Setup\Actions\SaveRatePlan;
use App\Domain\Setup\Actions\SaveSeason;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Enums\ReservationStatus;
use App\Filament\Resources\ApprovalRequests\ApprovalRequestResource;
use App\Filament\Resources\ApprovalRequests\Pages\ViewApprovalRequest;
use App\Models\Agency;
use App\Models\ApprovalRequest;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\VehicleDocument;
use App\Settings\ApprovalSettings;
use App\Support\Authorization\Role;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * Approbations du super admin (phase 5, lot L1, D46) : remise au-delà du seuil (RG-10)
 * et dérogation à la liste noire (RG-15). Demande figée et motivée, décision unique du
 * super admin, effet d'un refus, notifications sans donnée sensible (RG-20), droits.
 * La double décision sur deux processus est dans tests/Feature/Concurrency.
 */

beforeEach(function () {
    CarbonImmutable::setTestNow('2027-03-01 08:00');

    $this->superAdmin = withTwoFactor(userWithProfile(Role::SuperAdmin));
    $this->admin = withTwoFactor(userWithProfile(Role::Admin));
    $this->sfax = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->agent = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sfax->id]);

    $this->category = VehicleCategory::factory()->create(['code' => 'CIT', 'name' => 'Citadine']);
    $season = app(SaveSeason::class)->handle(null, ['name' => 'Année 2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31'], $this->admin);
    app(SaveRatePlan::class)->handle(null, [
        'vehicle_category_id' => $this->category->id, 'season_id' => $season->id, 'agency_id' => null,
        'valid_from' => '2027-01-01', 'valid_to' => null, 'is_active' => true,
        'lines' => [['min_days' => 1, 'max_days' => null, 'daily_amount' => '100,000']],
    ], $this->admin);

    $this->vehicle = approvalVehicle();
    $this->customer = Customer::factory()->create(['origin_agency_id' => $this->sfax->id, 'first_name' => 'Amel', 'last_name' => 'Trabelsi']);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function approvalVehicle(): Vehicle
{
    $vehicle = Vehicle::factory()->create(['agency_id' => test()->sfax->id, 'vehicle_category_id' => test()->category->id])->refresh();
    foreach (['insurance', 'technical_inspection'] as $type) {
        VehicleDocument::query()->create(['vehicle_id' => $vehicle->id, 'type' => $type, 'valid_from' => '2027-01-01', 'valid_until' => '2027-12-31']);
    }

    return $vehicle;
}

/**
 * Réservation de 3 jours à 100 DT (300 DT HT), saisie par l'agent de Sfax.
 */
function approvalReservation(array $overrides = [], ?User $actor = null, ?Reservation $reservation = null): Reservation
{
    return app(SaveReservation::class)->handle($reservation, $reservation !== null ? $overrides : array_merge([
        'customer_id' => test()->customer->id, 'agency_id' => test()->sfax->id, 'vehicle_category_id' => test()->category->id,
        'vehicle_id' => test()->vehicle->id, 'starts_at' => '2027-03-10 09:00', 'ends_at' => '2027-03-13 09:00',
    ], $overrides), $actor ?? test()->agent);
}

function blacklistApprovalCustomer(): void
{
    app(AddRecommendation::class)->handle(test()->customer, ['level' => 'blacklist', 'reason_code' => 'fraude', 'comment' => 'Motif confidentiel'], test()->superAdmin);
}

// --- Remise au-delà du seuil (RG-10) ----------------------------------------------------------

it('exige un motif pour une remise au-delà du seuil et n\'enregistre rien sans lui', function () {
    expect(fn () => approvalReservation(['discount_amount' => '30,000']))->toThrow(ApprovalException::class, 'Motif obligatoire')
        ->and(Reservation::query()->count())->toBe(0)
        ->and(ApprovalRequest::query()->count())->toBe(0);

    // Aucun numéro consommé par le refus.
    expect(approvalReservation()->number)->toBe('RES-2027-00001');
});

it('enregistre la réservation et ouvre une demande figée, notifiée au super admin sans donnée sensible', function () {
    $reservation = approvalReservation(['discount_amount' => '30,000', 'discount_reason' => 'Client fidèle, troisième location']);
    $request = ApprovalRequest::query()->sole();

    expect($reservation->status)->toBe(ReservationStatus::Confirmed)
        ->and((string) $reservation->discount_amount)->toBe('30.000')
        ->and((string) $reservation->estimated_total_amount)->toBe('270.000')
        ->and($reservation->discount_approval_id)->toBe($request->id)
        ->and($request->type)->toBe(ApprovalType::Discount)
        ->and($request->status)->toBe(ApprovalStatus::Pending)
        ->and($request->agency_id)->toBe($this->sfax->id)
        ->and($request->requested_by)->toBe($this->agent->id)
        ->and($request->payload)->toMatchArray([
            'document_number' => $reservation->number, 'subtotal_amount' => '300.000', 'discount_amount' => '30.000',
            'discount_percent' => '10.00', 'threshold_percent' => '0', 'total_excluding_tax' => '270.000',
        ]);

    $notifications = DatabaseNotification::query()->where('notifiable_id', $this->superAdmin->id)->get();
    $text = json_encode($notifications->first()?->data, JSON_UNESCAPED_UNICODE);

    expect($notifications)->toHaveCount(1)
        ->and($notifications->first()?->data['title'] ?? null)->toBe(ApprovalNotifier::REQUESTED_TITLE)
        ->and($text)->not->toContain('30,000')
        ->and($text)->not->toContain('30.000')
        ->and($text)->not->toContain('Trabelsi')
        ->and($text)->not->toContain('fidèle')
        ->and($text)->not->toContain($reservation->number)
        ->and(DatabaseNotification::query()->where('notifiable_id', $this->admin->id)->count())->toBe(0);
});

it('n\'ouvre aucune demande pour une remise sous le seuil', function () {
    $settings = app(ApprovalSettings::class);
    $settings->discount_threshold_percent = '10.00';
    $settings->save();

    $reservation = approvalReservation(['discount_amount' => '30,000']);

    expect($reservation->discount_approval_id)->toBeNull()
        ->and(ApprovalRequest::query()->count())->toBe(0)
        ->and(fn () => app(ApprovalGate::class)->assertDiscountCleared($reservation->discount_amount, $reservation->discount_approval_id))->not->toThrow(ApprovalException::class);
});

it('bloque le départ tant que la remise n\'est pas approuvée, puis le permet', function () {
    $reservation = approvalReservation(['discount_amount' => '30,000', 'discount_reason' => 'Geste commercial']);
    $gate = app(ApprovalGate::class);

    expect(fn () => $gate->assertDiscountCleared($reservation->discount_amount, $reservation->discount_approval_id))
        ->toThrow(ApprovalException::class, 'départ impossible');

    $request = app(DecideApproval::class)->approve(ApprovalRequest::query()->sole(), $this->superAdmin);

    expect($request->status)->toBe(ApprovalStatus::Approved)
        ->and($request->decided_by)->toBe($this->superAdmin->id)
        ->and($request->decided_at)->not->toBeNull()
        ->and(fn () => $gate->assertDiscountCleared($reservation->discount_amount, $reservation->discount_approval_id))->not->toThrow(ApprovalException::class);

    $notification = DatabaseNotification::query()->where('notifiable_id', $this->agent->id)->sole();
    expect($notification->data['title'])->toBe('Votre demande d\'approbation est approuvée');
});

it('refuse un refus sans motif, puis retire la remise et recalcule le devis', function () {
    $reservation = approvalReservation(['discount_amount' => '30,000', 'discount_reason' => 'Geste commercial']);
    $request = ApprovalRequest::query()->sole();

    expect(fn () => app(DecideApproval::class)->reject($request, $this->superAdmin, ''))->toThrow(ApprovalException::class, 'motivé')
        ->and($request->fresh()?->status)->toBe(ApprovalStatus::Pending);

    app(DecideApproval::class)->reject($request, $this->superAdmin, 'Remise trop élevée pour cette saison');
    $reservation->refresh();

    expect($request->fresh()?->status)->toBe(ApprovalStatus::Rejected)
        ->and($request->fresh()?->comment)->toBe('Remise trop élevée pour cette saison')
        ->and((string) $reservation->discount_amount)->toBe('0.000')
        ->and((string) $reservation->estimated_total_amount)->toBe('300.000')
        ->and($reservation->quote['discount_amount'])->toBe('0.000')
        ->and($reservation->discount_approval_id)->toBeNull()
        ->and($reservation->status)->toBe(ReservationStatus::Confirmed)
        ->and(DatabaseNotification::query()->where('notifiable_id', $this->agent->id)->sole()->data['title'])->toBe('Votre demande d\'approbation est refusée');
});

it('refuse une seconde décision sur une demande déjà tranchée', function () {
    approvalReservation(['discount_amount' => '30,000', 'discount_reason' => 'Geste commercial']);
    $request = ApprovalRequest::query()->sole();

    app(DecideApproval::class)->approve($request, $this->superAdmin);

    expect(fn () => app(DecideApproval::class)->reject($request, $this->superAdmin, 'Changement d\'avis'))->toThrow(ApprovalException::class, 'déjà traitée')
        ->and($request->fresh()?->status)->toBe(ApprovalStatus::Approved);
});

it('garde la demande d\'une remise inchangée et remplace celle d\'une remise modifiée', function () {
    $reservation = approvalReservation(['discount_amount' => '30,000', 'discount_reason' => 'Geste commercial']);
    $first = ApprovalRequest::query()->sole();

    // Dates modifiées, remise inchangée : même demande.
    $reservation = approvalReservation(['starts_at' => '2027-03-11 09:00', 'ends_at' => '2027-03-14 09:00'], reservation: $reservation);
    expect($reservation->discount_approval_id)->toBe($first->id)
        ->and(ApprovalRequest::query()->count())->toBe(1);

    // Remise modifiée : la première demande est annulée, une nouvelle est ouverte.
    $reservation = approvalReservation(['discount_amount' => '45,000', 'discount_reason' => 'Nouvelle proposition au client'], reservation: $reservation);
    $second = ApprovalRequest::query()->latest('id')->first();

    expect($first->fresh()?->status)->toBe(ApprovalStatus::Cancelled)
        ->and($second?->status)->toBe(ApprovalStatus::Pending)
        ->and($second?->payload['discount_amount'])->toBe('45.000')
        ->and($reservation->discount_approval_id)->toBe($second?->id);

    // Remise retirée : plus aucune demande en attente.
    $reservation = approvalReservation(['discount_amount' => '0'], reservation: $reservation);
    expect($second?->fresh()?->status)->toBe(ApprovalStatus::Cancelled)
        ->and($reservation->discount_approval_id)->toBeNull();
});

it('annule les demandes en attente d\'une réservation annulée', function () {
    $reservation = approvalReservation(['discount_amount' => '30,000', 'discount_reason' => 'Geste commercial']);

    app(CloseReservation::class)->cancel($reservation, 'Le client a annulé son voyage', $this->agent);

    expect(ApprovalRequest::query()->sole()->status)->toBe(ApprovalStatus::Cancelled)
        ->and(fn () => app(DecideApproval::class)->approve(ApprovalRequest::query()->sole(), $this->superAdmin))->toThrow(ApprovalException::class, 'déjà traitée');
});

// --- Dérogation à la liste noire (RG-15) ------------------------------------------------------

it('refuse une réservation confirmée ou un brouillon sans motif pour un client sur liste noire', function () {
    blacklistApprovalCustomer();

    expect(fn () => approvalReservation())->toThrow(CustomerBlocked::class)
        ->and(fn () => approvalReservation(['status' => 'draft']))->toThrow(CustomerBlocked::class)
        ->and(fn () => approvalReservation(['status' => 'confirmed', 'override_reason' => 'Client connu du gérant']))->toThrow(CustomerBlocked::class)
        ->and(Reservation::query()->count())->toBe(0);
});

it('enregistre un brouillon avec une demande de dérogation, sans jamais montrer le motif de la liste noire', function () {
    blacklistApprovalCustomer();

    $draft = approvalReservation(['status' => 'draft', 'override_reason' => 'Dette réglée, justificatif présenté']);
    $request = ApprovalRequest::query()->sole();

    expect($draft->status)->toBe(ReservationStatus::Draft)
        ->and($draft->override_approval_id)->toBe($request->id)
        ->and($request->type)->toBe(ApprovalType::BlacklistOverride)
        ->and(json_encode($request->payload))->not->toContain('confidentiel');

    // Confirmation refusée tant que la dérogation attend.
    expect(fn () => approvalReservation(['status' => 'confirmed'], reservation: $draft))->toThrow(CustomerBlocked::class, 'pas encore approuvée');

    app(DecideApproval::class)->approve($request, $this->superAdmin);
    $confirmed = approvalReservation(['status' => 'confirmed'], reservation: $draft->refresh());

    expect($confirmed->status)->toBe(ReservationStatus::Confirmed);
});

it('ne vaut que pour la réservation concernée, jamais pour le client en général', function () {
    blacklistApprovalCustomer();
    $draft = approvalReservation(['status' => 'draft', 'override_reason' => 'Dette réglée, justificatif présenté']);
    app(DecideApproval::class)->approve(ApprovalRequest::query()->sole(), $this->superAdmin);
    approvalReservation(['status' => 'confirmed'], reservation: $draft->refresh());

    expect(fn () => approvalReservation(['vehicle_id' => approvalVehicle()->id]))->toThrow(CustomerBlocked::class);
});

it('annule le brouillon quand la dérogation est refusée', function () {
    blacklistApprovalCustomer();
    $draft = approvalReservation(['status' => 'draft', 'override_reason' => 'Dette réglée, justificatif présenté']);

    app(DecideApproval::class)->reject(ApprovalRequest::query()->sole(), $this->superAdmin, 'Justificatif insuffisant');

    expect($draft->fresh()?->status)->toBe(ReservationStatus::Cancelled)
        ->and($draft->fresh()?->cancellation_reason)->toContain('Dérogation');
});

// --- Droits et intégrité ----------------------------------------------------------------------

it('ne laisse décider que le super admin, au niveau du domaine', function (string $profile) {
    approvalReservation(['discount_amount' => '30,000', 'discount_reason' => 'Geste commercial']);
    $request = ApprovalRequest::query()->sole();

    expect(fn () => app(DecideApproval::class)->approve($request, $this->{$profile}))->toThrow(ApprovalException::class, 'Seul le super admin')
        ->and(fn () => app(DecideApproval::class)->reject($request, $this->{$profile}, 'Refus non autorisé'))->toThrow(ApprovalException::class, 'Seul le super admin')
        ->and($request->fresh()?->status)->toBe(ApprovalStatus::Pending);
})->with(['admin', 'agent']);

it('réserve l\'écran des approbations au super admin, y compris par requête Livewire', function () {
    approvalReservation(['discount_amount' => '30,000', 'discount_reason' => 'Geste commercial']);
    $request = ApprovalRequest::query()->sole();

    foreach (['admin', 'agent'] as $profile) {
        $this->actingAs($this->{$profile});
        $this->get(ApprovalRequestResource::getUrl('index'))->assertForbidden();
        $this->get(ApprovalRequestResource::getUrl('view', ['record' => $request]))->assertForbidden();
        Livewire::test(ViewApprovalRequest::class, ['record' => $request->getRouteKey()])->assertForbidden();
    }

    $this->actingAs($this->superAdmin);
    $this->get(ApprovalRequestResource::getUrl('index'))->assertOk();
    $this->get(ApprovalRequestResource::getUrl('view', ['record' => $request]))->assertOk()
        ->assertSee('Geste commercial')->assertSee('30,000');

    Livewire::test(ViewApprovalRequest::class, ['record' => $request->getRouteKey()])
        ->callAction('reject', ['comment' => ''])
        ->assertHasActionErrors(['comment' => 'required']);

    Livewire::test(ViewApprovalRequest::class, ['record' => $request->getRouteKey()])
        ->callAction('approve', ['comment' => 'D\'accord pour cette fois'])
        ->assertHasNoActionErrors();

    expect($request->fresh()?->status)->toBe(ApprovalStatus::Approved);
});

it('fige les données d\'une demande et en interdit la suppression', function () {
    approvalReservation(['discount_amount' => '30,000', 'discount_reason' => 'Geste commercial']);
    $request = ApprovalRequest::query()->sole();

    expect(fn () => $request->forceFill(['payload' => ['discount_amount' => '1.000']])->save())->toThrow(LogicException::class)
        ->and(fn () => $request->refresh()->forceFill(['reason' => 'Autre motif'])->save())->toThrow(LogicException::class)
        ->and(fn () => $request->refresh()->delete())->toThrow(LogicException::class)
        ->and(ApprovalRequest::query()->sole()->payload['discount_amount'])->toBe('30.000');
});
