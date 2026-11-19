<?php

use App\Domain\Setup\Actions\SaveAgency;
use App\Domain\Setup\Actions\SaveAgencyLocation;
use App\Domain\Setup\Exceptions\SetupException;
use App\Filament\Resources\Agencies\AgencyResource;
use App\Filament\Resources\Agencies\Pages\CreateAgency;
use App\Filament\Resources\Agencies\Pages\EditAgency;
use App\Filament\Resources\Agencies\Pages\ListAgencies;
use App\Filament\Resources\Agencies\Pages\ViewAgency;
use App\Filament\Resources\Agencies\RelationManagers\LocationsRelationManager;
use App\Models\Agency;
use App\Models\AgencyLocation;
use App\Support\Agency\AgencyAccessException;
use App\Support\Authorization\Role;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->superAdmin = withTwoFactor(userWithProfile(Role::SuperAdmin));
    $this->admin = withTwoFactor(userWithProfile(Role::Admin));
    $this->djerba = Agency::factory()->headOffice()->create(['code' => 'DJE', 'name' => 'Djerba']);
    $this->sfax = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->agent = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sfax->id]);
});

function locationData(array $overrides = []): array
{
    return array_merge([
        'name' => 'Aéroport',
        'address' => 'Aéroport de Djerba-Zarzis',
        'is_pickup' => true,
        'is_return' => true,
        'is_active' => true,
        'opening_hours' => [
            'monday' => [['open' => '08:00', 'close' => '18:00']],
            'sunday' => [],
        ],
    ], $overrides);
}

// --- Agences : droits ----------------------------------------------------------

it('liste et ouvre les agences pour le super admin et l\'admin', function (string $who) {
    $this->actingAs($this->{$who});

    Livewire::test(ListAgencies::class)
        ->assertCanSeeTableRecords([$this->djerba, $this->sfax])
        ->assertActionDoesNotExist(TestAction::make('delete')->table($this->sfax));

    $this->get(AgencyResource::getUrl('edit', ['record' => $this->sfax]))->assertOk();
})->with(['superAdmin', 'admin']);

it('montre à l\'utilisateur d\'agence sa seule agence, en lecture', function () {
    $this->actingAs($this->agent);

    Livewire::test(ListAgencies::class)
        ->assertCanSeeTableRecords([$this->sfax])
        ->assertCanNotSeeTableRecords([$this->djerba])
        ->assertActionHidden('create');

    $this->get(AgencyResource::getUrl('view', ['record' => $this->sfax]))->assertOk();
    $this->get(AgencyResource::getUrl('edit', ['record' => $this->sfax]))->assertForbidden();
    $this->get(AgencyResource::getUrl('create'))->assertForbidden();
    $this->get(AgencyResource::getUrl('view', ['record' => $this->djerba]))->assertNotFound();
});

it('refuse toute écriture d\'agence à l\'utilisateur d\'agence, même hors de l\'interface', function () {
    expect(fn () => app(SaveAgency::class)->handle($this->sfax, ['name' => 'Autre', 'city' => 'Sfax'], $this->agent))
        ->toThrow(SetupException::class);
    expect(fn () => app(SaveAgency::class)->handle(null, ['code' => 'GAB', 'name' => 'Gabès', 'city' => 'Gabès'], $this->agent))
        ->toThrow(SetupException::class);
    expect($this->sfax->fresh()?->name)->toBe('Sfax');
});

// --- Agences : règles ----------------------------------------------------------

