<?php

use App\Domain\Fleet\Actions\SaveVehicleDocument;
use App\Models\Agency;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\VehicleDocument;
use App\Support\Authorization\Role;
use App\Support\Files\PrivateFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/*
 * Fichiers privés (D21, lot L3) : pièces jointes des documents de véhicule, déposées
 * dans le dossier de dépôt puis rangées sous le véhicule ; servies uniquement après
 * la policy du document, jamais par un chemin.
 */

const PDF_CONTENT = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

beforeEach(function () {
    Storage::fake(PrivateFiles::DISK);

    $this->superAdmin = withTwoFactor(userWithProfile(Role::SuperAdmin));
    $this->sfax = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->sousse = Agency::factory()->create(['code' => 'SOU', 'name' => 'Sousse']);
    $this->agentSfax = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sfax->id]);
    $this->agentSousse = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sousse->id]);
    $this->vehicle = Vehicle::factory()->create([
        'agency_id' => $this->sfax->id,
        'vehicle_category_id' => VehicleCategory::factory()->create()->id,
    ]);
});

function uploaded(string $name, string $content): string
{
    $path = PrivateFiles::UPLOAD_DIRECTORY.'/'.$name;
    Storage::disk(PrivateFiles::DISK)->put($path, $content);

    return $path;
}

function documentWithFile(string $uploadedPath): VehicleDocument
{
    return app(SaveVehicleDocument::class)->handle(test()->vehicle, null, [
        'type' => 'registration', 'reference' => 'CG-1', 'file' => $uploadedPath,
    ], test()->superAdmin);
}

it('range la pièce sous le véhicule, avec un nom aléatoire, et vide le dossier de dépôt', function () {
    $upload = uploaded('carte-grise.pdf', PDF_CONTENT);

    $document = documentWithFile($upload);

    expect($document->file_path)->toMatch('#^vehicle-documents/'.$this->vehicle->id.'/[0-9a-f-]{36}\.pdf$#');
    Storage::disk(PrivateFiles::DISK)->assertExists($document->file_path);
    Storage::disk(PrivateFiles::DISK)->assertMissing($upload);
});

it('refuse un fichier dont le contenu ne correspond pas à un type accepté', function () {
    $upload = uploaded('faux.pdf', "<script>alert('x')</script>");

    expect(fn () => documentWithFile($upload))->toThrow(ValidationException::class);
    expect(VehicleDocument::query()->count())->toBe(0);
});

it('refuse un chemin qui ne vient pas du dossier de dépôt', function (string $forged) {
    Storage::disk(PrivateFiles::DISK)->put('customers/1/piece.pdf', PDF_CONTENT);

    expect(fn () => documentWithFile($forged))->toThrow(ValidationException::class);
    Storage::disk(PrivateFiles::DISK)->assertExists('customers/1/piece.pdf');
})->with([
    'autre dossier privé' => 'customers/1/piece.pdf',
    'remontée de dossier' => 'uploads/../customers/1/piece.pdf',
    'fichier absent' => 'uploads/inexistant.pdf',
]);

it('garde la pièce existante si le champ est vide ou inchangé', function () {
    $document = documentWithFile(uploaded('cg.pdf', PDF_CONTENT));
    $path = $document->file_path;

    app(SaveVehicleDocument::class)->handle($this->vehicle, $document, ['type' => 'registration', 'file' => null], $this->superAdmin);
    app(SaveVehicleDocument::class)->handle($this->vehicle, $document, ['type' => 'registration', 'file' => $path], $this->superAdmin);

    expect($document->fresh()?->file_path)->toBe($path);
});

it('sert un PDF au super admin, en téléchargement, sans cache ni interprétation du type', function () {
    $document = documentWithFile(uploaded('cg.pdf', PDF_CONTENT));

    $response = $this->actingAs($this->superAdmin)
        ->get(route('filament.admin.private-files.vehicle-document', ['document' => $document->id]));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment;')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Cache-Control'))->toContain('private');
});

it('affiche une image à l\'agent de l\'agence où se trouve le véhicule', function () {
    $png = UploadedFile::fake()->image('attestation.png', 20, 20)->getContent();
    $document = documentWithFile(uploaded('attestation.png', $png));

    $response = $this->actingAs($this->agentSfax)
        ->get(route('filament.admin.private-files.vehicle-document', ['document' => $document->id]));

    $response->assertOk()->assertHeader('Content-Type', 'image/png');
    expect($response->headers->get('Content-Disposition'))->toStartWith('inline;')
        ->and($response->headers->get('Content-Security-Policy'))->toContain('sandbox');
});

it('ne sert rien à l\'agent d\'une autre agence, sans dire que le document existe', function () {
    $document = documentWithFile(uploaded('cg.pdf', PDF_CONTENT));

    $this->actingAs($this->agentSousse)
        ->get(route('filament.admin.private-files.vehicle-document', ['document' => $document->id]))
        ->assertNotFound();

    $this->actingAs($this->agentSousse)
        ->get(route('filament.admin.private-files.vehicle-document', ['document' => 999999]))
        ->assertNotFound();
});

it('renvoie 404 pour un document sans pièce jointe', function () {
    $document = app(SaveVehicleDocument::class)->handle($this->vehicle, null, ['type' => 'road_tax', 'valid_until' => '2026-12-31'], $this->superAdmin);

    $this->actingAs($this->superAdmin)
        ->get(route('filament.admin.private-files.vehicle-document', ['document' => $document->id]))
        ->assertNotFound();
});

it('exige d\'être connecté', function () {
    $document = documentWithFile(uploaded('cg.pdf', PDF_CONTENT));

    $this->get(route('filament.admin.private-files.vehicle-document', ['document' => $document->id]))
        ->assertRedirect();
});

it('n\'accepte qu\'un identifiant numérique dans l\'adresse', function () {
    $this->actingAs($this->superAdmin)
        ->get('/admin/fichiers/vehicules/documents/..%2F..%2F.env')
        ->assertNotFound();
});
