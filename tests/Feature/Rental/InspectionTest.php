<?php

use App\Domain\Approvals\Actions\DecideApproval;
use App\Domain\Approvals\Exceptions\ApprovalException;
use App\Domain\Crm\Actions\AddRecommendation;
use App\Domain\Fleet\Exceptions\VehicleNotRentable;
use App\Domain\Rental\Actions\CancelContract;
use App\Domain\Rental\Actions\OpenContract;
use App\Domain\Rental\Actions\RecordInspection;
use App\Domain\Rental\Actions\SaveContractDriver;
use App\Domain\Rental\Actions\StartRental;
use App\Domain\Rental\Exceptions\ContractException;
use App\Domain\Setup\Actions\SaveRatePlan;
use App\Domain\Setup\Actions\SaveSeason;
use App\Enums\ContractStatus;
use App\Enums\InspectionType;
use App\Enums\VehicleStatus;
use App\Models\Agency;
use App\Models\ApprovalRequest;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Inspection;
use App\Models\InspectionDamage;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\VehicleDocument;
use App\Settings\InspectionSettings;
use App\Support\Authorization\Role;
use App\Support\Files\PrivateFiles;
use App\Support\Sequences\SequenceAllocator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/*
 * États des lieux et départ (phase 5, lot L5, D-06, RG-04, D44, D45, D46, scénario 10) :
 * saisie, dommages repris du dernier retour, photos ré-encodées sans métadonnées,
 * signature, validation du départ (véhicule « Loué »), état des lieux figé, fichiers
 * privés servis par contrat (RG-13). Départ prévu le 10/03/2027 à 09:00.
 */

beforeEach(function () {
    CarbonImmutable::setTestNow('2027-03-10 08:30');
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

    $this->customer = Customer::factory()->create(['origin_agency_id' => $this->sfax->id]);
    app(SequenceAllocator::class)->prepare(OpenContract::sequence());
    $this->contract = inspectionContract();
    $this->record = app(RecordInspection::class);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function inspectionContract(array $overrides = []): Contract
{
    return app(OpenContract::class)->atCounter(array_merge([
        'customer_id' => test()->customer->id, 'agency_id' => test()->sfax->id, 'vehicle_id' => test()->vehicle->id,
        'starts_at' => '2027-03-10 09:00', 'ends_at' => '2027-03-13 09:00',
    ], $overrides), test()->agent);
}

function primaryDriver(Contract $contract): void
{
    app(SaveContractDriver::class)->handle($contract, [
        'first_name' => 'Amel', 'last_name' => 'Trabelsi', 'license_number' => '12/345678',
        'birth_date' => '1990-01-15', 'license_issued_on' => '2010-01-15', 'license_expires_on' => '2032-01-15', 'is_primary' => true,
    ], test()->agent);
}

function signatureDataUrl(): string
{
    $image = imagecreatetruecolor(300, 120);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
    imageline($image, 10, 100, 290, 20, (int) imagecolorallocate($image, 0, 0, 0));
    ob_start();
    imagepng($image);

    return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
}

/**
 * JPEG avec un bloc EXIF portant une position GPS fictive.
 */
function jpegWithGps(int $width = 800, int $height = 600): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 30, 30));
    ob_start();
    imagejpeg($image, null, 90);
    $jpeg = (string) ob_get_clean();

    $payload = "Exif\0\0GPSLatitude 33.8075 GPSLongitude 10.8451 position-secrete";
    $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

    return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
}

function uploadPrivate(string $bytes, string $name = 'photo.jpg'): string
{
    $path = PrivateFiles::UPLOAD_DIRECTORY.'/'.$name;
    Storage::disk(PrivateFiles::DISK)->put($path, $bytes);

    return $path;
}

/**
 * État des lieux de départ complet : kilométrage, carburant, signature.
 */
function completeDeparture(Contract $contract, int $mileage = 42150, int $fuel = 6): Inspection
{
    $inspection = app(RecordInspection::class)->open($contract, InspectionType::Departure, test()->agent);
    app(RecordInspection::class)->update($inspection, ['mileage' => $mileage, 'fuel_eighths' => $fuel], test()->agent);
    app(RecordInspection::class)->sign($inspection, signatureDataUrl(), test()->agent);

    return $inspection->refresh();
}

// --- Saisie ----------------------------------------------------------------------------------

