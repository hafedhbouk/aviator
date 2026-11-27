<?php

use App\Domain\Rental\Actions\ManageDamageFile;
use App\Domain\Rental\Actions\OpenContract;
use App\Domain\Rental\Actions\RecordInspection;
use App\Domain\Rental\Actions\RegisterReturn;
use App\Domain\Rental\Actions\SaveContractDriver;
use App\Domain\Rental\Actions\StartRental;
use App\Domain\Rental\Exceptions\ContractException;
use App\Domain\Setup\Actions\SaveRatePlan;
use App\Domain\Setup\Actions\SaveSeason;
use App\Enums\DamageDocumentKind;
use App\Enums\InspectionType;
use App\Filament\Resources\Contracts\ContractResource;
use App\Models\Agency;
use App\Models\Contract;
use App\Models\ContractCharge;
use App\Models\Customer;
use App\Models\DamageFile;
use App\Models\InspectionDamage;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\VehicleDocument;
use App\Support\Authorization\Role;
use App\Support\Files\PrivateFiles;
use App\Support\Sequences\SequenceAllocator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
 * Dossier de dommage (phase 5, lot L9, analyse du cabinet N2) : ouvert pour un dommage
 * nouveau du retour validé, pièces contrôlées sur le disque privé, frais du dommage
 * rattachés, complet seulement avec un constat et un devis ou une facture, portée par
 * agence (RG-13).
 */

beforeEach(function () {
    CarbonImmutable::setTestNow('2027-03-10 09:00');
    Storage::fake(PrivateFiles::DISK);

    $admin = withTwoFactor(userWithProfile(Role::Admin));
    $this->sfax = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->sousse = Agency::factory()->create(['code' => 'SOU', 'name' => 'Sousse']);
    $this->agent = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sfax->id]);
    $this->agentSousse = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sousse->id]);

    $this->category = VehicleCategory::factory()->create(['code' => 'CIT', 'name' => 'Citadine', 'insurance_excess_amount' => '1000.000']);
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
    $this->customer = Customer::factory()->create(['origin_agency_id' => $this->sfax->id]);
    app(SequenceAllocator::class)->prepare(OpenContract::sequence());

    [$this->contract, $this->newDamage, $this->oldDamage] = dmgReturnedContract();
    $this->files = app(ManageDamageFile::class);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function dmgSignature(): string
{
    $image = imagecreatetruecolor(200, 80);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
    imageline($image, 5, 70, 190, 10, (int) imagecolorallocate($image, 0, 0, 0));
    ob_start();
    imagepng($image);

    return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
}

/**
 * Contrat restitué avec un dommage déjà présent au départ et un dommage nouveau chiffré.
 *
 * @return array{0: Contract, 1: InspectionDamage, 2: InspectionDamage}
 */
function dmgReturnedContract(): array
{
    $agent = test()->agent;
    $record = app(RecordInspection::class);
    $contract = app(OpenContract::class)->atCounter([
        'customer_id' => test()->customer->id, 'agency_id' => test()->sfax->id, 'vehicle_id' => test()->vehicle->id,
        'starts_at' => '2027-03-10 09:00', 'ends_at' => '2027-03-13 09:00',
    ], $agent);
    app(SaveContractDriver::class)->handle($contract, [
        'first_name' => 'Amel', 'last_name' => 'Trabelsi', 'license_number' => '12/345678',
        'birth_date' => '1990-01-15', 'license_issued_on' => '2010-01-15', 'license_expires_on' => '2032-01-15', 'is_primary' => true,
    ], $agent);
    $departure = $record->open($contract, InspectionType::Departure, $agent);
    $record->update($departure, ['mileage' => 42100, 'fuel_eighths' => 8], $agent);
    $record->addDamage($departure, ['zone' => 'hood', 'x' => '50', 'y' => '20', 'description' => 'Éclat capot', 'severity' => 'minor'], $agent);
    $record->sign($departure, dmgSignature(), $agent);
    app(StartRental::class)->handle($contract, $agent);

    CarbonImmutable::setTestNow('2027-03-13 09:00');
    $return = $record->open($contract->fresh(), InspectionType::Return, $agent);
    $record->update($return, ['mileage' => 42500, 'fuel_eighths' => 8], $agent);
    $record->sign($return, dmgSignature(), $agent);
    $new = $record->addDamage($return, ['zone' => 'front_left', 'x' => '10', 'y' => '30', 'description' => 'Portière enfoncée', 'severity' => 'major'], $agent);
    app(RegisterReturn::class)->handle($contract->fresh(), ['damage_amounts' => [$new->id => '650,000']], $agent);

    return [$contract->fresh(), $new, $return->damages()->where('is_new', false)->sole()];
}

