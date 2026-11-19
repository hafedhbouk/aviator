<?php

use App\Domain\Tax\Actions\AddStampDutyRuleVersion;
use App\Domain\Tax\Actions\AddTaxRuleVersion;
use App\Domain\Tax\Actions\SetFiscalRuleActive;
use App\Domain\Tax\Exceptions\FiscalSetupException;
use App\Domain\Tax\Exceptions\TaxRuleUnavailable;
use App\Domain\Tax\FiscalGuard;
use App\Domain\Tax\StampDutyResolver;
use App\Domain\Tax\TaxCalculator;
use App\Domain\Tax\TaxLine;
use App\Domain\Tax\TaxRuleResolver;
use App\Domain\TaxReporting\FiscalConfigurationReport;
use App\Domain\TaxReporting\FiscalReportSpreadsheet;
use App\Enums\FiscalValidationStatus;
use App\Enums\TaxNature;
use App\Filament\Pages\FiscalConfigurationReportPage;
use App\Filament\Resources\StampDutyRules\StampDutyRuleResource;
use App\Filament\Resources\TaxCodes\Pages\ViewTaxCode;
use App\Filament\Resources\TaxCodes\RelationManagers\RulesRelationManager;
use App\Filament\Resources\TaxCodes\TaxCodeResource;
use App\Models\Agency;
use App\Models\Company;
use App\Models\FiscalReference;
use App\Models\StampDutyRule;
use App\Models\TaxCode;
use App\Models\TaxRule;
use App\Settings\BillingSettings;
use App\Support\Authorization\Role;
use App\Support\Environment\CheckStatus;
use App\Support\Environment\EnvironmentChecker;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\FiscalReferenceSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(FiscalReferenceSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->superAdmin = withTwoFactor(userWithProfile(Role::SuperAdmin));
    $this->admin = withTwoFactor(userWithProfile(Role::Admin));
    $this->agent = userWithProfile(Role::AgencyUser, ['agency_id' => Agency::factory()->create()->id]);
});

function on(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date, 'Africa/Tunis');
}

function line(string $code, string $amount): TaxLine
{
    return new TaxLine($code, Money::of($amount));
}

function taxCode(string $code): TaxCode
{
    return TaxCode::query()->where('code', $code)->sole();
}

// --- Valeurs initiales (référentiel v1.0) --------------------------------------------

it('enregistre la référence v1.0 avec l\'empreinte du fichier validé', function () {
    $reference = FiscalReference::query()->sole();
    $file = base_path('docs/reference/regles_fiscales_validees/'.FiscalReferenceSeeder::FILE_NAME);

    expect($reference->version)->toBe('1.0')
        ->and($reference->sha256)->toBe('43b2de817cb6590f2e90875afbff9c29c795f50599073fe3ecd7cbb98158e887')
        ->and(hash_file('sha256', $file))->toBe($reference->sha256)
        ->and($reference->validated_at->toDateString())->toBe('2026-11-18');
});

it('crée les 15 codes : 6 CONFIRMED à 19 %, 9 CONDITIONAL', function () {
    $rules = TaxRule::query()->with('taxCode')->get();
    $confirmed = $rules->filter(fn (TaxRule $r) => $r->validation_status === FiscalValidationStatus::Confirmed);

    expect(TaxCode::query()->count())->toBe(15)
        ->and($rules)->toHaveCount(15)
        ->and($confirmed->map(fn (TaxRule $r) => $r->taxCode->code)->sort()->values()->all())
        ->toBe(['CHILD_SEAT', 'EXTRA_DRIVER', 'EXTRA_RENTAL_PERIOD', 'FUEL_RECHARGE', 'GPS', 'RENTAL'])
        ->and($confirmed->pluck('rate')->unique()->values()->all())->toBe(['19.0000'])
        ->and($rules->filter(fn (TaxRule $r) => $r->validation_status === FiscalValidationStatus::Conditional))->toHaveCount(9)
        ->and($rules->every(fn (TaxRule $r) => $r->fiscal_reference_id !== null && $r->is_active))->toBeTrue();
});

