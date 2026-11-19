<?php

use App\Domain\Setup\Actions\SaveCompanyProfile;
use App\Support\Authorization\Role;
use App\Support\Files\PublicFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
 * Route publique du logo (D21) : lecture seule, liste blanche, en-têtes de cache,
 * sans storage:link ni session.
 */

const PUBLIC_ROUTE_TEST_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

beforeEach(function () {
    Storage::fake(PublicFiles::DISK);
    $this->logo = 'public-files/company/'.str_repeat('a', 40).'.png';
    Storage::disk(PublicFiles::DISK)->put($this->logo, base64_decode(PUBLIC_ROUTE_TEST_PNG));

    app(SaveCompanyProfile::class)->handle([
        'legal_name' => 'Aviator SARL',
        'logo_path' => $this->logo,
    ], userWithProfile(Role::SuperAdmin));
});

it('sert le logo enregistré, sans connexion, avec son type et des en-têtes de cache', function () {
    $response = $this->get(PublicFiles::url($this->logo));

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $cacheControl = (string) $response->headers->get('Cache-Control');

    expect($cacheControl)->toContain('public')
        ->and($cacheControl)->toContain('max-age=31536000')
        ->and($cacheControl)->toContain('immutable')
        ->and($response->headers->get('ETag'))->not->toBeNull()
        ->and($response->headers->getCookies())->toBe([]);
});

it('répond 304 quand le navigateur a déjà le logo', function () {
    $etag = (string) $this->get(PublicFiles::url($this->logo))->headers->get('ETag');

    $this->get(PublicFiles::url($this->logo), ['If-None-Match' => $etag])->assertStatus(304);
});

it('ne sert aucun fichier hors de la liste blanche, même présent sur le disque', function (string $path) {
    Storage::disk(PublicFiles::DISK)->put($path, base64_decode(PUBLIC_ROUTE_TEST_PNG));

    $this->get('/fichiers/public/'.$path)->assertNotFound();
})->with([
    'autre image du dossier public' => ['public-files/company/ancien.png'],
    'document client' => ['customers/12/cin.png'],
    'contrat' => ['contracts/2026/contrat.jpg'],
]);

it('refuse les chemins détournés et les fichiers absents', function (string $url) {
    $this->get($url)->assertNotFound();
})->with([
    'remontée de dossier' => ['/fichiers/public/public-files/company/../../customers/cin.png'],
    'remontée encodée' => ['/fichiers/public/public-files%2F..%2F..%2F.env'],
    'fichier .env' => ['/fichiers/public/.env'],
    'absent' => ['/fichiers/public/public-files/company/absent.png'],
]);

it('ne sert plus un logo dont le contenu a été remplacé par autre chose qu\'une image', function () {
    Storage::disk(PublicFiles::DISK)->put($this->logo, '<html><script>alert(1)</script></html>');

    $this->get(PublicFiles::url($this->logo))->assertNotFound();
});

it('est en lecture seule', function (string $method) {
    $this->call($method, PublicFiles::url($this->logo))->assertStatus(405);
})->with(['POST', 'PUT', 'PATCH', 'DELETE']);

it('n\'expose pas le logo sous /storage : aucun lien symbolique (D21)', function () {
    expect(file_exists(public_path('storage')))->toBeFalse();

    $this->get('/storage/'.$this->logo)->assertNotFound();
});
