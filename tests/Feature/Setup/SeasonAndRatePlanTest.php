<?php

use App\Domain\Setup\Actions\DeleteSeason;
use App\Domain\Setup\Actions\SaveRatePlan;
use App\Domain\Setup\Actions\SaveSeason;
use App\Domain\Setup\Exceptions\SetupException;
use App\Domain\Setup\RateResolver;
use App\Filament\Resources\RatePlans\Pages\CreateRatePlan;
use App\Filament\Resources\RatePlans\Pages\EditRatePlan;
use App\Filament\Resources\RatePlans\RatePlanResource;
use App\Filament\Resources\Seasons\Pages\ManageSeasons;
use App\Filament\Resources\Seasons\SeasonResource;
use App\Models\Agency;
use App\Models\RatePlan;
use App\Models\RatePlanLine;
use App\Models\Season;
use App\Models\VehicleCategory;
use App\Support\Authorization\Role;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = withTwoFactor(userWithProfile(Role::Admin));
    $this->sfax = Agency::factory()->create(['code' => 'SFX']);
    $this->agent = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sfax->id]);
    $this->category = VehicleCategory::factory()->create(['code' => 'ECO', 'name' => 'Citadine']);
    $this->high = app(SaveSeason::class)->handle(null, ['name' => 'Haute 2027', 'starts_on' => '2027-07-01', 'ends_on' => '2027-08-31'], $this->admin);
    $this->low = app(SaveSeason::class)->handle(null, ['name' => 'Basse 2027', 'starts_on' => '2027-09-01', 'ends_on' => '2027-12-31'], $this->admin);
});

function planData(array $overrides = []): array
{
    return array_merge([
        'vehicle_category_id' => test()->category->id,
        'season_id' => test()->high->id,
        'agency_id' => null,
        'valid_from' => '2027-01-01',
        'valid_to' => null,
        'is_active' => true,
        'lines' => [
            ['min_days' => 1, 'max_days' => 6, 'daily_amount' => '80,000'],
            ['min_days' => 7, 'max_days' => null, 'daily_amount' => '70,000'],
        ],
    ], $overrides);
}

function day(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date, 'Africa/Tunis');
}

// --- Saisons ------------------------------------------------------------------------

it('refuse une saison qui chevauche une autre, bornes comprises', function (string $start, string $end) {
    expect(fn () => app(SaveSeason::class)->handle(null, ['name' => 'Fêtes', 'starts_on' => $start, 'ends_on' => $end], $this->admin))
        ->toThrow(SetupException::class, 'chevauche');
    expect(Season::query()->count())->toBe(2);
})->with([
    'dernier jour commun' => ['2027-06-01', '2027-07-01'],
    'premier jour commun' => ['2027-08-31', '2027-08-31'],
    'à l\'intérieur' => ['2027-07-10', '2027-07-20'],
    'englobe' => ['2027-06-01', '2028-01-31'],
]);

it('accepte une saison contiguë et refuse une fin avant le début', function () {
    app(SaveSeason::class)->handle(null, ['name' => 'Printemps 2027', 'starts_on' => '2027-04-01', 'ends_on' => '2027-06-30'], $this->admin);

    expect(Season::query()->count())->toBe(3);
    expect(fn () => app(SaveSeason::class)->handle(null, ['name' => 'X', 'starts_on' => '2028-02-01', 'ends_on' => '2028-01-01'], $this->admin))
        ->toThrow(SetupException::class, 'fin précède');
});

it('modifie une saison sans se heurter à elle-même', function () {
    app(SaveSeason::class)->handle($this->high, ['name' => 'Haute 2027', 'starts_on' => '2027-06-15', 'ends_on' => '2027-08-31'], $this->admin);

    expect($this->high->fresh()?->starts_on->toDateString())->toBe('2027-06-15');
});

it('supprime une saison inutilisée seulement', function () {
    app(SaveRatePlan::class)->handle(null, planData(), $this->admin);

    expect(fn () => app(DeleteSeason::class)->handle($this->high, $this->admin))->toThrow(SetupException::class, 'utilisée');

    app(DeleteSeason::class)->handle($this->low, $this->admin);

    expect(Season::query()->pluck('id')->all())->toBe([$this->high->id]);
});

it('gère les saisons depuis l\'interface', function () {
    app(SaveRatePlan::class)->handle(null, planData(), $this->admin);
    $this->actingAs($this->admin);

    Livewire::test(ManageSeasons::class)
        ->callAction('create', ['name' => 'Hiver 2028', 'starts_on' => '2028-01-01', 'ends_on' => '2028-03-31'])
        ->assertHasNoFormErrors()
        ->assertActionHidden(TestAction::make('delete')->table($this->high))
        ->assertActionVisible(TestAction::make('delete')->table($this->low))
        ->callAction(TestAction::make('create'), ['name' => 'Doublon', 'starts_on' => '2028-02-01', 'ends_on' => '2028-02-10'])
        ->assertNotified('Saison refusée : elle chevauche la saison Hiver 2028 (01/01/2028 – 31/03/2028).');

    expect(Season::query()->where('name', 'Hiver 2028')->exists())->toBeTrue()
        ->and(Season::query()->where('name', 'Doublon')->exists())->toBeFalse();
});