it('reprend au départ les dommages du dernier retour validé du véhicule', function () {
    $previous = Contract::query()->create([
        'number' => 'CTR-2027-09999', 'agency_id' => $this->sfax->id, 'return_agency_id' => $this->sfax->id, 'vehicle_id' => $this->vehicle->id,
        'vehicle_category_id' => $this->category->id, 'customer_id' => $this->customer->id, 'starts_at' => '2027-02-01 09:00', 'ends_at' => '2027-02-03 09:00',
        'status' => ContractStatus::Closed, 'mileage_policy' => Contract::MILEAGE_UNLIMITED, 'fuel_policy' => Contract::FUEL_SAME_LEVEL,
        'daily_rate_amount' => '100.000', 'rental_amount' => '200.000', 'options_amount' => '0.000', 'total_amount' => '200.000', 'quote' => [],
    ]);
    $return = Inspection::query()->create(['contract_id' => $previous->id, 'type' => InspectionType::Return, 'mileage' => 42000, 'fuel_eighths' => 8, 'validated_at' => '2027-02-03 10:00']);
    InspectionDamage::query()->create(['inspection_id' => $return->id, 'zone' => 'rear_bumper', 'x' => '50.00', 'y' => '95.00', 'description' => 'Rayure pare-chocs', 'severity' => 'minor', 'is_new' => true]);

    $inspection = $this->record->open($this->contract, InspectionType::Departure, $this->agent);
    $damage = $inspection->damages()->sole();

    expect($damage->is_new)->toBeFalse()
        ->and($damage->description)->toBe('Rayure pare-chocs')
        ->and($damage->carried_from_id)->not->toBeNull()
        ->and(fn () => $this->record->removeDamage($damage, $this->agent))->toThrow(ContractException::class);

    // Rouvrir ne duplique ni l'état des lieux ni les dommages.
    expect($this->record->open($this->contract, InspectionType::Departure, $this->agent)->id)->toBe($inspection->id)
        ->and($inspection->damages()->count())->toBe(1);
});

it('refuse un kilométrage de départ inférieur au dernier relevé et un carburant hors des huit crans', function () {
    $inspection = $this->record->open($this->contract, InspectionType::Departure, $this->agent);

    expect(fn () => $this->record->update($inspection, ['mileage' => 41999], $this->agent))->toThrow(ContractException::class, '42 000 km')
        ->and(fn () => $this->record->update($inspection, ['fuel_eighths' => 9], $this->agent))->toThrow(ValidationException::class);

    $this->record->update($inspection, ['mileage' => 42000, 'fuel_eighths' => 8], $this->agent);
    expect($inspection->refresh()->mileage)->toBe(42000);
});

it('enregistre un dommage placé sur le schéma, avec sa zone et sa gravité', function () {
    $inspection = $this->record->open($this->contract, InspectionType::Departure, $this->agent);

    $damage = $this->record->addDamage($inspection, ['zone' => 'front_left', 'x' => '12.5', 'y' => '30', 'description' => 'Bosse portière', 'severity' => 'moderate'], $this->agent);

    expect($damage->is_new)->toBeTrue()
        ->and((string) $damage->fresh()?->x)->toBe('12.50')
        ->and(fn () => $this->record->addDamage($inspection, ['zone' => 'moteur', 'x' => '10', 'y' => '10', 'description' => 'x', 'severity' => 'minor'], $this->agent))->toThrow(ValidationException::class)
        ->and(fn () => $this->record->addDamage($inspection, ['zone' => 'hood', 'x' => '120', 'y' => '10', 'description' => 'x', 'severity' => 'minor'], $this->agent))->toThrow(ValidationException::class);
});

// --- Photos et signature (D44, D45) --------------------------------------------------------

it('ré-encode une photo : métadonnées GPS retirées, plus grand côté ramené à 1 600 px', function () {
    $inspection = $this->record->open($this->contract, InspectionType::Departure, $this->agent);
    $original = jpegWithGps(3000, 2000);
    expect($original)->toContain('GPSLatitude');

    $photo = $this->record->addPhoto($inspection, uploadPrivate($original), $this->agent);
    $stored = (string) Storage::disk(PrivateFiles::DISK)->get($photo->path);

    expect($stored)->not->toContain('GPSLatitude')->not->toContain('Exif')
        ->and($photo->width)->toBe(1600)
        ->and($photo->height)->toBe(1066)
        ->and($photo->sha256)->toBe(hash('sha256', $stored))
        ->and(str_starts_with($photo->path, "inspections/{$inspection->id}/"))->toBeTrue()
        ->and(Storage::disk(PrivateFiles::DISK)->exists(PrivateFiles::UPLOAD_DIRECTORY.'/photo.jpg'))->toBeFalse();
});