it('ajoute une agence sans développement (A-02), code en majuscules, jamais siège', function () {
    $this->actingAs($this->admin);

    Livewire::test(CreateAgency::class)
        ->fillForm(['code' => 'gab', 'name' => 'Gabès', 'city' => 'Gabès', 'is_active' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    $gabes = Agency::query()->where('code', 'GAB')->sole();

    expect($gabes->is_head_office)->toBeFalse()
        ->and($gabes->is_active)->toBeTrue();
});

it('refuse un code d\'agence invalide ou déjà pris', function (string $code, string $message) {
    expect(fn () => app(SaveAgency::class)->handle(null, ['code' => $code, 'name' => 'X', 'city' => 'X'], $this->admin))
        ->toThrow(SetupException::class, $message);
})->with([
    'déjà pris' => ['sfx', 'déjà utilisé'],
    'trop court' => ['G', 'Code d\'agence refusé'],
    'caractères' => ['GA-B', 'Code d\'agence refusé'],
]);

it('fixe le code à la création', function () {
    $this->actingAs($this->admin);

    Livewire::test(EditAgency::class, ['record' => $this->sfax->getRouteKey()])
        ->assertFormFieldDisabled('code');

    expect(fn () => app(SaveAgency::class)->handle($this->sfax, ['code' => 'SFA', 'name' => 'Sfax', 'city' => 'Sfax'], $this->admin))
        ->toThrow(SetupException::class, 'ne se modifie pas');
    expect($this->sfax->fresh()?->code)->toBe('SFX');
});

it('modifie une agence depuis la page, sans toucher au code ni au siège', function () {
    $this->actingAs($this->admin);

    Livewire::test(EditAgency::class, ['record' => $this->sfax->getRouteKey()])
        ->fillForm(['name' => 'Sfax centre', 'phone' => '+216 74 000 000'])
        ->call('save')
        ->assertHasNoFormErrors();

    $sfax = $this->sfax->fresh();

    expect($sfax?->name)->toBe('Sfax centre')
        ->and($sfax?->code)->toBe('SFX')
        ->and($sfax?->is_head_office)->toBeFalse();
});

it('ne désactive ni le siège ni une agence qui a des utilisateurs actifs', function () {
    expect(fn () => app(SaveAgency::class)->handle($this->djerba, ['name' => 'Djerba', 'city' => 'Djerba', 'is_active' => false], $this->superAdmin))
        ->toThrow(SetupException::class, 'siège');
    expect(fn () => app(SaveAgency::class)->handle($this->sfax, ['name' => 'Sfax', 'city' => 'Sfax', 'is_active' => false], $this->superAdmin))
        ->toThrow(SetupException::class, '1 utilisateur(s) actif(s)');

    $this->agent->update(['is_active' => false]);
    app(SaveAgency::class)->handle($this->sfax, ['name' => 'Sfax', 'city' => 'Sfax', 'is_active' => false], $this->superAdmin);

    expect($this->sfax->fresh()?->is_active)->toBeFalse()
        ->and($this->djerba->fresh()?->is_active)->toBeTrue();
});

it('journalise la modification d\'une agence avec avant et après', function () {
    $this->actingAs($this->admin);
    app(SaveAgency::class)->handle($this->sfax, ['name' => 'Sfax ville', 'city' => 'Sfax'], $this->admin);

    $activity = Activity::query()->where('log_name', 'audit')->where('event', 'updated')
        ->where('subject_type', Agency::class)->where('subject_id', $this->sfax->id)->sole();

    expect($activity->causer?->is($this->admin))->toBeTrue()
        ->and($activity->properties['old']['name'])->toBe('Sfax')
        ->and($activity->properties['attributes']['name'])->toBe('Sfax ville');
});

// --- Lieux et horaires ------------------------------------------------------------

it('ajoute un lieu avec ses horaires depuis la fiche de l\'agence', function () {
    $this->actingAs($this->admin);

    Livewire::test(LocationsRelationManager::class, ['ownerRecord' => $this->djerba, 'pageClass' => EditAgency::class])
        ->callAction(TestAction::make('create')->table(), locationData())
        ->assertHasNoFormErrors();

    $location = AgencyLocation::query()->sole();

    expect($location->agency_id)->toBe($this->djerba->id)
        ->and($location->opening_hours['monday'])->toBe([['open' => '08:00', 'close' => '18:00']])
        ->and($location->opening_hours['sunday'])->toBe([])
        ->and($location->openingHours()->summary())->toBe('lundi : 08:00 – 18:00 ; mardi – dimanche : fermé');
});

it('enregistre « horaires non renseignés » quand aucune plage n\'est saisie', function () {
    $location = app(SaveAgencyLocation::class)->handle(null, $this->djerba, locationData([
        'opening_hours' => ['monday' => [], 'tuesday' => []],
    ]), $this->admin);

    expect($location->opening_hours)->toBeNull()
        ->and($location->openingHours()->isDefined())->toBeFalse();
});

it('refuse des horaires incohérents et ne crée rien', function () {
    expect(fn () => app(SaveAgencyLocation::class)->handle(null, $this->djerba, locationData([
        'opening_hours' => ['friday' => [['open' => '18:00', 'close' => '08:00']]],
    ]), $this->admin))->toThrow(SetupException::class, 'vendredi');

    expect(AgencyLocation::query()->count())->toBe(0);
});

it('exige qu\'un lieu serve à la prise en charge ou à la restitution', function () {
    expect(fn () => app(SaveAgencyLocation::class)->handle(null, $this->djerba, locationData([
        'is_pickup' => false, 'is_return' => false,
    ]), $this->admin))->toThrow(SetupException::class, 'au moins');
});

it('refuse deux lieux du même nom dans une agence, mais l\'accepte dans une autre', function () {
    app(SaveAgencyLocation::class)->handle(null, $this->djerba, locationData(), $this->admin);

    expect(fn () => app(SaveAgencyLocation::class)->handle(null, $this->djerba, locationData(), $this->admin))
        ->toThrow(SetupException::class, 'déjà un lieu');

    app(SaveAgencyLocation::class)->handle(null, $this->sfax, locationData(), $this->admin);

    expect(AgencyLocation::query()->count())->toBe(2);
});

it('ne déplace pas un lieu vers une autre agence', function () {
    $location = app(SaveAgencyLocation::class)->handle(null, $this->djerba, locationData(), $this->admin);

    expect(fn () => app(SaveAgencyLocation::class)->handle($location, $this->sfax, locationData(), $this->admin))
        ->toThrow(SetupException::class);
    expect($location->fresh()?->agency_id)->toBe($this->djerba->id);
});

it('modifie un lieu et journalise avant et après', function () {
    $this->actingAs($this->admin);
    $location = app(SaveAgencyLocation::class)->handle(null, $this->djerba, locationData(), $this->admin);

    app(SaveAgencyLocation::class)->handle($location, $this->djerba, locationData([
        'opening_hours' => ['monday' => [['open' => '07:00', 'close' => '19:00']]],
    ]), $this->admin);

    $activity = Activity::query()->where('log_name', 'audit')->where('event', 'updated')
        ->where('subject_type', AgencyLocation::class)->sole();

    expect($activity->properties['old']['opening_hours']['monday'][0]['open'])->toBe('08:00')
        ->and($activity->properties['attributes']['opening_hours']['monday'][0]['open'])->toBe('07:00');
});

it('ne propose aucune suppression de lieu', function () {
    $location = AgencyLocation::factory()->for($this->djerba)->create();
    $this->actingAs($this->admin);

    Livewire::test(LocationsRelationManager::class, ['ownerRecord' => $this->djerba, 'pageClass' => EditAgency::class])
        ->assertCanSeeTableRecords([$location])
        ->assertActionDoesNotExist(TestAction::make('delete')->table($location));
});

// --- Lieux : portée par agence (RG-13) ------------------------------------------

it('montre à l\'utilisateur d\'agence les lieux de son agence, en lecture seule', function () {
    $sfaxLocation = AgencyLocation::factory()->for($this->sfax)->create(['name' => 'Gare de Sfax']);
    $djerbaLocation = AgencyLocation::factory()->for($this->djerba)->create(['name' => 'Aéroport']);
    $this->actingAs($this->agent);

    expect(AgencyLocation::query()->pluck('id')->all())->toBe([$sfaxLocation->id]);

    Livewire::test(LocationsRelationManager::class, ['ownerRecord' => $this->sfax, 'pageClass' => ViewAgency::class])
        ->assertCanSeeTableRecords([$sfaxLocation])
        ->assertActionHidden(TestAction::make('create')->table())
        ->assertActionHidden(TestAction::make('edit')->table($sfaxLocation));

    expect(fn () => app(SaveAgencyLocation::class)->handle($sfaxLocation, $this->sfax, locationData(), $this->agent))
        ->toThrow(SetupException::class);
    expect($djerbaLocation->fresh())->not->toBeNull();
});

it('refuse l\'écriture d\'un lieu d\'une autre agence même en contournant la policy (BelongsToAgency)', function () {
    $this->actingAs($this->agent);

    expect(fn () => AgencyLocation::query()->create([
        'agency_id' => $this->djerba->id, 'name' => 'Faux lieu', 'is_pickup' => true, 'is_return' => true, 'is_active' => true,
    ]))->toThrow(AgencyAccessException::class);
});
