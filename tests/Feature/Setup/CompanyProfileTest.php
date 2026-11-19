<?php

use App\Domain\Setup\Actions\SaveCompanyProfile;
use App\Domain\Setup\Exceptions\SetupException;
use App\Filament\Pages\CompanyProfile;
use App\Models\Company;
use App\Support\Authorization\Role;
use App\Support\Files\PublicFiles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/** Image PNG de 1 × 1 pixel. */
const COMPANY_TEST_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

function companyData(array $overrides = []): array
{
    return array_merge([
        'legal_name' => 'Aviator SARL',
        'trade_name' => 'Aviator',
        'tax_id' => '1234567/a/m/000',
        'trade_register' => 'B0123456789',
        'address' => 'Route de l\'aéroport',
        'city' => 'Djerba',
        'phone' => '+216 75 000 000',
        'email' => 'Contact@Aviator.example',
    ], $overrides);
}

function storeCompanyFile(string $path, string $content): string
{
    Storage::disk(PublicFiles::DISK)->put($path, $content);

    return $path;
}

beforeEach(function () {
    Storage::fake(PublicFiles::DISK);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->superAdmin = withTwoFactor(userWithProfile(Role::SuperAdmin));
    $this->admin = withTwoFactor(userWithProfile(Role::Admin));
});

// --- Droits (plan de la phase 3, §2) -------------------------------------------

it('ouvre la fiche société au super admin et à l\'admin du siège', function (string $who) {
    $this->actingAs($this->{$who})
        ->get(CompanyProfile::getUrl())
        ->assertOk()
        ->assertSee('Fiche société');
})->with(['superAdmin', 'admin']);

it('refuse la fiche société à l\'utilisateur d\'agence, page comme action', function () {
    $agent = userWithProfile(Role::AgencyUser);

    $this->actingAs($agent)->get(CompanyProfile::getUrl())->assertForbidden();

    expect(fn () => app(SaveCompanyProfile::class)->handle(companyData(), $agent))
        ->toThrow(SetupException::class);
    expect(Company::query()->count())->toBe(0);
});

// --- Enregistrement --------------------------------------------------------------

it('crée la fiche unique au premier enregistrement, en TND, depuis la page', function () {
    $this->actingAs($this->admin);

    Livewire::test(CompanyProfile::class)
        ->fillForm(companyData())
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Fiche société enregistrée');

    $company = Company::query()->sole();

    expect($company->id)->toBe(Company::SINGLETON_ID)
        ->and($company->legal_name)->toBe('Aviator SARL')
        ->and($company->tax_id)->toBe('1234567/A/M/000')
        ->and($company->email)->toBe('contact@aviator.example')
        ->and($company->currency)->toBe('TND');
});

it('modifie la même fiche sans en créer une seconde, et préremplit le formulaire', function () {
    app(SaveCompanyProfile::class)->handle(companyData(), $this->superAdmin);

    $this->actingAs($this->admin);

    Livewire::test(CompanyProfile::class)
        ->assertSchemaStateSet(['legal_name' => 'Aviator SARL', 'city' => 'Djerba'])
        ->fillForm(['city' => 'Houmt Souk'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Company::query()->count())->toBe(1)
        ->and(Company::current()?->city)->toBe('Houmt Souk');
});

it('exige la raison sociale, dans le formulaire comme dans l\'action', function () {
    $this->actingAs($this->admin);

    Livewire::test(CompanyProfile::class)
        ->fillForm(companyData(['legal_name' => '']))
        ->call('save')
        ->assertHasFormErrors(['legal_name' => 'required']);

    expect(fn () => app(SaveCompanyProfile::class)->handle(companyData(['legal_name' => '  ']), $this->admin))
        ->toThrow(ValidationException::class);
    expect(Company::query()->count())->toBe(0);
});

it('refuse toute devise autre que le dinar tunisien (D26)', function () {
    expect(fn () => app(SaveCompanyProfile::class)->handle(companyData(['currency' => 'EUR']), $this->superAdmin))
        ->toThrow(SetupException::class, 'TND');
    expect(Company::query()->count())->toBe(0);
});

it('journalise la création et la modification, avec avant et après (J-05)', function () {
    $this->actingAs($this->admin);
    app(SaveCompanyProfile::class)->handle(companyData(), $this->admin);
    app(SaveCompanyProfile::class)->handle(companyData(['legal_name' => 'Aviator Location SARL']), $this->admin);

    $updated = Activity::query()->where('log_name', 'audit')->where('event', 'updated')
        ->where('subject_type', Company::class)->sole();

    expect(Activity::query()->where('log_name', 'audit')->where('event', 'created')->where('subject_type', Company::class)->count())->toBe(1)
        ->and($updated->causer?->is($this->admin))->toBeTrue()
        ->and($updated->properties['old']['legal_name'])->toBe('Aviator SARL')
        ->and($updated->properties['attributes']['legal_name'])->toBe('Aviator Location SARL');
});

// --- Mentions des factures : paramètre fiscal, super admin uniquement -------------

it('laisse le super admin saisir les mentions des factures', function () {
    $this->actingAs($this->superAdmin);

    Livewire::test(CompanyProfile::class)
        ->assertFormFieldEnabled('invoice_legal_mentions')
        ->fillForm(companyData(['invoice_legal_mentions' => 'Mention validée par le cabinet']))
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Company::current()?->invoice_legal_mentions)->toBe('Mention validée par le cabinet');
});

