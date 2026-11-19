<?php

use App\Filament\Pages\CompanyProfile;
use App\Filament\Pages\FiscalConfigurationReportPage;
use App\Filament\Pages\Settings\ReservationSettingsPage;
use App\Filament\Resources\Agencies\AgencyResource;
use App\Filament\Resources\Options\OptionResource;
use App\Filament\Resources\PaymentMethods\PaymentMethodResource;
use App\Filament\Resources\RatePlans\RatePlanResource;
use App\Filament\Resources\Seasons\SeasonResource;
use App\Filament\Resources\StampDutyRules\StampDutyRuleResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Resources\TaxCodes\TaxCodeResource;
use App\Filament\Resources\VehicleCategories\VehicleCategoryResource;
use App\Models\Agency;
use App\Models\AgencyLocation;
use App\Models\Company;
use App\Models\Option;
use App\Models\PaymentMethod;
use App\Models\RatePlan;
use App\Models\Season;
use App\Models\StampDutyRule;
use App\Models\Supplier;
use App\Models\TaxCode;
use App\Models\TaxRule;
use App\Models\VehicleCategory;
use App\Support\Audit\AuditsChanges;
use App\Support\Authorization\Role;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

/*
 * Contrôles transverses de la phase 3 (lot L10) : matrice des droits du plan (§2) par
 * profil, policies sans suppression, journal des modifications, recherche globale
 * fermée, messages en français.
 */

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/**
 * Page d'accueil de chaque écran du paramétrage => statut attendu pour
 * [super admin, admin, utilisateur d'agence] (plan de la phase 3, §2).
 */
dataset('setup screens', [
    'société' => [fn () => CompanyProfile::getUrl(), [200, 200, 403]],
    'agences et lieux' => [fn () => AgencyResource::getUrl(), [200, 200, 200]],
    'catégories' => [fn () => VehicleCategoryResource::getUrl(), [200, 200, 200]],
    'saisons' => [fn () => SeasonResource::getUrl(), [200, 200, 403]],
    'grilles' => [fn () => RatePlanResource::getUrl(), [200, 200, 403]],
    'options' => [fn () => OptionResource::getUrl(), [200, 200, 200]],
    'modes de paiement' => [fn () => PaymentMethodResource::getUrl(), [200, 200, 200]],
    'fournisseurs' => [fn () => SupplierResource::getUrl(), [200, 200, 403]],
    'codes fiscaux' => [fn () => TaxCodeResource::getUrl(), [200, 200, 403]],
    'timbre' => [fn () => StampDutyRuleResource::getUrl(), [200, 200, 403]],
    'rapport fiscal' => [fn () => FiscalConfigurationReportPage::getUrl(), [200, 200, 403]],
    'réglages' => [fn () => ReservationSettingsPage::getUrl(), [200, 403, 403]],
]);

it('applique la matrice des droits du plan à chaque écran', function (Closure $url, array $expected) {
    $users = [
        withTwoFactor(userWithProfile(Role::SuperAdmin)),
        withTwoFactor(userWithProfile(Role::Admin)),
        userWithProfile(Role::AgencyUser, ['agency_id' => Agency::factory()->create()->id]),
    ];

    foreach ($users as $index => $user) {
        $this->actingAs($user)->get($url())->assertStatus($expected[$index]);
    }
})->with('setup screens');

it('ne laisse écrire le paramétrage qu\'au super admin et à l\'admin, la fiscalité qu\'au super admin', function () {
    $superAdmin = userWithProfile(Role::SuperAdmin);
    $admin = userWithProfile(Role::Admin);
    $agent = userWithProfile(Role::AgencyUser, ['agency_id' => Agency::factory()->create()->id]);

    foreach ([VehicleCategory::class, Season::class, RatePlan::class, Option::class, PaymentMethod::class, Supplier::class, AgencyLocation::class, Agency::class] as $model) {
        expect(Gate::forUser($superAdmin)->allows('create', $model))->toBeTrue($model)
            ->and(Gate::forUser($admin)->allows('create', $model))->toBeTrue($model)
            ->and(Gate::forUser($agent)->allows('create', $model))->toBeFalse($model);
    }

    foreach ([TaxRule::class, StampDutyRule::class] as $model) {
        expect(Gate::forUser($superAdmin)->allows('create', $model))->toBeTrue($model)
            ->and(Gate::forUser($admin)->allows('create', $model))->toBeFalse($model)
            ->and(Gate::forUser($admin)->allows('viewAny', $model))->toBeTrue($model)
            ->and(Gate::forUser($agent)->allows('viewAny', $model))->toBeFalse($model);
    }

    expect(Gate::forUser($superAdmin)->allows('create', TaxCode::class))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('updateLegalMentions', Company::class))->toBeFalse()
        ->and(Gate::forUser($superAdmin)->allows('updateLegalMentions', Company::class))->toBeTrue();
});

it('n\'autorise aucune suppression du paramétrage (sauf une saison inutilisée)', function () {
    $superAdmin = userWithProfile(Role::SuperAdmin);

    foreach ([
        Agency::factory()->create(), AgencyLocation::factory()->create(), VehicleCategory::factory()->create(),
    ] as $record) {
        expect(Gate::forUser($superAdmin)->allows('delete', $record))->toBeFalse($record::class);
    }

    foreach ([Option::class, PaymentMethod::class, Supplier::class, RatePlan::class, TaxRule::class, StampDutyRule::class, TaxCode::class] as $model) {
        expect(Gate::forUser($superAdmin)->allows('deleteAny', $model))->toBeFalse($model);
    }
});

it('journalise les modifications de tous les modèles modifiables du paramétrage', function () {
    foreach ([
        Company::class, Agency::class, AgencyLocation::class, VehicleCategory::class, Season::class, RatePlan::class,
        Option::class, PaymentMethod::class, Supplier::class, TaxRule::class, StampDutyRule::class,
    ] as $model) {
        expect(in_array(AuditsChanges::class, class_uses_recursive($model), true))->toBeTrue("Journal manquant : {$model}");
    }
});

it('ferme la recherche globale sur tous les écrans du paramétrage', function () {
    foreach ([
        AgencyResource::class, VehicleCategoryResource::class, SeasonResource::class, RatePlanResource::class, OptionResource::class,
        PaymentMethodResource::class, SupplierResource::class, TaxCodeResource::class, StampDutyRuleResource::class,
    ] as $resource) {
        expect($resource::canGloballySearch())->toBeFalse($resource);
    }
});

it('affiche les messages de validation en français', function () {
    app()->setLocale('fr');

    expect(__('validation.required', ['attribute' => 'nom']))->toBe('Le champ nom est obligatoire.')
        ->and(__('validation.max.string', ['attribute' => 'nom', 'max' => 100]))->toBe('Le champ nom ne doit pas dépasser 100 caractères.');
});

it('traduit toutes les clés de validation, d\'authentification et de mot de passe du framework', function (string $file) {
    $english = require base_path("vendor/laravel/framework/src/Illuminate/Translation/lang/en/{$file}.php");
    $french = require lang_path("fr/{$file}.php");

    $keys = fn (array $lines): array => collect(Arr::dot($lines))
        ->keys()->reject(fn (string $key): bool => preg_match('/^(custom|attributes)(\.|$)/', $key) === 1)
        ->sort()->values()->all();

    expect(array_values(array_diff($keys($english), $keys($french))))->toBe([]);
})->with(['validation', 'auth', 'passwords', 'pagination']);
