<?php

use App\Domain\Setup\Actions\UpdateSettings;
use App\Domain\Setup\Exceptions\SetupException;
use App\Filament\Pages\Settings\ApprovalSettingsPage;
use App\Filament\Pages\Settings\BillingSettingsPage;
use App\Filament\Pages\Settings\ContractSettingsPage;
use App\Filament\Pages\Settings\InspectionSettingsPage;
use App\Filament\Pages\Settings\NotificationSettingsPage;
use App\Filament\Pages\Settings\PricingSettingsPage;
use App\Filament\Pages\Settings\ReservationSettingsPage;
use App\Filament\Pages\Settings\SecuritySettingsPage;
use App\Models\Agency;
use App\Settings\ApprovalSettings;
use App\Settings\BillingSettings;
use App\Settings\NotificationSettings;
use App\Settings\PricingSettings;
use App\Settings\ReservationSettings;
use App\Settings\SecuritySettings;
use App\Support\Authorization\Role;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->superAdmin = withTwoFactor(userWithProfile(Role::SuperAdmin));
    $this->admin = withTwoFactor(userWithProfile(Role::Admin));
    $this->agent = userWithProfile(Role::AgencyUser, ['agency_id' => Agency::factory()->create()->id]);
});

it('pose les valeurs par défaut des questions ouvertes, vides quand rien n\'est décidé', function () {
    $reservations = app(ReservationSettings::class);
    $pricing = app(PricingSettings::class);
    $approvals = app(ApprovalSettings::class);
    $alerts = app(NotificationSettings::class)->alerts;

    expect($reservations->min_rental_days)->toBeNull()
        ->and($reservations->min_notice_hours)->toBeNull()
        ->and($reservations->draft_expiry_hours)->toBeNull()
        ->and($reservations->no_show_after_minutes)->toBe(120)
        ->and($reservations->allow_one_way)->toBeFalse()
        ->and($pricing->day_mode)->toBe('rolling_24h')
        ->and($pricing->late_tolerance_minutes)->toBe(60)
        ->and($pricing->late_unit)->toBe('day')
        ->and($approvals->discount_threshold_percent)->toBe('0')
        ->and($approvals->delegate_to_admin)->toBeFalse()
        ->and($alerts['insurance_expiry'])->toBe(['enabled' => false, 'days_before' => null, 'recipients' => ['admin']])
        ->and($alerts['approval_request']['recipients'])->toBe(['super_admin']);
});

it('réserve toutes les pages de réglages au super admin', function (string $page) {
    $this->actingAs($this->superAdmin)->get($page::getUrl())->assertOk();
    $this->actingAs($this->admin)->get($page::getUrl())->assertForbidden();
    $this->actingAs($this->agent)->get($page::getUrl())->assertForbidden();
})->with([
    ReservationSettingsPage::class, PricingSettingsPage::class, BillingSettingsPage::class,
    ApprovalSettingsPage::class, NotificationSettingsPage::class, SecuritySettingsPage::class, InspectionSettingsPage::class,
    ContractSettingsPage::class,
]);

it('refuse la modification à l\'admin, même hors de l\'interface', function () {
    expect(fn () => app(UpdateSettings::class)->handle(app(SecuritySettings::class), ['require_2fa_for_agency_users' => true], $this->admin))
        ->toThrow(SetupException::class);
    expect(app(SecuritySettings::class)->require_2fa_for_agency_users)->toBeFalse();
});