it('montre les mentions à l\'admin en lecture seule et les conserve quand il enregistre', function () {
    app(SaveCompanyProfile::class)->handle(companyData(['invoice_legal_mentions' => 'Mention validée']), $this->superAdmin);

    $this->actingAs($this->admin);

    Livewire::test(CompanyProfile::class)
        ->assertFormFieldDisabled('invoice_legal_mentions')
        ->fillForm(['city' => 'Midoun'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Company::current()?->invoice_legal_mentions)->toBe('Mention validée')
        ->and(Company::current()?->city)->toBe('Midoun');
});

it('refuse à l\'admin de modifier les mentions, même en contournant le formulaire', function () {
    app(SaveCompanyProfile::class)->handle(companyData(['invoice_legal_mentions' => 'Mention validée']), $this->superAdmin);

    expect(fn () => app(SaveCompanyProfile::class)->handle(
        companyData(['invoice_legal_mentions' => 'Mention inventée']),
        $this->admin,
    ))->toThrow(SetupException::class, 'super admin');

    expect(Company::current()?->invoice_legal_mentions)->toBe('Mention validée');
});

// --- Logo (D21) ------------------------------------------------------------------

it('dépose le logo sur le disque privé, dans le dossier des fichiers publics', function () {
    $this->actingAs($this->admin);

    Livewire::test(CompanyProfile::class)
        ->fillForm(companyData(['logo_path' => UploadedFile::fake()->createWithContent('logo.png', base64_decode(COMPANY_TEST_PNG))]))
        ->call('save')
        ->assertHasNoFormErrors();

    $path = (string) Company::current()?->logo_path;

    expect($path)->toStartWith(PublicFiles::COMPANY_DIRECTORY.'/')
        ->and($path)->toEndWith('.png')
        ->and($path)->not->toContain('logo.png');
    Storage::disk(PublicFiles::DISK)->assertExists($path);
});

it('refuse un logo hors du dossier des fichiers publics : un document privé ne devient jamais public', function () {
    $cin = storeCompanyFile('customers/12/cin.png', base64_decode(COMPANY_TEST_PNG));

    expect(fn () => app(SaveCompanyProfile::class)->handle(companyData(['logo_path' => $cin]), $this->superAdmin))
        ->toThrow(SetupException::class, 'Logo refusé');
    expect(Company::query()->count())->toBe(0);
});

it('refuse un logo dont le contenu n\'est pas une image, ou une image SVG', function (string $path, string $content) {
    storeCompanyFile($path, $content);

    expect(fn () => app(SaveCompanyProfile::class)->handle(companyData(['logo_path' => $path]), $this->superAdmin))
        ->toThrow(SetupException::class, 'Logo refusé');
})->with([
    'texte renommé en .png' => ['public-files/company/faux.png', '<html><script>alert(1)</script></html>'],
    'SVG' => ['public-files/company/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
    'remontée de dossier' => ['public-files/company/../../customers/cin.png', 'x'],
]);

it('efface l\'ancien logo après son remplacement ou sa suppression', function () {
    $first = storeCompanyFile('public-files/company/premier.png', base64_decode(COMPANY_TEST_PNG));
    $second = storeCompanyFile('public-files/company/second.png', base64_decode(COMPANY_TEST_PNG));

    app(SaveCompanyProfile::class)->handle(companyData(['logo_path' => $first]), $this->admin);
    app(SaveCompanyProfile::class)->handle(companyData(['logo_path' => $second]), $this->admin);

    Storage::disk(PublicFiles::DISK)->assertMissing($first);
    Storage::disk(PublicFiles::DISK)->assertExists($second);

    app(SaveCompanyProfile::class)->handle(companyData(['logo_path' => null]), $this->admin);

    Storage::disk(PublicFiles::DISK)->assertMissing($second);
    expect(Company::current()?->logo_path)->toBeNull();
});
