<?php

use App\Domain\Setup\Actions\SaveOption;
use App\Domain\Setup\Exceptions\SetupException;
use App\Domain\Tax\Actions\AddTaxRuleVersion;
use App\Domain\Tax\Actions\SetFiscalRuleActive;
use App\Filament\Resources\Options\OptionResource;
use App\Filament\Resources\Options\Pages\CreateOption;
use App\Filament\Resources\Options\Pages\EditOption;
use App\Filament\Resources\Options\Pages\ListOptions;
use App\Models\Agency;
use App\Models\Option;
use App\Models\TaxCode;
use App\Models\TaxRule;
use App\Support\Authorization\Role;
use Database\Seeders\FiscalReferenceSeeder;
use Database\Seeders\OptionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(FiscalReferenceSeeder::class);
    $this->seed(OptionSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->superAdmin = withTwoFactor(userWithProfile(Role::SuperAdmin));
    $this->admin = withTwoFactor(userWithProfile(Role::Admin));
    $this->agent = userWithProfile(Role::AgencyUser, ['agency_id' => Agency::factory()->create()->id]);
});

function optionData(array $overrides = []): array
{
    return array_merge([
        'code' => 'baby_seat', 'name' => 'Rehausseur', 'pricing_unit' => Option::PER_DAY,
        'amount' => '8,000', 'tax_code_id' => TaxCode::query()->where('code', 'CHILD_SEAT')->value('id'), 'is_active' => true,
    ], $overrides);
}

function seededOption(string $code): Option
{
    return Option::query()->where('code', $code)->sole();
}

it('crée les options du cahier, inactives et sans prix, avec le code fiscal du plan', function () {
    $codes = Option::query()->with('taxCode')->get()->mapWithKeys(fn (Option $o) => [$o->code => $o->taxCode?->code])->all();

    expect($codes)->toBe([
        'EXTRA_DRIVER' => 'EXTRA_DRIVER', 'CHILD_SEAT' => 'CHILD_SEAT', 'GPS' => 'GPS',
        'EXCESS_REDUCTION' => 'EXCESS_REDUCTION_OPTION', 'DELIVERY' => null,
    ])->and(Option::query()->where('is_active', true)->exists())->toBeFalse()
        ->and(Option::query()->whereNotNull('amount')->exists())->toBeFalse();
});

it('refuse d\'activer « livraison » tant qu\'elle n\'a pas de code fiscal (Q85)', function () {
    expect(fn () => app(SaveOption::class)->handle(seededOption('DELIVERY'), [
        'code' => 'DELIVERY', 'name' => 'Livraison', 'pricing_unit' => Option::PER_RENTAL, 'amount' => '20', 'is_active' => true,
    ], $this->admin))->toThrow(SetupException::class, 'code fiscal');

    expect(seededOption('DELIVERY')->is_active)->toBeFalse();
});

it('ne déduit jamais le code fiscal d\'un libellé « assurance »', function (string $name) {
    $option = app(SaveOption::class)->handle(null, optionData([
        'code' => 'ASSUR', 'name' => $name, 'tax_code_id' => null, 'is_active' => false,
    ]), $this->admin);

    expect($option->tax_code_id)->toBeNull();
    expect(fn () => app(SaveOption::class)->handle($option, optionData([
        'code' => 'ASSUR', 'name' => $name, 'tax_code_id' => null, 'is_active' => true,
    ]), $this->admin))->toThrow(SetupException::class, 'jamais déduit du libellé');
})->with(['Assurance tous risques', 'ASSURANCE', 'Assûrance complémentaire']);

it('active une option avec un code applicable et un prix HT', function () {
    $option = app(SaveOption::class)->handle(null, optionData(), $this->admin);

    expect($option->is_active)->toBeTrue()
        ->and($option->amount?->toDecimalString())->toBe('8.000');
});

it('refuse l\'activation sans prix, ou si la règle du code n\'est pas applicable', function () {
    expect(fn () => app(SaveOption::class)->handle(null, optionData(['amount' => '']), $this->admin))
        ->toThrow(SetupException::class, 'prix HT');

    $rule = TaxRule::query()->whereHas('taxCode', fn ($q) => $q->where('code', 'CHILD_SEAT'))->sole();
    app(SetFiscalRuleActive::class)->handle($rule, false, $this->superAdmin);

    expect(fn () => app(SaveOption::class)->handle(null, optionData(), $this->admin))
        ->toThrow(SetupException::class, 'Aucune règle fiscale active');
});

it('refuse l\'activation si la règle du code attend une pièce', function () {
    app(AddTaxRuleVersion::class)->handle(TaxCode::query()->where('code', 'GPS')->sole(), [
        'rate' => '19', 'valid_from' => now()->addDay()->toDateString(), 'validation_status' => 'PENDING_DOCUMENTATION',
    ], $this->superAdmin);
    $this->travelTo(now()->addDays(2));

    expect(fn () => app(SaveOption::class)->handle(null, optionData(['tax_code_id' => TaxCode::query()->where('code', 'GPS')->value('id')]), $this->admin))
        ->toThrow(SetupException::class, 'PENDING_DOCUMENTATION');
});

it('contrôle le plafond, l\'unité et l\'unicité du code', function () {
    expect(fn () => app(SaveOption::class)->handle(null, optionData(['max_amount_per_rental' => '5']), $this->admin))->toThrow(ValidationException::class)
        ->and(fn () => app(SaveOption::class)->handle(null, optionData(['pricing_unit' => 'per_hour']), $this->admin))->toThrow(ValidationException::class)
        ->and(fn () => app(SaveOption::class)->handle(null, optionData(['code' => 'GPS']), $this->admin))->toThrow(ValidationException::class)
        ->and(fn () => app(SaveOption::class)->handle(null, optionData(['amount' => 8.5]), $this->admin))->toThrow(ValidationException::class);
});

it('saisit le prix et active une option depuis l\'interface, journalisé', function () {
    $this->actingAs($this->admin);
    $gps = seededOption('GPS');

    Livewire::test(EditOption::class, ['record' => $gps->getRouteKey()])
        ->fillForm(['amount' => '6,500', 'is_active' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    $activity = Activity::query()->where('log_name', 'audit')->where('event', 'updated')->where('subject_type', Option::class)->sole();

    expect($gps->fresh()?->is_active)->toBeTrue()
        ->and($gps->fresh()?->amount?->toDecimalString())->toBe('6.500')
        ->and($activity->properties['attributes']['is_active'])->toBeTrue();
});

it('crée une option depuis l\'interface', function () {
    $this->actingAs($this->admin);

    Livewire::test(CreateOption::class)->fillForm(optionData())->call('create')->assertHasNoFormErrors();

    expect(Option::query()->where('code', 'BABY_SEAT')->exists())->toBeTrue();
});

it('laisse l\'utilisateur d\'agence lire les options, sans les modifier', function () {
    $this->actingAs($this->agent);
    $gps = seededOption('GPS');

    Livewire::test(ListOptions::class)
        ->assertCanSeeTableRecords([$gps])
        ->assertActionHidden('create')
        ->assertActionHidden(TestAction::make('edit')->table($gps));

    $this->get(OptionResource::getUrl('edit', ['record' => $gps]))->assertForbidden();
    expect(fn () => app(SaveOption::class)->handle($gps, optionData(['code' => 'GPS']), $this->agent))->toThrow(SetupException::class);
});