it('refuse un fichier qui n\'est pas une image, même renommé en .jpg, et un chemin hors du dépôt', function () {
    $inspection = $this->record->open($this->contract, InspectionType::Departure, $this->agent);

    expect(fn () => $this->record->addPhoto($inspection, uploadPrivate('<?php echo "pirate"; ?>', 'faux.jpg'), $this->agent))->toThrow(InvalidArgumentException::class, 'pas une image')
        ->and(fn () => $this->record->addPhoto($inspection, '../.env', $this->agent))->toThrow(InvalidArgumentException::class, 'introuvable')
        ->and($inspection->photos()->count())->toBe(0);
});

it('limite le nombre de photos par état des lieux (D44)', function () {
    $settings = app(InspectionSettings::class);
    $settings->max_photos = 1;
    $settings->save();
    $inspection = $this->record->open($this->contract, InspectionType::Departure, $this->agent);

    $this->record->addPhoto($inspection, uploadPrivate(jpegWithGps(), 'a.jpg'), $this->agent);

    expect(fn () => $this->record->addPhoto($inspection, uploadPrivate(jpegWithGps(), 'b.jpg'), $this->agent))->toThrow(InvalidArgumentException::class, '1 photos au plus')
        ->and($inspection->photos()->count())->toBe(1);
});

it('enregistre la signature dessinée et refuse une signature illisible', function () {
    $inspection = $this->record->open($this->contract, InspectionType::Departure, $this->agent);

    expect(fn () => $this->record->sign($inspection, 'data:image/png;base64,pas-une-image', $this->agent))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->record->sign($inspection, 'data:image/png;base64,'.base64_encode('texte'), $this->agent))->toThrow(InvalidArgumentException::class);

    $this->record->sign($inspection, signatureDataUrl(), $this->agent);
    $inspection->refresh();

    expect($inspection->signature_path)->not->toBeNull()
        ->and(Storage::disk(PrivateFiles::DISK)->exists((string) $inspection->signature_path))->toBeTrue()
        ->and(fn () => $this->record->markCustomerAbsent($inspection, 'Retour en boîte à clés', $this->agent))->toThrow(ContractException::class, 'absence du client');
});

// --- Départ ----------------------------------------------------------------------------------

it('valide le départ : contrat en cours, véhicule loué, kilométrage relevé, état des lieux figé', function () {
    primaryDriver($this->contract);
    $inspection = completeDeparture($this->contract);

    $contract = app(StartRental::class)->handle($this->contract, $this->agent);

    expect($contract->status)->toBe(ContractStatus::Active)
        ->and($contract->departed_at?->equalTo(CarbonImmutable::now()))->toBeTrue()
        ->and($contract->fuel_eighths_required)->toBe(6)
        ->and($this->vehicle->refresh()->status)->toBe(VehicleStatus::Rented)
        ->and($this->vehicle->mileage)->toBe(42150)
        ->and($inspection->refresh()->validated_by)->toBe($this->agent->id)
        ->and(fn () => $this->record->update($inspection, ['fuel_eighths' => 2], $this->agent))->toThrow(ContractException::class)
        ->and(fn () => $inspection->forceFill(['mileage' => 1])->save())->toThrow(LogicException::class)
        ->and(fn () => $inspection->delete())->toThrow(LogicException::class)
        ->and(fn () => app(StartRental::class)->handle($this->contract, $this->agent))->toThrow(ContractException::class, 'En cours');
});

it('refuse le départ sans état des lieux complet ni conducteur principal', function () {
    expect(fn () => app(StartRental::class)->handle($this->contract, $this->agent))->toThrow(ContractException::class, 'non commencé');

    $inspection = $this->record->open($this->contract, InspectionType::Departure, $this->agent);
    $this->record->update($inspection, ['mileage' => 42150, 'fuel_eighths' => 6], $this->agent);
    expect(fn () => app(StartRental::class)->handle($this->contract, $this->agent))->toThrow(ContractException::class, 'signature du client');

    $this->record->sign($inspection, signatureDataUrl(), $this->agent);
    expect(fn () => app(StartRental::class)->handle($this->contract, $this->agent))->toThrow(ContractException::class, 'conducteur principal')
        ->and($this->contract->fresh()?->status)->toBe(ContractStatus::Draft)
        ->and($this->vehicle->refresh()->status)->not->toBe(VehicleStatus::Rented);
});

it('refuse le départ avec une assurance expirée le jour du départ (scénario 10)', function () {
    primaryDriver($this->contract);
    completeDeparture($this->contract);
    VehicleDocument::query()->where('vehicle_id', $this->vehicle->id)->where('type', 'insurance')->update(['valid_until' => '2027-03-09']);

    expect(fn () => app(StartRental::class)->handle($this->contract, $this->agent))->toThrow(VehicleNotRentable::class, 'assurance')
        ->and($this->contract->fresh()?->status)->toBe(ContractStatus::Draft)
        ->and($this->vehicle->refresh()->mileage)->toBe(42000);
});