// --- Grilles ------------------------------------------------------------------------

it('enregistre une grille commune, ses tranches et ses prix HT au millime', function () {
    $plan = app(SaveRatePlan::class)->handle(null, planData(), $this->admin);

    expect($plan->agency_id)->toBeNull()
        ->and($plan->lines->map(fn (RatePlanLine $l) => [$l->min_days, $l->max_days, $l->daily_amount->toDecimalString()])->all())
        ->toBe([[1, 6, '80.000'], [7, null, '70.000']]);
});

it('refuse deux grilles actives pour la même catégorie, saison et agence sur une même période', function (array $overrides) {
    app(SaveRatePlan::class)->handle(null, planData(['valid_from' => '2027-01-01', 'valid_to' => '2027-07-31']), $this->admin);

    expect(fn () => app(SaveRatePlan::class)->handle(null, planData($overrides), $this->admin))
        ->toThrow(SetupException::class, 'existe déjà');
})->with([
    'même début' => [['valid_from' => '2027-01-01']],
    'commence avant la fin' => [['valid_from' => '2027-07-31']],
    'sans fin, commence avant' => [['valid_from' => '2026-12-01', 'valid_to' => null]],
]);

it('accepte une grille qui prend la suite, une grille d\'agence, une autre saison ou une grille inactive', function () {
    app(SaveRatePlan::class)->handle(null, planData(['valid_to' => '2027-07-31']), $this->admin);

    app(SaveRatePlan::class)->handle(null, planData(['valid_from' => '2027-08-01']), $this->admin);
    app(SaveRatePlan::class)->handle(null, planData(['agency_id' => $this->sfax->id]), $this->admin);
    app(SaveRatePlan::class)->handle(null, planData(['season_id' => $this->low->id]), $this->admin);
    app(SaveRatePlan::class)->handle(null, planData(['is_active' => false]), $this->admin);

    expect(RatePlan::query()->count())->toBe(5);
});

it('refuse une validité incohérente, des tranches incohérentes ou une catégorie inactive', function () {
    expect(fn () => app(SaveRatePlan::class)->handle(null, planData(['valid_from' => '2027-08-01', 'valid_to' => '2027-07-01']), $this->admin))
        ->toThrow(SetupException::class, 'fin de validité');
    expect(fn () => app(SaveRatePlan::class)->handle(null, planData(['lines' => [['min_days' => 1, 'max_days' => 6, 'daily_amount' => '80']]]), $this->admin))
        ->toThrow(SetupException::class, 'sans limite');

    $this->category->update(['is_active' => false]);

    expect(fn () => app(SaveRatePlan::class)->handle(null, planData(), $this->admin))
        ->toThrow(SetupException::class, 'catégorie inactive');
    expect(RatePlan::query()->count())->toBe(0);
});

it('journalise le changement des tranches avec avant et après (J-04)', function () {
    $this->actingAs($this->admin);
    $plan = app(SaveRatePlan::class)->handle(null, planData(), $this->admin);

    app(SaveRatePlan::class)->handle($plan, planData(['lines' => [
        ['min_days' => 1, 'max_days' => 6, 'daily_amount' => '85'],
        ['min_days' => 7, 'max_days' => null, 'daily_amount' => '70'],
    ]]), $this->admin);

    $entries = Activity::query()->where('log_name', 'audit')->where('event', 'rate_plan_lines_updated')
        ->where('subject_id', $plan->id)->orderBy('id')->get();
    $last = $entries->last();

    expect($entries)->toHaveCount(2)
        ->and($last?->causer?->is($this->admin))->toBeTrue()
        ->and($last?->properties['old'][0]['daily_amount'])->toBe('80.000')
        ->and($last?->properties['attributes'][0]['daily_amount'])->toBe('85.000')
        ->and($plan->fresh()?->lines)->toHaveCount(2);
});

it('ne réécrit pas les tranches inchangées', function () {
    $plan = app(SaveRatePlan::class)->handle(null, planData(), $this->admin);
    $ids = $plan->lines->pluck('id')->all();

    app(SaveRatePlan::class)->handle($plan, planData(['valid_to' => '2027-12-31']), $this->admin);

    expect($plan->fresh()?->lines->pluck('id')->all())->toBe($ids)
        ->and(Activity::query()->where('event', 'rate_plan_lines_updated')->count())->toBe(1);
});

// --- Prix journalier (RateResolver) ----------------------------------------------

