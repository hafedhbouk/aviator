<?php

use App\Domain\Fleet\Alerts\DueDateAlerts;
use App\Domain\Fleet\Financing\Actions\SaveFinancing;
use App\Enums\FinancingMode;
use App\Models\Agency;
use App\Models\SentAlert;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\VehicleDocument;
use App\Settings\NotificationSettings;
use App\Support\Authorization\Role;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

uses(RefreshDatabase::class);

/*
 * Alertes avant échéance (B-07, B-13, A-09, lot L7) : désactivées tant que le délai
 * est vide (Q90), une seule fois par échéance, aux bons destinataires, sans donnée client.
 */

beforeEach(function () {
    $this->superAdmin = userWithProfile(Role::SuperAdmin);
    $this->admin = userWithProfile(Role::Admin);
    $this->sfax = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->sousse = Agency::factory()->create(['code' => 'SOU', 'name' => 'Sousse']);
    $this->agentSfax = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sfax->id]);
    $this->agentSousse = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sousse->id]);
    $this->vehicle = Vehicle::factory()->create(['agency_id' => $this->sfax->id, 'vehicle_category_id' => VehicleCategory::factory()->create()->id])->refresh();
    $this->today = CarbonImmutable::parse('2026-12-01');
});

function alertSettings(string $event, ?int $days, array $recipients = ['admin']): void
{
    $settings = app(NotificationSettings::class);
    $alerts = $settings->alerts;
    $alerts[$event] = ['enabled' => true, 'days_before' => $days, 'recipients' => $recipients];
    $settings->alerts = $alerts;
    $settings->save();
}

function notificationsOf(User $user): array
{
    return DB::table('notifications')->where('notifiable_id', $user->id)->pluck('data')
        ->map(fn (string $data) => json_decode($data, true))->all();
}

function insurance(string $until): void
{
    VehicleDocument::query()->create(['vehicle_id' => test()->vehicle->id, 'type' => 'insurance', 'valid_from' => '2026-01-01', 'valid_until' => $until]);
}

it('n\'envoie rien tant que le délai n\'est pas renseigné, même active (Q90)', function () {
    insurance('2026-12-10');
    alertSettings('insurance_expiry', null);

    expect(app(DueDateAlerts::class)->run($this->today))->toBe(0);
});

it('prévient de la fin d\'assurance dans le délai, une seule fois par échéance', function () {
    insurance('2026-12-10');
    alertSettings('insurance_expiry', 15);
    $alerts = app(DueDateAlerts::class);

    expect($alerts->run($this->today))->toBe(1)
        ->and($alerts->run($this->today))->toBe(0)
        ->and($alerts->run($this->today->addDay()))->toBe(0)
        ->and(SentAlert::query()->count())->toBe(1);

    [$notification] = notificationsOf($this->admin);
    expect($notification['title'])->toBe('Assurance : échéance le 10/12/2026')
        ->and($notification['body'])->toContain($this->vehicle->plate_number);
});

it('ne prévient pas hors délai, ni pour une échéance déjà passée', function (string $until) {
    insurance($until);
    alertSettings('insurance_expiry', 15);

    expect(app(DueDateAlerts::class)->run($this->today))->toBe(0);
})->with(['2026-12-17', '2026-11-30']);

it('ne prévient plus quand le document a été renouvelé', function () {
    insurance('2026-12-10');
    insurance('2027-12-10');
    alertSettings('insurance_expiry', 15);

    expect(app(DueDateAlerts::class)->run($this->today))->toBe(0);
});

it('prévient à nouveau pour une nouvelle échéance', function () {
    insurance('2026-12-10');
    alertSettings('insurance_expiry', 15);
    app(DueDateAlerts::class)->run($this->today);

    insurance('2027-12-10');
    expect(app(DueDateAlerts::class)->run(CarbonImmutable::parse('2027-12-01')))->toBe(1)
        ->and(SentAlert::query()->count())->toBe(2);
});

it('n\'alerte l\'agent que pour les véhicules localisés dans son agence', function () {
    VehicleDocument::query()->create(['vehicle_id' => $this->vehicle->id, 'type' => 'technical_inspection', 'valid_from' => '2026-01-01', 'valid_until' => '2026-12-05']);
    alertSettings('technical_inspection', 30, ['agency_user']);

    app(DueDateAlerts::class)->run($this->today);

    expect(notificationsOf($this->agentSfax))->toHaveCount(1)
        ->and(notificationsOf($this->agentSousse))->toBe([])
        ->and(notificationsOf($this->admin))->toBe([]);
});

it('prévient de la fin de leasing et de la levée d\'option, jamais un agent', function () {
    $this->vehicle->forceFill(['financing_mode' => FinancingMode::Leasing])->saveQuietly();
    $lessor = Supplier::query()->create(['type' => 'leasing', 'name' => 'Société de leasing fictive', 'is_active' => true]);
    app(SaveFinancing::class)->handle($this->vehicle->fresh(), null, [
        'type' => 'leasing', 'supplier_id' => $lessor->id, 'reference' => 'LS-9', 'starts_on' => '2024-01-01', 'first_due_on' => '2024-02-01',
        'ends_on' => '2026-12-20', 'monthly_amount_excl_tax' => '900', 'monthly_amount_incl_tax' => '1071', 'purchase_option_on' => '2026-12-15',
    ], withTwoFactor($this->superAdmin));
    alertSettings('leasing_end', 30, ['admin', 'agency_user']);

    expect(app(DueDateAlerts::class)->run($this->today))->toBe(2);

    expect(collect(notificationsOf($this->admin))->pluck('title')->sort()->values()->all())
        ->toBe(['Fin du leasing le 20/12/2026', 'Levée d\'option le 15/12/2026'])
        ->and(notificationsOf($this->agentSfax))->toBe([]);
});

it('n\'alerte pas un compte inactif', function () {
    insurance('2026-12-10');
    alertSettings('insurance_expiry', 15);
    $this->admin->forceFill(['is_active' => false])->save();

    app(DueDateAlerts::class)->run($this->today);

    expect(notificationsOf($this->admin))->toBe([]);
});

it('planifie les alertes chaque jour à 06:05 et les expose en commande', function () {
    $event = collect(Schedule::events())->first(fn ($event) => $event->description === 'fleet-alerts');

    expect($event?->expression)->toBe('5 6 * * *');
    $this->artisan('fleet:alerts')->assertSuccessful();
});
