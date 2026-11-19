<?php

use App\Domain\Setup\Actions\SavePaymentMethod;
use App\Domain\Setup\Actions\SaveSupplier;
use App\Domain\Setup\Exceptions\SetupException;
use App\Filament\Resources\PaymentMethods\Pages\ManagePaymentMethods;
use App\Filament\Resources\PaymentMethods\PaymentMethodResource;
use App\Filament\Resources\Suppliers\Pages\ManageSuppliers;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\Agency;
use App\Models\PaymentMethod;
use App\Models\Supplier;
use App\Settings\BillingSettings;
use App\Support\Authorization\Role;
use Database\Seeders\PaymentMethodSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PaymentMethodSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = withTwoFactor(userWithProfile(Role::Admin));
    $this->agent = userWithProfile(Role::AgencyUser, ['agency_id' => Agency::factory()->create()->id]);
});

// --- Modes et conditions de paiement (A-07) ------------------------------------------

it('crée les cinq modes de paiement, référence exigée sauf pour les espèces', function () {
    expect(PaymentMethod::query()->orderBy('id')->pluck('requires_reference', 'code')->all())->toBe([
        'cash' => false, 'cheque' => true, 'card' => true, 'transfer' => true, 'bill_of_exchange' => true,
    ]);

    $this->seed(PaymentMethodSeeder::class);

    expect(PaymentMethod::query()->count())->toBe(5);
});

it('pose les conditions de paiement par défaut : comptant (Q89)', function () {
    expect(app(BillingSettings::class)->payment_terms_days)->toBe(0);
});

it('ajoute un mode, fixe son code et désactive sans supprimer', function () {
    $this->actingAs($this->admin);

    Livewire::test(ManagePaymentMethods::class)
        ->callAction('create', ['code' => 'konnect', 'name' => 'Paiement mobile', 'requires_reference' => true, 'is_active' => true])
        ->assertHasNoFormErrors()
        ->assertActionDoesNotExist(TestAction::make('delete')->table(PaymentMethod::query()->where('code', 'cash')->sole()));

    $mobile = PaymentMethod::query()->where('code', 'konnect')->sole();

    expect(fn () => app(SavePaymentMethod::class)->handle($mobile, ['code' => 'other', 'name' => 'X'], $this->admin))
        ->toThrow(SetupException::class, 'ne se modifie pas');

    app(SavePaymentMethod::class)->handle($mobile, ['name' => 'Paiement mobile', 'is_active' => false], $this->admin);

    expect($mobile->fresh()?->is_active)->toBeFalse()
        ->and(Activity::query()->where('subject_type', PaymentMethod::class)->where('event', 'updated')->exists())->toBeTrue();
});

it('refuse un code de mode invalide ou déjà pris', function (string $code) {
    expect(fn () => app(SavePaymentMethod::class)->handle(null, ['code' => $code, 'name' => 'X'], $this->admin))
        ->toThrow(ValidationException::class);
})->with(['cash', 'x', 'carte bleue']);

it('laisse l\'agence lire les modes de paiement sans les modifier', function () {
    $this->actingAs($this->agent);

    Livewire::test(ManagePaymentMethods::class)
        ->assertCanSeeTableRecords(PaymentMethod::all())
        ->assertActionHidden('create');

    expect(fn () => app(SavePaymentMethod::class)->handle(null, ['code' => 'new', 'name' => 'X'], $this->agent))
        ->toThrow(SetupException::class);
});

// --- Fournisseurs (G-05) ----------------------------------------------------------------

it('crée un fournisseur depuis l\'interface, journalisé', function () {
    $this->actingAs($this->admin);

    Livewire::test(ManageSuppliers::class)
        ->callAction('create', ['type' => 'garage', 'name' => 'Garage du Sud', 'tax_id' => '1234567a', 'email' => 'Contact@Garage.example', 'is_active' => true])
        ->assertHasNoFormErrors();

    $supplier = Supplier::query()->sole();

    expect($supplier->tax_id)->toBe('1234567A')
        ->and($supplier->email)->toBe('contact@garage.example')
        ->and(Activity::query()->where('subject_type', Supplier::class)->where('event', 'created')->exists())->toBeTrue();
});

it('refuse un type inconnu ou un nom manquant', function (array $data) {
    expect(fn () => app(SaveSupplier::class)->handle(null, $data, $this->admin))->toThrow(ValidationException::class);
})->with([
    [['type' => 'restaurant', 'name' => 'X']],
    [['type' => 'garage', 'name' => '']],
]);

it('réserve les fournisseurs au super admin et à l\'admin', function () {
    $this->actingAs($this->agent)->get(SupplierResource::getUrl())->assertForbidden();
    $this->actingAs($this->admin)->get(SupplierResource::getUrl())->assertOk();
    $this->actingAs($this->agent)->get(PaymentMethodResource::getUrl())->assertOk();

    expect(fn () => app(SaveSupplier::class)->handle(null, ['type' => 'garage', 'name' => 'X'], $this->agent))
        ->toThrow(SetupException::class);
});
