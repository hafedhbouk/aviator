<?php

use App\Domain\Rental\Actions\OpenContract;
use App\Domain\Rental\Actions\RecordInspection;
use App\Domain\Rental\Actions\RegisterReturn;
use App\Domain\Rental\Actions\SaveContractDriver;
use App\Domain\Rental\Actions\StartRental;
use App\Domain\Rental\Documents\ContractDocuments;
use App\Domain\Setup\Actions\SaveRatePlan;
use App\Domain\Setup\Actions\SaveSeason;
use App\Domain\Setup\Actions\UpdateSettings;
use App\Enums\InspectionType;
use App\Models\Agency;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Inspection;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\VehicleDocument;
use App\Settings\ContractSettings;
use App\Support\Authorization\Role;
use App\Support\Files\PrivateFiles;
use App\Support\Sequences\SequenceAllocator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
 * Documents du contrat (phase 5, lot L11, D-10, D43, Q15) : PDF du contrat, de l'état
 * des lieux et des conditions générales ; exemplaire signé de l'état des lieux figé à la
 * validation ; mention « Modèle non validé » ; aucune coordonnée du client (D13) ;
 * portée par agence (RG-13).
 */

beforeEach(function () {
    CarbonImmutable::setTestNow('2027-03-10 09:00');
    Storage::fake(PrivateFiles::DISK);

    $this->superAdmin = withTwoFactor(userWithProfile(Role::SuperAdmin));
    $admin = withTwoFactor(userWithProfile(Role::Admin));
    $this->sfax = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->sousse = Agency::factory()->create(['code' => 'SOU', 'name' => 'Sousse']);
    $this->agent = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sfax->id]);
    $this->agentSousse = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sousse->id]);

    $this->category = VehicleCategory::factory()->create(['code' => 'CIT', 'name' => 'Citadine']);
    $season = app(SaveSeason::class)->handle(null, ['name' => 'Année 2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31'], $admin);
    app(SaveRatePlan::class)->handle(null, [
        'vehicle_category_id' => $this->category->id, 'season_id' => $season->id, 'agency_id' => null,
        'valid_from' => '2027-01-01', 'valid_to' => null, 'is_active' => true,
        'lines' => [['min_days' => 1, 'max_days' => null, 'daily_amount' => '100,000']],
    ], $admin);
    $this->vehicle = Vehicle::factory()->create(['agency_id' => $this->sfax->id, 'vehicle_category_id' => $this->category->id, 'mileage' => 42000])->refresh();
    foreach (['insurance', 'technical_inspection'] as $type) {
        VehicleDocument::query()->create(['vehicle_id' => $this->vehicle->id, 'type' => $type, 'valid_from' => '2027-01-01', 'valid_until' => '2027-12-31']);
    }
    $this->customer = Customer::factory()->create([
        'origin_agency_id' => $this->sfax->id, 'first_name' => 'Amel', 'last_name' => 'Trabelsi',
        'phone' => '20 111 222', 'email' => 'amel@exemple.test', 'address' => '12 rue de la Liberté',
        'id_document_type' => 'cin', 'id_document_number' => '08123456',
    ]);
    app(SequenceAllocator::class)->prepare(OpenContract::sequence());

    $this->contract = app(OpenContract::class)->atCounter([
        'customer_id' => $this->customer->id, 'agency_id' => $this->sfax->id, 'vehicle_id' => $this->vehicle->id,
        'starts_at' => '2027-03-10 09:00', 'ends_at' => '2027-03-13 09:00',
    ], $this->agent);
    app(SaveContractDriver::class)->handle($this->contract, [
        'first_name' => 'Amel', 'last_name' => 'Trabelsi', 'license_number' => '12/345678',
        'birth_date' => '1990-01-15', 'license_issued_on' => '2010-01-15', 'license_expires_on' => '2032-01-15', 'is_primary' => true,
    ], $this->agent);
    $this->docs = app(ContractDocuments::class);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function docSignature(): string
{
    $image = imagecreatetruecolor(200, 80);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
    imageline($image, 5, 70, 190, 10, (int) imagecolorallocate($image, 0, 0, 0));
    ob_start();
    imagepng($image);

    return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
}

function docDeparture(Contract $contract): Contract
{
    $record = app(RecordInspection::class);
    $inspection = $record->open($contract, InspectionType::Departure, test()->agent);
    $record->update($inspection, ['mileage' => 42100, 'fuel_eighths' => 8], test()->agent);
    $record->addDamage($inspection, ['zone' => 'hood', 'x' => '50', 'y' => '20', 'description' => 'Éclat capot', 'severity' => 'minor'], test()->agent);
    $record->sign($inspection, docSignature(), test()->agent);

    return app(StartRental::class)->handle($contract, test()->agent);
}