it('qualifie les codes selon le référentiel : nature, taux, justificatif', function (string $code, TaxNature $nature, ?string $rate, bool $justification) {
    $taxCode = taxCode($code);

    expect($taxCode->nature)->toBe($nature)
        ->and($taxCode->requires_justification)->toBe($justification)
        ->and($taxCode->rules()->sole()->rate)->toBe($rate);
})->with([
    ['INSURANCE_PREMIUM', TaxNature::Exempt, null, true],
    ['LATE_RETURN_PENALTY', TaxNature::OutOfScope, null, true],
    ['VEHICLE_DAMAGE_COMPENSATION', TaxNature::OutOfScope, null, true],
    ['INSURANCE_EXCESS_COMPENSATION', TaxNature::OutOfScope, null, true],
    ['EXCESS_REDUCTION_OPTION', TaxNature::Taxable, '19.0000', false],
    ['REPAIR_SERVICE', TaxNature::Taxable, '19.0000', false],
]);

it('crée le timbre de 1,000 DT et les exonérations de l\'article 118 inactives, sans tarif « grandes surfaces »', function () {
    $standard = StampDutyRule::query()->where('rule_code', StampDutyRule::STANDARD)->sole();
    $exemptions = StampDutyRule::query()->where('rule_code', '!=', StampDutyRule::STANDARD)->get();

    expect($standard->amount->toDecimalString())->toBe('1.000')
        ->and($standard->validation_status)->toBe(FiscalValidationStatus::Confirmed)
        ->and($standard->is_active)->toBeTrue()
        ->and($exemptions)->toHaveCount(3)
        ->and($exemptions->every(fn (StampDutyRule $r) => ! $r->is_active && $r->validation_status === FiscalValidationStatus::PendingDocumentation))->toBeTrue()
        ->and(StampDutyRule::query()->where('description', 'like', '%surface%')->orWhere('rule_code', 'like', '%SURFACE%')->exists())->toBeFalse();
});

it('est idempotent et ne modifie jamais une règle existante', function () {
    $rule = TaxRule::query()->whereHas('taxCode', fn ($q) => $q->where('code', 'RENTAL'))->sole();
    app(SetFiscalRuleActive::class)->handle($rule, false, $this->superAdmin);

    $this->seed(FiscalReferenceSeeder::class);

    expect(TaxCode::query()->count())->toBe(15)
        ->and(TaxRule::query()->count())->toBe(15)
        ->and(StampDutyRule::query()->count())->toBe(4)
        ->and(FiscalReference::query()->count())->toBe(1)
        ->and($rule->fresh()?->is_active)->toBeFalse();
});

// --- Calcul (référentiel §5) -----------------------------------------------------------

it('reproduit l\'exemple chiffré du référentiel : 115,000 HT, 21,850 TVA, 136,850 TTC, 1,000 timbre, 137,850 net', function () {
    $result = app(TaxCalculator::class)->calculate([
        line('RENTAL', '100.000'), line('GPS', '10.000'), line('CHILD_SEAT', '5.000'),
    ], on('2026-12-01'));

    expect($result->totalExcludingTax->toDecimalString())->toBe('115.000')
        ->and($result->totalVat->toDecimalString())->toBe('21.850')
        ->and($result->totalIncludingTax->toDecimalString())->toBe('136.850')
        ->and($result->stampDuty->toDecimalString())->toBe('1.000')
        ->and($result->netToPay->toDecimalString())->toBe('137.850')
        ->and($result->bases)->toHaveCount(1)
        ->and($result->bases[0]['rate'])->toBe('19');
});

it('calcule la TVA sur la base agrégée par taux, pas ligne par ligne', function () {
    // Ligne par ligne : 3 × arrondi(0,0285) = 3 × 0,029 = 0,087 ; sur la base agrégée : 0,450 × 19 % = 0,0855 → 0,086.
    $result = app(TaxCalculator::class)->calculate([
        line('RENTAL', '0.150'), line('GPS', '0.150'), line('CHILD_SEAT', '0.150'),
    ], on('2026-12-01'));

    expect($result->totalVat->toDecimalString())->toBe('0.086');
});

it('arrondit selon le réglage billing.vat_rounding_mode (Q86)', function (string $mode, string $vat) {
    $settings = app(BillingSettings::class);
    $settings->vat_rounding_mode = $mode;
    $settings->save();

    $result = app(TaxCalculator::class)->calculate([line('RENTAL', '0.150')], on('2026-12-01'), withStampDuty: false);

    expect($result->totalVat->toDecimalString())->toBe($vat);
})->with([
    'demi supérieur (défaut)' => ['half_up', '0.029'],
    'demi pair' => ['half_even', '0.028'],
]);

