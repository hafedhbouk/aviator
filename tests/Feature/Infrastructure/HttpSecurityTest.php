<?php

use App\Providers\AppServiceProvider;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

it('envoie les en-têtes de sécurité sur toutes les pages', function () {
    $response = $this->get((string) Filament::getPanel('admin')->getLoginUrl());

    $response->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy')
        ->assertHeaderMissing('X-Powered-By');
});

it('redirige la racine du site vers le back-office, sans page d\'accueil de Laravel', function () {
    $this->get('/')
        ->assertRedirect('/admin')
        ->assertDontSee('Laravel');
});

it('impose HTTPS (HSTS) hors du poste local et des tests', function () {
    app()->detectEnvironment(fn (): string => 'production');

    $this->get((string) Filament::getPanel('admin')->getLoginUrl())
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

it('génère des adresses en https en production', function () {
    app()->detectEnvironment(fn (): string => 'production');
    (new AppServiceProvider(app()))->boot();

    expect(url('/admin'))->toStartWith('https://');

    URL::forceScheme(null);
});

// --- Fichiers : aucune exposition automatique, aucun stockage externe (D21) ---

it('n\'expose aucune route automatique de fichiers (storage/{path})', function () {
    $storageRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (Illuminate\Routing\Route $route): bool => str_starts_with($route->uri(), 'storage'))
        ->map(fn (Illuminate\Routing\Route $route): string => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();

    expect($storageRoutes)->toBe([]);
});

it('garde le disque privé hors du dossier public', function () {
    $privateRoot = str_replace('\\', '/', (string) config('filesystems.disks.private.root'));
    $publicPath = str_replace('\\', '/', public_path());

    expect($privateRoot)->not->toStartWith($publicPath)
        ->and(config('filesystems.disks.private.serve'))->toBeFalse()
        ->and(config('filesystems.disks.local.serve'))->toBeFalse();
});

it('n\'utilise aucun stockage externe (données en Tunisie)', function () {
    // Le disque « s3 » par défaut du framework reste défini (fusion de configuration de
    // Laravel), mais il est inutilisable : aucune clé AWS n'est prévue, et rien n'y fait référence.
    $usedDisks = ['local', 'private', 'public', (string) config('filesystems.default')];

    foreach ($usedDisks as $disk) {
        expect(config("filesystems.disks.{$disk}.driver"))->toBe('local', "Disque {$disk}");
    }

    // Le code de l'application ne fait référence à aucun service externe (S3, SQS, DynamoDB, SES…).
    $references = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        if (preg_match('/[\'"](s3|sqs|dynamodb|ses|postmark|resend)[\'"]|AWS_/i', (string) file_get_contents($file->getPathname())) === 1) {
            $references[] = $file->getFilename();
        }
    }

    expect($references)->toBe([]);

    // Valeurs par défaut des environnements : tout est local ou chez l'hébergeur en Tunisie.
    $example = (string) file_get_contents(base_path('.env.example'));

    expect($example)->not->toContain('AWS_')
        ->and($example)->toContain("FILESYSTEM_DISK=local\n")
        ->and($example)->toContain("CACHE_STORE=database\n")
        ->and($example)->toContain("QUEUE_CONNECTION=database\n")
        ->and($example)->toContain("SESSION_DRIVER=database\n")
        ->and($example)->toMatch('/^MAIL_MAILER=(log|smtp)$/m');
});
