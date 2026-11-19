<?php

/*
 * Contraintes de l'hébergement mutualisé (D14, D21, D25) : exec, proc_open, shell_exec,
 * system et passthru sont désactivés chez Oxahost ; l'application n'en dépend pas.
 */

arch('aucune fonction de lancement de processus dans l\'application (D14)')
    ->expect(['exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec'])
    ->not->toBeUsed();

arch('aucun lancement de sous-processus par Symfony Process ou la façade Process (D14)')
    ->expect('App')
    ->not->toUse(['Symfony\Component\Process', 'Illuminate\Support\Facades\Process', 'Illuminate\Process']);

arch('aucun lien symbolique créé par l\'application (D21)')
    ->expect(['symlink', 'link'])
    ->not->toBeUsed();

it('ne lance jamais storage:link : ni dans le code, ni dans Composer, ni au déploiement (D21)', function () {
    $files = [
        base_path('composer.json'),
        base_path('scripts/build-release.sh'),
        base_path('scripts/install-release.sh'),
        base_path('scripts/rollback-release.sh'),
        base_path('scripts/lib/release-files.sh'),
        base_path('routes/console.php'),
        base_path('routes/web.php'),
        base_path('routes/public.php'),
        base_path('bootstrap/app.php'),
    ];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        $files[] = $file->getPathname();
    }

    $offenders = array_values(array_filter(
        $files,
        fn (string $path): bool => is_file($path) && str_contains((string) file_get_contents($path), 'storage:link'),
    ));

    expect($offenders)->toBe([]);
});