it('isole les lignes hors champ dans une base sans TVA, avec le timbre', function () {
    $result = app(TaxCalculator::class)->calculate([
        line('RENTAL', '100.000'), line('VEHICLE_DAMAGE_COMPENSATION', '300.000'),
    ], on('2026-12-01'));

    expect($result->totalExcludingTax->toDecimalString())->toBe('400.000')
        ->and($result->totalVat->toDecimalString())->toBe('19.000')
        ->and($result->netToPay->toDecimalString())->toBe('420.000')
        ->and(collect($result->bases)->firstWhere('nature', 'out_of_scope')['vat']->toDecimalString())->toBe('0.000');
});

// --- Aucun libellé : « assurance » --------------------------------------------------------

it('n\'accorde jamais d\'exonération à partir d\'un libellé contenant « assurance »', function (string $label) {
    expect(fn () => app(TaxRuleResolver::class)->resolve($label, on('2026-12-01')))
        ->toThrow(TaxRuleUnavailable::class, 'Code fiscal inconnu');
})->with(['Assurance', 'assurance tous risques', 'ASSURANCE', 'Prime d\'assurance véritable', 'Assûrance']);

// --- Statuts et versions --------------------------------------------------------------------

it('ne renvoie jamais une règle PENDING_DOCUMENTATION ni une règle inactive', function () {
    $rule = TaxRule::query()->whereHas('taxCode', fn ($q) => $q->where('code', 'GPS'))->sole();

    app(AddTaxRuleVersion::class)->handle(taxCode('GPS'), [
        'rate' => '19', 'valid_from' => '2027-01-01', 'validation_status' => 'PENDING_DOCUMENTATION',
    ], $this->superAdmin);

    expect(fn () => app(TaxRuleResolver::class)->resolve('GPS', on('2027-02-01')))->toThrow(TaxRuleUnavailable::class, 'PENDING_DOCUMENTATION')
        ->and(app(TaxRuleResolver::class)->resolve('GPS', on('2026-12-31'))->id)->toBe($rule->id);

    app(SetFiscalRuleActive::class)->handle($rule, false, $this->superAdmin);

    expect(fn () => app(TaxRuleResolver::class)->resolve('GPS', on('2026-12-31')))->toThrow(TaxRuleUnavailable::class, 'Aucune règle');
});

it('refuse d\'activer une règle PENDING_DOCUMENTATION', function () {
    $exemption = StampDutyRule::query()->where('rule_code', 'EXEMPT_ART118_NOT_PAID')->sole();

    expect(fn () => app(SetFiscalRuleActive::class)->handle($exemption, true, $this->superAdmin))
        ->toThrow(FiscalSetupException::class, 'PENDING_DOCUMENTATION');
    expect($exemption->fresh()?->is_active)->toBeFalse();
});

it('crée une version datée, clôt la précédente à la veille et retrouve l\'ancienne à une date antérieure', function () {
    $this->actingAs($this->superAdmin);

    app(AddTaxRuleVersion::class)->handle(taxCode('RENTAL'), [
        'rate' => '13', 'valid_from' => '2027-03-01', 'validation_status' => 'CONFIRMED',
        'fiscal_reference_id' => FiscalReference::query()->value('id'),
    ], $this->superAdmin);

    $resolver = app(TaxRuleResolver::class);
    $old = TaxRule::query()->whereHas('taxCode', fn ($q) => $q->where('code', 'RENTAL'))->orderBy('valid_from')->first();

    expect($old?->valid_to?->toDateString())->toBe('2027-02-28')
        ->and($old?->rate)->toBe('19.0000')
        ->and($resolver->resolve('RENTAL', on('2027-02-28'))->rate)->toBe('19.0000')
        ->and($resolver->resolve('RENTAL', on('2027-03-01'))->rate)->toBe('13.0000')
        ->and(Activity::query()->where('event', 'tax_rule_version_added')->sole()->properties['old']['rate'])->toBe('19.0000');
});

it('refuse une version qui chevauche ou précède la dernière', function (string $date) {
    expect(fn () => app(AddTaxRuleVersion::class)->handle(taxCode('RENTAL'), [
        'rate' => '18', 'valid_from' => $date, 'validation_status' => 'CONDITIONAL',
    ], $this->superAdmin))->toThrow(FiscalSetupException::class, 'commencer après');
})->with(['2026-11-18', '2026-01-01']);