it('enregistre les règles de réservation depuis la page et journalise avant et après', function () {
    $this->actingAs($this->superAdmin);

    Livewire::test(ReservationSettingsPage::class)
        ->assertSchemaStateSet(['no_show_after_minutes' => 120, 'min_rental_days' => null])
        ->fillForm(['min_rental_days' => '2', 'min_notice_hours' => '', 'no_show_after_minutes' => '90'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Réglages enregistrés');

    $settings = app(ReservationSettings::class);
    $settings->refresh();
    $activity = Activity::query()->where('event', 'settings_updated')->sole();

    expect($settings->min_rental_days)->toBe(2)
        ->and($settings->min_notice_hours)->toBeNull()
        ->and($settings->no_show_after_minutes)->toBe(90)
        ->and($activity->causer?->is($this->superAdmin))->toBeTrue()
        ->and($activity->properties['group'])->toBe('reservations')
        ->and($activity->properties['old'])->toBe(['min_rental_days' => null, 'no_show_after_minutes' => 120])
        ->and($activity->properties['attributes'])->toBe(['min_rental_days' => 2, 'no_show_after_minutes' => 90]);
});

it('ne journalise rien si rien ne change', function () {
    $this->actingAs($this->superAdmin);

    Livewire::test(PricingSettingsPage::class)->call('save')->assertHasNoFormErrors();

    expect(Activity::query()->where('event', 'settings_updated')->exists())->toBeFalse();
});

it('refuse des valeurs hors limites et le signale sur le champ', function () {
    $this->actingAs($this->superAdmin);

    Livewire::test(PricingSettingsPage::class)
        ->fillForm(['day_mode' => 'weekly'])
        ->call('save')
        ->assertHasFormErrors(['day_mode']);

    expect(fn () => app(UpdateSettings::class)->handle(app(ReservationSettings::class), ['min_driver_age' => '16'], $this->superAdmin))
        ->toThrow(ValidationException::class);
    expect(app(PricingSettings::class)->day_mode)->toBe('rolling_24h');
});

it('ignore les clés hors du catalogue', function () {
    app(UpdateSettings::class)->handle(app(BillingSettings::class), ['payment_terms_days' => '30', 'unknown' => 'x'], $this->superAdmin);

    expect(app(BillingSettings::class)->payment_terms_days)->toBe(30);
});

it('normalise le seuil de remise en pourcentage, sans float', function (string $input, string $stored) {
    app(UpdateSettings::class)->handle(app(ApprovalSettings::class), ['discount_threshold_percent' => $input], $this->superAdmin);

    expect(app(ApprovalSettings::class)->discount_threshold_percent)->toBe($stored);
})->with([['5', '5.00'], ['7,5', '7.50'], ['100', '100.00']]);

it('refuse un seuil de remise invalide', function (string $input) {
    expect(fn () => app(UpdateSettings::class)->handle(app(ApprovalSettings::class), ['discount_threshold_percent' => $input], $this->superAdmin))
        ->toThrow(ValidationException::class);
})->with(['101', '-1', '5,555', 'cinq']);

it('n\'active une alerte datée qu\'avec son délai, et contrôle les destinataires', function () {
    $alerts = app(NotificationSettings::class)->alerts;

    $enabled = $alerts;
    $enabled['insurance_expiry']['enabled'] = true;

    expect(fn () => app(UpdateSettings::class)->handle(app(NotificationSettings::class), ['alerts' => $enabled], $this->superAdmin))
        ->toThrow(ValidationException::class);

    $enabled['insurance_expiry']['days_before'] = 30;
    $enabled['insurance_expiry']['recipients'] = ['admin', 'super_admin'];
    app(UpdateSettings::class)->handle(app(NotificationSettings::class), ['alerts' => $enabled], $this->superAdmin);

    expect(app(NotificationSettings::class)->alerts['insurance_expiry'])
        ->toBe(['enabled' => true, 'days_before' => 30, 'recipients' => ['admin', 'super_admin']]);

    $wrong = $enabled;
    $wrong['late_return']['recipients'] = ['customer'];

    expect(fn () => app(UpdateSettings::class)->handle(app(NotificationSettings::class), ['alerts' => $wrong], $this->superAdmin))
        ->toThrow(ValidationException::class);
});

it('modifie les alertes et la 2FA des agences depuis les pages', function () {
    $this->actingAs($this->superAdmin);

    Livewire::test(NotificationSettingsPage::class)
        ->fillForm(['alerts.leasing_end.enabled' => true, 'alerts.leasing_end.days_before' => '60'])
        ->call('save')
        ->assertHasNoFormErrors();

    Livewire::test(SecuritySettingsPage::class)
        ->fillForm(['require_2fa_for_agency_users' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(NotificationSettings::class)->alerts['leasing_end']['days_before'])->toBe(60)
        ->and(app(SecuritySettings::class)->require_2fa_for_agency_users)->toBeTrue();
});
