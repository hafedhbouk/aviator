<?php

use App\Domain\Availability\Period;
use App\Domain\Pricing\BillableDurationCalculator;
use App\Domain\Pricing\Exceptions\QuoteUnavailable;
use App\Domain\Pricing\PriceQuote;
use App\Domain\Pricing\QuoteCalculator;
use App\Domain\Setup\Actions\SaveRatePlan;
use App\Domain\Setup\Actions\SaveSeason;
use App\Models\Agency;
use App\Models\Option;
use App\Models\TaxCode;
use App\Models\VehicleCategory;
use App\Settings\ApprovalSettings;
use App\Settings\PricingSettings;
use App\Support\Authorization\Role;
use App\Support\Money\Money;
use Database\Seeders\FiscalReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Devis d'une location (C-06, lot L10) : durée facturée (Q32), tarif dégressif et
 * saison de chaque jour (Q33), grille d'agence (Q10), options, remise (D36), taxes.
 */

beforeEach(function () {
    $this->admin = withTwoFactor(userWithProfile(Role::Admin));
    $this->sfax = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->category = VehicleCategory::factory()->create(['code' => 'CIT', 'name' => 'Citadine']);
    $this->high = app(SaveSeason::class)->handle(null, ['name' => 'Haute 2027', 'starts_on' => '2027-07-01', 'ends_on' => '2027-08-31'], $this->admin);
    $this->low = app(SaveSeason::class)->handle(null, ['name' => 'Basse 2027', 'starts_on' => '2027-09-01', 'ends_on' => '2027-12-31'], $this->admin);

    $plan = fn (int $seasonId, array $amounts, ?int $agencyId = null) => app(SaveRatePlan::class)->handle(null, [
        'vehicle_category_id' => $this->category->id, 'season_id' => $seasonId, 'agency_id' => $agencyId,
        'valid_from' => '2027-01-01', 'valid_to' => null, 'is_active' => true,
        'lines' => [
            ['min_days' => 1, 'max_days' => 3, 'daily_amount' => $amounts[0]],
            ['min_days' => 4, 'max_days' => 7, 'daily_amount' => $amounts[1]],
            ['min_days' => 8, 'max_days' => null, 'daily_amount' => $amounts[2]],
        ],
    ], $this->admin);

    $plan($this->high->id, ['120,000', '110,000', '100,000']);
    $plan($this->low->id, ['80,000', '70,000', '60,000']);
    $this->plan = $plan;
    $this->quotes = app(QuoteCalculator::class);
});

function quoteFor(string $from, string $to, array $options = [], ?string $discount = null): PriceQuote
{
    return test()->quotes->quote(test()->category->id, test()->sfax->id, Period::between($from, $to), $options, $discount === null ? null : Money::of($discount));
}

// --- Durée facturée (Q32) ------------------------------------------------------------

it('compte les tranches de 24 h, avec la tolérance de retard sur la dernière', function (string $to, int $days) {
    expect(app(BillableDurationCalculator::class)->days(Period::between('2027-09-10 09:00', $to)))->toHaveCount($days);
})->with([
    'deux heures' => ['2027-09-10 11:00', 1],
    'trois jours pile' => ['2027-09-13 09:00', 3],
    'trois jours et 60 min (tolérance)' => ['2027-09-13 10:00', 3],
    'trois jours et 61 min' => ['2027-09-13 10:01', 4],
]);

it('compte des jours calendaires quand le réglage le demande', function () {
    $settings = app(PricingSettings::class);
    $settings->day_mode = 'calendar';
    $settings->save();

    $days = app(BillableDurationCalculator::class)->days(Period::between('2027-09-10 18:00', '2027-09-13 09:00'));

    expect(array_map(fn ($day) => $day->toDateString(), $days))->toBe(['2027-09-10', '2027-09-11', '2027-09-12']);
});

// --- Prix (Q33, Q10) -----------------------------------------------------------------

it('applique le tarif de la tranche de durée totale à chaque jour (tarif dégressif)', function () {
    $quote = quoteFor('2027-09-10 09:00', '2027-09-20 09:00');

    expect($quote->billableDays())->toBe(10)
        ->and((string) $quote->rentalAmount)->toBe('600.000')
        ->and((string) $quote->totalExcludingTax)->toBe('600.000');
});

it('valorise chaque jour selon sa saison, pour une location à cheval sur deux saisons', function () {
    // 29/08 → 03/09 : 5 jours (tranche 4 à 7), 3 en haute saison à 110, 2 en basse à 70.
    $quote = quoteFor('2027-08-29 10:00', '2027-09-03 10:00');

    expect(array_column($quote->days, 'season'))->toBe(['Haute 2027', 'Haute 2027', 'Haute 2027', 'Basse 2027', 'Basse 2027'])
        ->and((string) $quote->rentalAmount)->toBe('470.000');
});

it('préfère la grille de l\'agence de départ à la grille commune', function () {
    ($this->plan)($this->low->id, ['75,000', '65,000', '55,000'], $this->sfax->id);

    expect((string) quoteFor('2027-09-10 09:00', '2027-09-12 09:00')->rentalAmount)->toBe('150.000');
});

it('refuse un devis quand un jour n\'a pas de grille, sans jamais tarifer à zéro', function () {
    expect(fn () => quoteFor('2027-12-30 09:00', '2028-01-02 09:00'))
        ->toThrow(QuoteUnavailable::class, 'Aucun tarif pour la catégorie Citadine le 01/01/2028');
});