it('contrôle le taux selon la nature et la référence d\'une règle CONFIRMED', function () {
    $add = fn (string $code, array $data) => app(AddTaxRuleVersion::class)->handle(taxCode($code), $data + ['valid_from' => '2027-01-01'], $this->superAdmin);

    expect(fn () => $add('INSURANCE_PREMIUM', ['rate' => '19', 'validation_status' => 'CONDITIONAL']))->toThrow(FiscalSetupException::class, 'exonéré ou hors champ')
        ->and(fn () => $add('RENTAL', ['rate' => '', 'validation_status' => 'CONDITIONAL']))->toThrow(FiscalSetupException::class, 'exige un taux')
        ->and(fn () => $add('RENTAL', ['rate' => '19,12345', 'validation_status' => 'CONDITIONAL']))->toThrow(FiscalSetupException::class, 'exige un taux')
        ->and(fn () => $add('RENTAL', ['rate' => '19', 'validation_status' => 'CONFIRMED']))->toThrow(FiscalSetupException::class, 'référence');
});

it('interdit toute modification sur place d\'une règle, d\'un code ou de la référence', function () {
    $rule = TaxRule::query()->first();

    expect(fn () => $rule?->update(['rate' => '7.0000']))->toThrow(LogicException::class)
        ->and(fn () => TaxCode::query()->first()?->update(['label' => 'Assurance']))->toThrow(LogicException::class)
        ->and(fn () => FiscalReference::query()->first()?->update(['sha256' => str_repeat('0', 64)]))->toThrow(LogicException::class)
        ->and(fn () => $rule?->delete())->toThrow(LogicException::class);
});

it('ajoute une version du timbre et applique le montant en vigueur', function () {
    app(AddStampDutyRuleVersion::class)->handle(StampDutyRule::STANDARD, [
        'amount' => '1,500', 'effective_from' => '2027-01-01', 'validation_status' => 'CONFIRMED',
        'fiscal_reference_id' => FiscalReference::query()->value('id'),
    ], $this->superAdmin);

    expect(app(StampDutyResolver::class)->standardAmount(on('2026-12-31'))->toDecimalString())->toBe('1.000')
        ->and(app(StampDutyResolver::class)->standardAmount(on('2027-01-01'))->toDecimalString())->toBe('1.500');
});

// --- Garde-fou (aviator:check-environment) ---------------------------------------------------

it('signale chaque cas bloquant du garde-fou, codes seulement', function () {
    expect(app(FiscalGuard::class)->problems())->toBe(['mentions obligatoires des factures vides (fiche société)']);

    Company::query()->forceCreate(['id' => Company::SINGLETON_ID, 'legal_name' => 'Aviator', 'invoice_legal_mentions' => 'Mentions']);

    expect(app(FiscalGuard::class)->problems())->toBe([]);

    TaxRule::query()->whereHas('taxCode', fn ($q) => $q->where('code', 'GPS'))->toBase()->update(['validation_status' => 'PENDING_DOCUMENTATION']);
    TaxRule::query()->whereHas('taxCode', fn ($q) => $q->where('code', 'RENTAL'))->toBase()->update(['fiscal_reference_id' => null]);

    expect(app(FiscalGuard::class)->problems())->toBe([
        'règle(s) active(s) en attente de pièce (PENDING_DOCUMENTATION) : GPS',
        'règle(s) CONFIRMED sans référence validée : RENTAL',
    ]);
});

it('passe en ERREUR en production et en AVERTISSEMENT ailleurs', function (string $env, CheckStatus $expected) {
    app()->detectEnvironment(fn (): string => $env);

    $result = collect(app(EnvironmentChecker::class)->run())->firstWhere('check', 'Règles fiscales');

    expect($result?->status)->toBe($expected)
        ->and($result?->finding)->toContain('Mentions obligatoires des factures vides');
})->with([
    ['production', CheckStatus::Error],
    ['local', CheckStatus::Warning],
]);

// --- Rapport de configuration --------------------------------------------------------------------

it('produit le rapport : référence, règles, timbre et exemple calculé en direct', function () {
    $report = app(FiscalConfigurationReport::class)->build(on('2026-12-01'));

    expect($report['references'][0]['sha256'])->toBe(FiscalReferenceSeeder::SHA256)
        ->and($report['rules'])->toHaveCount(15)
        ->and($report['stamps'])->toHaveCount(4)
        ->and(collect($report['example']['totals'])->pluck('amount', 'label')->all())->toBe([
            'Total HT' => '115,000 DT', 'TVA 19 %' => '21,850 DT', 'Total TTC' => '136,850 DT',
            'Timbre fiscal' => '1,000 DT', 'Net à payer' => '137,850 DT',
        ]);
});