function docHtml(string $view, array $data): string
{
    return view($view, app(ContractDocuments::class)->viewData($data))->render();
}

it('produit le PDF du contrat avec ses parties, conducteurs, conditions et montants, sans coordonnée du client', function () {
    docDeparture($this->contract);
    $html = docHtml('pdf.contract', ['contract' => $this->contract->fresh(), 'departure' => Inspection::query()->sole()]);

    expect($html)->toContain('Contrat de location n° '.$this->contract->number)
        ->toContain('Amel Trabelsi')
        ->toContain('CIN n° 08123456')
        ->toContain('12/345678')
        ->toContain('Illimité')
        ->toContain('300,000 DT')
        ->toContain('data:image/png;base64,')
        ->toContain('Modèle non validé')
        ->not->toContain('20 111 222')
        ->not->toContain('amel@exemple.test')
        ->not->toContain('rue de la Liberté');

    expect(str_starts_with($this->docs->contractPdf($this->contract->fresh()), '%PDF-'))->toBeTrue();
});

it('retire la mention « Modèle non validé » quand le modèle est validé (Q15)', function () {
    app(UpdateSettings::class)->handle(app(ContractSettings::class), ['template_validated' => true], $this->superAdmin);

    expect(docHtml('pdf.contract', ['contract' => $this->contract, 'departure' => null]))->not->toContain('Modèle non validé');
});

it('fige l\'exemplaire signé de l\'état des lieux à la validation du départ et du retour (D43)', function () {
    docDeparture($this->contract);
    $departure = Inspection::query()->where('type', 'departure')->sole();
    $stored = (string) Storage::disk(PrivateFiles::DISK)->get((string) $departure->signed_pdf_path);

    expect($departure->signed_pdf_path)->not->toBeNull()
        ->and(str_starts_with($stored, '%PDF-'))->toBeTrue()
        ->and($departure->signed_pdf_sha256)->toBe(hash('sha256', $stored));

    // Un réglage modifié ensuite ne change pas l'exemplaire signé.
    app(UpdateSettings::class)->handle(app(ContractSettings::class), ['template_validated' => true, 'terms_text' => 'Nouveau texte'], $this->superAdmin);
    expect(hash('sha256', (string) Storage::disk(PrivateFiles::DISK)->get((string) $departure->fresh()?->signed_pdf_path)))->toBe($departure->signed_pdf_sha256);

    CarbonImmutable::setTestNow('2027-03-13 09:00');
    $record = app(RecordInspection::class);
    $return = $record->open($this->contract->fresh(), InspectionType::Return, $this->agent);
    $record->update($return, ['mileage' => 42500, 'fuel_eighths' => 8], $this->agent);
    $record->markCustomerAbsent($return, 'Clés déposées dans la boîte', $this->agent);
    app(RegisterReturn::class)->handle($this->contract->fresh(), [], $this->agent);

    expect($return->fresh()?->signed_pdf_path)->not->toBeNull();
});

it('détaille l\'état des lieux : relevés, dommages, signature', function () {
    docDeparture($this->contract);
    $html = docHtml('pdf.inspection', ['contract' => $this->contract->fresh(), 'inspection' => Inspection::query()->sole()->load(['damages', 'photos'])]);

    expect($html)->toContain('42 100 km')->toContain('plein')->toContain('Éclat capot')->toContain('data:image/png;base64,');
});

it('imprime les conditions générales saisies, échappées, ou signale leur absence', function () {
    expect(docHtml('pdf.terms', []))->toContain('Conditions générales non saisies');

    app(UpdateSettings::class)->handle(app(ContractSettings::class), [
        'terms_text' => "# Article 1 — Objet\n\nLe loueur met à disposition <script>alert(1)</script> un véhicule.",
    ], $this->superAdmin);

    $html = docHtml('pdf.terms', []);
    expect($html)->toContain('Article 1 — Objet')
        ->toContain('&lt;script&gt;')
        ->not->toContain('<script>alert');
});

it('sert les PDF à l\'agence du contrat seulement (RG-13)', function () {
    docDeparture($this->contract);
    $inspection = Inspection::query()->sole();

    $this->actingAs($this->agentSousse);
    $this->get(route('filament.admin.documents.contract', ['contract' => $this->contract->id]))->assertNotFound();
    $this->get(route('filament.admin.documents.inspection', ['inspection' => $inspection->id]))->assertNotFound();

    $this->actingAs($this->agent);
    $this->get(route('filament.admin.documents.contract', ['contract' => $this->contract->id]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get(route('filament.admin.documents.inspection', ['inspection' => $inspection->id]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get(route('filament.admin.documents.terms'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
});