it('refuse le départ tant que la remise n\'est pas approuvée, puis l\'accepte (D46)', function () {
    app(CancelContract::class)->handle($this->contract, 'Remplacé par un autre contrat', $this->agent);
    $contract = inspectionContract(['discount_amount' => '30,000', 'discount_reason' => 'Geste commercial']);
    primaryDriver($contract);
    completeDeparture($contract);

    expect(fn () => app(StartRental::class)->handle($contract, $this->agent))->toThrow(ApprovalException::class, 'départ impossible');

    app(DecideApproval::class)->approve(ApprovalRequest::query()->sole(), $this->superAdmin);

    expect(app(StartRental::class)->handle($contract, $this->agent)->status)->toBe(ContractStatus::Active);
});

it('refuse le départ d\'un client sur liste noire tant que la dérogation n\'est pas approuvée', function () {
    app(CancelContract::class)->handle($this->contract, 'Remplacé par un autre contrat', $this->agent);
    app(AddRecommendation::class)->handle($this->customer, ['level' => 'blacklist', 'reason_code' => 'fraude'], $this->superAdmin);
    $contract = inspectionContract(['override_reason' => 'Dette réglée, justificatif présenté']);
    primaryDriver($contract);
    completeDeparture($contract);

    expect(fn () => app(StartRental::class)->handle($contract, $this->agent))->toThrow(ContractException::class, 'dérogation');

    app(DecideApproval::class)->approve(ApprovalRequest::query()->sole(), $this->superAdmin);

    expect(app(StartRental::class)->handle($contract, $this->agent)->status)->toBe(ContractStatus::Active);
});

// --- Retour (saisie, RG-04) ------------------------------------------------------------------

it('n\'ouvre le retour que d\'un contrat en cours, avec les dommages du départ et RG-04', function () {
    expect(fn () => $this->record->open($this->contract, InspectionType::Return, $this->agent))->toThrow(ContractException::class, 'Brouillon');

    primaryDriver($this->contract);
    $departure = completeDeparture($this->contract);
    $this->record->addDamage($departure, ['zone' => 'hood', 'x' => '50', 'y' => '20', 'description' => 'Éclat capot', 'severity' => 'minor'], $this->agent);
    app(StartRental::class)->handle($this->contract, $this->agent);

    $return = $this->record->open($this->contract->fresh(), InspectionType::Return, $this->agent);

    expect($return->damages()->sole()->is_new)->toBeFalse()
        ->and(fn () => $this->record->update($return, ['mileage' => 42149], $this->agent))->toThrow(ContractException::class, 'RG-04');

    $this->record->update($return, ['mileage' => 42150], $this->agent);
    $this->record->markCustomerAbsent($return, 'Retour en boîte à clés à 22 h', $this->agent);

    expect($return->refresh()->mileage)->toBe(42150)
        ->and($return->customer_absent_reason)->toBe('Retour en boîte à clés à 22 h');
});

// --- Droits et fichiers privés (RG-13, D21) -------------------------------------------------

it('réserve la saisie et les fichiers de l\'état des lieux à l\'agence du contrat', function () {
    $inspection = $this->record->open($this->contract, InspectionType::Departure, $this->agent);
    $photo = $this->record->addPhoto($inspection, uploadPrivate(jpegWithGps()), $this->agent);
    $this->record->sign($inspection, signatureDataUrl(), $this->agent);

    expect(fn () => $this->record->open($this->contract, InspectionType::Departure, $this->agentSousse))->toThrow(ContractException::class, 'non autorisée')
        ->and(fn () => $this->record->update($inspection, ['mileage' => 42500], $this->agentSousse))->toThrow(ContractException::class, 'non autorisée')
        ->and(fn () => app(StartRental::class)->handle($this->contract, $this->agentSousse))->toThrow(ContractException::class, 'non autorisée');

    /** @var User $agentSousse */
    $agentSousse = $this->agentSousse;
    $this->actingAs($agentSousse);
    $this->get(route('filament.admin.private-files.inspection-photo', ['photo' => $photo->id]))->assertNotFound();
    $this->get(route('filament.admin.private-files.inspection-signature', ['inspection' => $inspection->id]))->assertNotFound();

    $this->actingAs($this->agent);
    $this->get(route('filament.admin.private-files.inspection-photo', ['photo' => $photo->id]))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $this->get(route('filament.admin.private-files.inspection-signature', ['inspection' => $inspection->id]))->assertOk()->assertHeader('Content-Type', 'image/png');
});