it('exporte le rapport en XLSX lisible', function () {
    $path = tempnam(sys_get_temp_dir(), 'test-fiscal-');
    app(FiscalReportSpreadsheet::class)->write(app(FiscalConfigurationReport::class)->build(on('2026-12-01')), (string) $path);

    $reader = new Reader;
    $reader->open((string) $path);
    $sheets = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        $rows = [];
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
        $sheets[$sheet->getName()] = $rows;
    }

    $reader->close();
    unlink((string) $path);

    expect(array_keys($sheets))->toBe(['Règles de TVA', 'Timbre', 'Référentiel', 'Exemple §5'])
        ->and($sheets['Règles de TVA'])->toHaveCount(16)
        ->and($sheets['Référentiel'][1][3])->toBe(FiscalReferenceSeeder::SHA256)
        ->and(end($sheets['Exemple §5']))->toBe(['Net à payer', '', '137,850 DT']);
});

it('ouvre le rapport et sa version imprimable au super admin et à l\'admin, pas à l\'agence', function () {
    foreach ([$this->superAdmin, $this->admin] as $user) {
        $this->actingAs($user)->get(FiscalConfigurationReportPage::getUrl())->assertOk()->assertSee('137,850 DT');
        $this->actingAs($user)->get(route('filament.admin.fiscal-report.print'))->assertOk()
            ->assertSee(FiscalReferenceSeeder::SHA256)->assertSee('Signature');
    }

    $this->actingAs($this->agent)->get(FiscalConfigurationReportPage::getUrl())->assertForbidden();
    $this->actingAs($this->agent)->get(route('filament.admin.fiscal-report.print'))->assertForbidden();
});

it('exige la 2FA pour la version imprimable', function () {
    $this->actingAs(userWithProfile(Role::Admin))
        ->get(route('filament.admin.fiscal-report.print'))
        ->assertRedirect();
});

// --- Écrans et droits -------------------------------------------------------------------------------

it('laisse l\'admin lire les règles fiscales sans pouvoir les modifier', function () {
    $this->actingAs($this->admin);
    $gps = taxCode('GPS');
    $rule = $gps->rules()->sole();

    $this->get(TaxCodeResource::getUrl())->assertOk();
    $this->get(StampDutyRuleResource::getUrl())->assertOk();

    Livewire::test(RulesRelationManager::class, ['ownerRecord' => $gps, 'pageClass' => ViewTaxCode::class])
        ->assertCanSeeTableRecords([$rule])
        ->assertActionHidden(TestAction::make('addVersion')->table())
        ->assertActionHidden(TestAction::make('toggleActive')->table($rule));

    expect(fn () => app(AddTaxRuleVersion::class)->handle($gps, ['rate' => '19', 'valid_from' => '2027-01-01', 'validation_status' => 'CONDITIONAL'], $this->admin))
        ->toThrow(FiscalSetupException::class, 'super admin')
        ->and(fn () => app(SetFiscalRuleActive::class)->handle($rule, false, $this->admin))
        ->toThrow(FiscalSetupException::class, 'super admin');
});

it('laisse le super admin ajouter une version depuis l\'écran, journalisée', function () {
    $this->actingAs($this->superAdmin);
    $gps = taxCode('GPS');

    Livewire::test(RulesRelationManager::class, ['ownerRecord' => $gps, 'pageClass' => ViewTaxCode::class])
        ->callAction(TestAction::make('addVersion')->table(), [
            'rate' => '19', 'valid_from' => '2027-06-01', 'validation_status' => 'CONDITIONAL',
        ])
        ->assertNotified('Nouvelle version enregistrée');

    expect($gps->rules()->count())->toBe(2)
        ->and(Activity::query()->where('event', 'tax_rule_version_added')->sole()->causer?->is($this->superAdmin))->toBeTrue();
});

it('refuse les écrans fiscaux à l\'utilisateur d\'agence', function () {
    $this->actingAs($this->agent);

    $this->get(TaxCodeResource::getUrl())->assertForbidden();
    $this->get(StampDutyRuleResource::getUrl())->assertForbidden();
});