it('donne le prix du jour selon sa saison et la durée de la location', function () {
    app(SaveRatePlan::class)->handle(null, planData(), $this->admin);
    app(SaveRatePlan::class)->handle(null, planData(['season_id' => $this->low->id, 'lines' => [
        ['min_days' => 1, 'max_days' => null, 'daily_amount' => '50'],
    ]]), $this->admin);
    $resolver = app(RateResolver::class);

    expect($resolver->dailyAmount($this->category, null, day('2027-08-31'), 3)?->toDecimalString())->toBe('80.000')
        ->and($resolver->dailyAmount($this->category, null, day('2027-08-31'), 10)?->toDecimalString())->toBe('70.000')
        ->and($resolver->dailyAmount($this->category, null, day('2027-09-01'), 10)?->toDecimalString())->toBe('50.000');
});

it('applique la grille de l\'agence avant la grille commune (Q10)', function () {
    app(SaveRatePlan::class)->handle(null, planData(), $this->admin);
    app(SaveRatePlan::class)->handle(null, planData(['agency_id' => $this->sfax->id, 'lines' => [
        ['min_days' => 1, 'max_days' => null, 'daily_amount' => '90'],
    ]]), $this->admin);
    $djerba = Agency::factory()->create();
    $resolver = app(RateResolver::class);

    expect($resolver->dailyAmount($this->category, $this->sfax->id, day('2027-07-15'), 3)?->toDecimalString())->toBe('90.000')
        ->and($resolver->dailyAmount($this->category, $djerba->id, day('2027-07-15'), 3)?->toDecimalString())->toBe('80.000')
        ->and($resolver->dailyAmount($this->category, null, day('2027-07-15'), 3)?->toDecimalString())->toBe('80.000');
});

it('suit la validité au jour loué et ignore les grilles inactives', function () {
    app(SaveRatePlan::class)->handle(null, planData(['valid_to' => '2027-07-31']), $this->admin);
    app(SaveRatePlan::class)->handle(null, planData(['valid_from' => '2027-08-01', 'lines' => [
        ['min_days' => 1, 'max_days' => null, 'daily_amount' => '95'],
    ]]), $this->admin);
    app(SaveRatePlan::class)->handle(null, planData(['agency_id' => $this->sfax->id, 'is_active' => false, 'lines' => [
        ['min_days' => 1, 'max_days' => null, 'daily_amount' => '1'],
    ]]), $this->admin);
    $resolver = app(RateResolver::class);

    expect($resolver->dailyAmount($this->category, $this->sfax->id, day('2027-07-31'), 2)?->toDecimalString())->toBe('80.000')
        ->and($resolver->dailyAmount($this->category, $this->sfax->id, day('2027-08-01'), 2)?->toDecimalString())->toBe('95.000');
});

it('ne donne aucun prix hors saison ou sans grille, jamais zéro', function () {
    app(SaveRatePlan::class)->handle(null, planData(), $this->admin);
    $other = VehicleCategory::factory()->create();
    $resolver = app(RateResolver::class);

    expect($resolver->dailyAmount($this->category, null, day('2027-06-30'), 3))->toBeNull()
        ->and($resolver->dailyAmount($other, null, day('2027-07-15'), 3))->toBeNull();
});

// --- Interface et droits -----------------------------------------------------------

it('crée et modifie une grille depuis l\'interface, avec ses tranches', function () {
    $this->actingAs($this->admin);

    Livewire::test(CreateRatePlan::class)
        ->fillForm(planData())
        ->call('create')
        ->assertHasNoFormErrors();

    $plan = RatePlan::query()->sole();

    Livewire::test(EditRatePlan::class, ['record' => $plan->getRouteKey()])
        ->assertSchemaStateSet(['valid_from' => '2027-01-01', 'agency_id' => null])
        ->fillForm(['lines' => [
            ['min_days' => 1, 'max_days' => 2, 'daily_amount' => '90,000'],
            ['min_days' => 3, 'max_days' => null, 'daily_amount' => '75,500'],
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($plan->fresh()?->lines->map(fn (RatePlanLine $l) => $l->daily_amount->toDecimalString())->all())
        ->toBe(['90.000', '75.500']);
});

it('réserve saisons et grilles au super admin et à l\'admin', function () {
    $this->actingAs($this->agent);

    $this->get(SeasonResource::getUrl())->assertForbidden();
    $this->get(RatePlanResource::getUrl())->assertForbidden();

    expect(fn () => app(SaveSeason::class)->handle(null, ['name' => 'X', 'starts_on' => '2029-01-01', 'ends_on' => '2029-01-31'], $this->agent))
        ->toThrow(SetupException::class);
    expect(fn () => app(SaveRatePlan::class)->handle(null, planData(), $this->agent))
        ->toThrow(SetupException::class);
});