function dmgUpload(string $name, string $bytes = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF"): string
{
    $path = PrivateFiles::UPLOAD_DIRECTORY.'/'.$name;
    Storage::disk(PrivateFiles::DISK)->put($path, $bytes);

    return $path;
}

it('ouvre le dossier d\'un dommage nouveau et y rattache le frais du dommage', function () {
    $file = $this->files->open($this->contract, $this->newDamage->id, $this->agent);
    $charge = ContractCharge::query()->where('inspection_damage_id', $this->newDamage->id)->sole();

    expect($file->contract_id)->toBe($this->contract->id)
        ->and($file->isComplete())->toBeFalse()
        ->and($charge->damage_file_id)->toBe($file->id)
        ->and((string) $charge->amount)->toBe('650.000')
        // Un seul dossier par dommage.
        ->and($this->files->open($this->contract, $this->newDamage->id, $this->agent)->id)->toBe($file->id);
});

it('refuse un dossier pour un dommage déjà présent au départ ou d\'un autre contrat', function () {
    expect(fn () => $this->files->open($this->contract, $this->oldDamage->id, $this->agent))->toThrow(ContractException::class, 'dommage nouveau');

    $this->vehicle->refresh();
    CarbonImmutable::setTestNow('2027-03-20 08:00');
    $other = app(OpenContract::class)->atCounter([
        'customer_id' => $this->customer->id, 'agency_id' => $this->sfax->id, 'vehicle_id' => $this->vehicle->id,
        'starts_at' => '2027-03-20 09:00', 'ends_at' => '2027-03-22 09:00',
    ], $this->agent);

    expect(fn () => $this->files->open($other, $this->newDamage->id, $this->agent))->toThrow(ContractException::class);
});

it('n\'est complet qu\'avec un constat et un devis ou une facture de réparation', function () {
    $file = $this->files->open($this->contract, $this->newDamage->id, $this->agent);

    expect(fn () => $this->files->complete($file, $this->agent))->toThrow(ContractException::class, 'constat, devis ou facture de réparation');

    $this->files->attach($file, DamageDocumentKind::Report, dmgUpload('constat.pdf'), $this->agent);
    expect($this->files->missing($file))->toBe(['devis ou facture de réparation']);

    $document = $this->files->attach($file, DamageDocumentKind::Quote, dmgUpload('devis.pdf'), $this->agent);
    $this->files->complete($file, $this->agent);

    expect($file->refresh()->isComplete())->toBeTrue()
        ->and($file->completed_by)->toBe($this->agent->id)
        ->and($document->sha256)->toBe(hash('sha256', (string) Storage::disk(PrivateFiles::DISK)->get($document->path)))
        ->and(str_starts_with($document->path, "damage-files/{$file->id}/"))->toBeTrue()
        ->and(fn () => $this->files->attach($file, DamageDocumentKind::Other, dmgUpload('autre.pdf'), $this->agent))->toThrow(ContractException::class, 'dossier complet')
        ->and(fn () => $document->delete())->toThrow(LogicException::class)
        ->and(fn () => $file->delete())->toThrow(LogicException::class);
});

it('refuse une pièce qui n\'est ni un PDF ni une image, et un chemin hors du dépôt', function () {
    $file = $this->files->open($this->contract, $this->newDamage->id, $this->agent);

    expect(fn () => $this->files->attach($file, DamageDocumentKind::Report, dmgUpload('faux.pdf', '<?php echo 1;'), $this->agent))->toThrow(InvalidArgumentException::class, 'Type de fichier refusé')
        ->and(fn () => $this->files->attach($file, DamageDocumentKind::Report, 'damage-files/1/../../.env', $this->agent))->toThrow(InvalidArgumentException::class)
        ->and($file->documents()->count())->toBe(0);
});

it('réserve le dossier et ses pièces à l\'agence du contrat (RG-13)', function () {
    $file = $this->files->open($this->contract, $this->newDamage->id, $this->agent);
    $document = $this->files->attach($file, DamageDocumentKind::Report, dmgUpload('constat.pdf'), $this->agent);

    expect(fn () => $this->files->open($this->contract, $this->newDamage->id, $this->agentSousse))->toThrow(ContractException::class, 'non autorisée')
        ->and(fn () => $this->files->attach($file, DamageDocumentKind::Quote, dmgUpload('devis.pdf'), $this->agentSousse))->toThrow(ContractException::class, 'non autorisée');

    $this->actingAs($this->agentSousse);
    $this->get(route('filament.admin.private-files.damage-document', ['document' => $document->id]))->assertNotFound();

    $this->actingAs($this->agent);
    $this->get(route('filament.admin.private-files.damage-document', ['document' => $document->id]))->assertOk();
});

it('affiche les dossiers, leur état et leurs pièces sur la fiche du contrat', function () {
    $file = $this->files->open($this->contract, $this->newDamage->id, $this->agent);
    $this->files->attach($file, DamageDocumentKind::Report, dmgUpload('constat.pdf'), $this->agent);
    $this->actingAs($this->agent);

    $this->get(ContractResource::getUrl('view', ['record' => $this->contract]))->assertOk()
        ->assertSee('Dossiers de dommage')
        ->assertSee('Portière enfoncée')
        ->assertSee('incomplet : devis ou facture de réparation à joindre')
        ->assertSee('Dossier de dommage')
        ->assertSee('Dommage : Avant gauche (aile, portière), Portière enfoncée : 650,000 DT');
});

it('refuse un dossier sur un contrat qui n\'est pas encore restitué', function () {
    CarbonImmutable::setTestNow('2027-03-20 08:00');
    $draft = app(OpenContract::class)->atCounter([
        'customer_id' => $this->customer->id, 'agency_id' => $this->sfax->id, 'vehicle_id' => $this->vehicle->id,
        'starts_at' => '2027-03-20 09:00', 'ends_at' => '2027-03-22 09:00',
    ], $this->agent);

    expect(fn () => $this->files->open($draft, $this->newDamage->id, $this->agent))->toThrow(ContractException::class, 'Brouillon');
    expect(DamageFile::query()->count())->toBe(0);
});