it('refuse une catégorie inactive', function () {
    $this->category->forceFill(['is_active' => false])->save();

    expect(fn () => quoteFor('2027-09-10 09:00', '2027-09-12 09:00'))->toThrow(QuoteUnavailable::class, 'inactive');
});

// --- Options ---------------------------------------------------------------------------

function quoteOption(string $code, string $unit, ?string $amount, ?string $max = null, bool $active = true): Option
{
    test()->seed(FiscalReferenceSeeder::class);

    return Option::query()->create([
        'code' => $code, 'name' => $code, 'pricing_unit' => $unit, 'amount' => $amount === null ? null : Money::of($amount),
        'max_amount_per_rental' => $max === null ? null : Money::of($max),
        'tax_code_id' => TaxCode::query()->where('code', $code === 'SEAT' ? 'CHILD_SEAT' : 'GPS')->value('id'),
        'is_active' => $active,
    ]);
}

it('ajoute les options par jour, par location, avec plafond et quantité', function () {
    $gps = quoteOption('GPS', 'per_day', '10.000', '50.000');
    $seat = quoteOption('SEAT', 'per_rental', '15.000');

    // 10 jours : GPS 10 × 10 = 100, plafonné à 50 ; deux sièges à 15 = 30.
    $quote = quoteFor('2027-09-10 09:00', '2027-09-20 09:00', [$gps->id => 1, $seat->id => 2]);

    expect((string) $quote->optionsAmount)->toBe('80.000')
        ->and((string) $quote->totalExcludingTax)->toBe('680.000')
        ->and(array_column($quote->options, 'tax_code'))->toBe(['GPS', 'CHILD_SEAT']);
});

it('refuse une option inactive, sans prix ou en quantité invalide', function () {
    $inactive = quoteOption('GPS', 'per_day', '10.000', active: false);

    expect(fn () => quoteFor('2027-09-10 09:00', '2027-09-12 09:00', [$inactive->id => 1]))->toThrow(QuoteUnavailable::class, 'pas proposée');

    $inactive->forceFill(['is_active' => true])->save();
    expect(fn () => quoteFor('2027-09-10 09:00', '2027-09-12 09:00', [$inactive->id => 0]))->toThrow(QuoteUnavailable::class, 'Quantité');
});

// --- Remise (D36) ------------------------------------------------------------------------

it('refuse toute remise avec le seuil par défaut de 0 % (D36)', function () {
    expect(fn () => quoteFor('2027-09-10 09:00', '2027-09-12 09:00', discount: '1.000'))
        ->toThrow(QuoteUnavailable::class, 'seuil de 0 %');
});

it('accepte une remise sous le seuil, la refuse au-delà ou au-delà du montant', function () {
    $settings = app(ApprovalSettings::class);
    $settings->discount_threshold_percent = '10.00';
    $settings->save();

    // 2 jours à 80 = 160 ; 10 % = 16.
    $quote = quoteFor('2027-09-10 09:00', '2027-09-12 09:00', discount: '16.000');
    expect((string) $quote->totalExcludingTax)->toBe('144.000');

    expect(fn () => quoteFor('2027-09-10 09:00', '2027-09-12 09:00', discount: '16.001'))->toThrow(QuoteUnavailable::class, 'seuil de 10 %')
        ->and(fn () => quoteFor('2027-09-10 09:00', '2027-09-12 09:00', discount: '-1.000'))->toThrow(QuoteUnavailable::class);
});

// --- Taxes ---------------------------------------------------------------------------------

it('donne un devis hors taxes, avec la mention, sans règle fiscale applicable', function () {
    $quote = quoteFor('2027-09-10 09:00', '2027-09-12 09:00');

    expect($quote->taxes)->toBeNull()
        ->and($quote->taxNotice)->toContain('règle fiscale non validée');
});

it('calcule TVA, TTC et timbre du référentiel validé, remise répartie au prorata', function () {
    $settings = app(ApprovalSettings::class);
    $settings->discount_threshold_percent = '10.00';
    $settings->save();
    $gps = quoteOption('GPS', 'per_rental', '40.000');

    // 160 + 40 = 200 HT, remise 20 : 180 HT ; TVA 19 % = 34,200 ; TTC 214,200 ; timbre 1 ; net 215,200.
    $quote = quoteFor('2027-09-10 09:00', '2027-09-12 09:00', [$gps->id => 1], '20.000');

    expect($quote->taxNotice)->toBeNull()
        ->and((string) $quote->taxes?->totalExcludingTax)->toBe('180.000')
        ->and((string) $quote->taxes?->totalVat)->toBe('34.200')
        ->and((string) $quote->taxes?->totalIncludingTax)->toBe('214.200')
        ->and((string) $quote->taxes?->netToPay)->toBe('215.200');
});

it('fige le devis en JSON sans aucun nombre flottant', function () {
    $json = json_encode(quoteFor('2027-08-29 10:00', '2027-09-03 10:00')->toArray(), JSON_THROW_ON_ERROR);
    $data = json_decode($json, true);

    expect($data['rental_amount'])->toBe('470.000')
        ->and($data['billable_days'])->toBe(5)
        ->and($data['days'][0])->toBe(['date' => '2027-08-29', 'season' => 'Haute 2027', 'daily_amount' => '110.000'])
        ->and($json)->not->toMatch('/:\s*\d+\.\d+[,}]/');
});
