<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/*
 * Déploiement par archive (D25) : scripts/build-release.sh (poste),
 * scripts/install-release.sh et scripts/rollback-release.sh (terminal web de cPanel).
 */

const RELEASE_SCRIPTS = [
    'scripts/build-release.sh',
    'scripts/install-release.sh',
    'scripts/rollback-release.sh',
    'scripts/lib/release-files.sh',
    'scripts/clean-env.sh',
    'scripts/ci-env-testing.sh',
];

/**
 * Bash du poste (Git Bash sous Windows, jamais le bash de WSL) ou du serveur de CI.
 */
function bashBinary(): ?string
{
    if (PHP_OS_FAMILY !== 'Windows') {
        return 'bash';
    }

    $gitBash = 'C:\\Program Files\\Git\\bin\\bash.exe';

    return is_file($gitBash) ? $gitBash : null;
}

/**
 * Chemin compréhensible par bash (D:\aviator → /d/aviator sous Git Bash).
 */
function bashPath(string $path): string
{
    $path = str_replace('\\', '/', $path);

    return PHP_OS_FAMILY === 'Windows' && preg_match('#^([A-Za-z]):/#', $path, $m) === 1
        ? '/'.strtolower($m[1]).substr($path, 2)
        : $path;
}

/**
 * @param  array<string, string>  $env
 */
function runBash(string $script, array $env = [], ?string $input = null): Process
{
    $bash = bashBinary();

    if ($bash === null) {
        test()->markTestSkipped('Git Bash introuvable sur ce poste.');
    }

    $process = new Process([$bash, '-c', $script], base_path(), $env + ['LC_ALL' => 'C.UTF-8'], $input, 120);
    $process->run();

    return $process;
}

function makeTree(string $root, array $files): void
{
    foreach ($files as $file => $content) {
        $path = $root.'/'.$file;

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $content);
    }
}

function tempDir(string $prefix): string
{
    $dir = storage_path('framework/testing/'.$prefix.'-'.Str::random(8));
    mkdir($dir, 0777, true);

    return $dir;
}

afterEach(function () {
    foreach (glob(storage_path('framework/testing/release-*')) ?: [] as $dir) {
        (new Filesystem)->deleteDirectory($dir);
    }
});

// --- Scripts --------------------------------------------------------------------

it('ne contient plus scripts/deploy.sh (remplacé par D25)', function () {
    expect(file_exists(base_path('scripts/deploy.sh')))->toBeFalse();
});

it('écrit des scripts bash syntaxiquement corrects', function (string $script) {
    $process = runBash('bash -n '.escapeshellarg(bashPath(base_path($script))));

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
})->with(RELEASE_SCRIPTS);

it('passe shellcheck (si l\'outil est disponible)', function () {
    $check = runBash('command -v shellcheck');

    if (! $check->isSuccessful()) {
        $this->markTestSkipped('shellcheck n\'est pas installé sur ce poste (il l\'est sur la CI).');
    }

    $files = implode(' ', array_map(fn (string $s): string => escapeshellarg($s), RELEASE_SCRIPTS));
    $process = runBash("shellcheck --severity=warning --external-sources {$files}");

    expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
});

it('n\'utilise sur le serveur ni Composer, ni npm, ni Git, ni « set -e » global', function (string $script) {
    $code = collect(explode("\n", (string) file_get_contents(base_path($script))))
        ->reject(fn (string $line): bool => str_starts_with(ltrim($line), '#'))
        ->implode("\n");

    expect($code)->not->toMatch('/\b(composer|npm|git)\b/')
        ->and($code)->not->toMatch('/^\s*set\s+-[a-z]*e/m');
})->with(['scripts/install-release.sh', 'scripts/rollback-release.sh']);

// --- Environnement des contrôles (clean-env.sh) ---------------------------------------

/**
 * Copie clean-env.sh dans un faux dépôt, avec ses .env.example et .env.
 */
function cleanEnvFixture(): string
{
    $root = tempDir('release-cleanenv');
    makeTree($root, [
        '.env.example' => "# commentaire\nAVIATOR_FIXTURE_A=valeur-exemple\n\nexport AVIATOR_FIXTURE_B=\n",
        '.env' => "AVIATOR_FIXTURE_C=\"valeur du fichier\"\nPATH=/nulle-part\n",
    ]);
    mkdir($root.'/scripts');
    copy(base_path('scripts/clean-env.sh'), $root.'/scripts/clean-env.sh');

    return $root;
}

const CLEAN_ENV_SECRETS = [
    'AVIATOR_FIXTURE_A' => 'secret-a-4f1c',
    'AVIATOR_FIXTURE_B' => 'secret-b-9d2e',
    'AVIATOR_FIXTURE_C' => 'secret-c-77ab',
];

it('retire les variables nommées dans .env.example et .env, garde les autres et lance la commande', function () {
    $script = escapeshellarg(bashPath(cleanEnvFixture().'/scripts/clean-env.sh'));

    $process = runBash("bash {$script} env", CLEAN_ENV_SECRETS + ['AVIATOR_FIXTURE_KEEP' => 'gardee']);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->not->toContain('AVIATOR_FIXTURE_A=', 'AVIATOR_FIXTURE_B=', 'AVIATOR_FIXTURE_C=')
        ->and($process->getOutput())->toContain('AVIATOR_FIXTURE_KEEP=gardee', 'AVIATOR_CLEAN_ENV=1', 'PATH=')
        ->and($process->getOutput())->not->toContain('PATH=/nulle-part')
        ->and($process->getErrorOutput())->toContain('3 variable(s)');
});

it('n\'affiche jamais aucune valeur et transmet le code de sortie de la commande', function () {
    $script = escapeshellarg(bashPath(cleanEnvFixture().'/scripts/clean-env.sh'));

    $ok = runBash("bash {$script} true", CLEAN_ENV_SECRETS);
    $failed = runBash("bash {$script} bash -c 'exit 7'", CLEAN_ENV_SECRETS);
    $usage = runBash("bash {$script}");

    $printed = $ok->getOutput().$ok->getErrorOutput().$failed->getOutput().$failed->getErrorOutput();

    expect($ok->getExitCode())->toBe(0)
        ->and($failed->getExitCode())->toBe(7)
        ->and($usage->getExitCode())->toBe(64);

    foreach ([...CLEAN_ENV_SECRETS, 'valeur-exemple', 'valeur du fichier', '/nulle-part'] as $value) {
        expect($printed)->not->toContain($value);
    }
});

it('fait passer composer check, composer test et build-release.sh par clean-env.sh', function () {
    $scripts = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR)['scripts'];
    $build = (string) file_get_contents(base_path('scripts/build-release.sh'));

    expect($scripts['check'])->toBe([
        'Composer\\Config::disableProcessTimeout',
        'bash scripts/clean-env.sh composer run-script --timeout=0 check-steps',
    ])
        // check-steps refuse de tourner sans clean-env.sh, avant tout contrôle.
        ->and($scripts['check-steps'][1])->toContain("getenv('AVIATOR_CLEAN_ENV') === '1'", 'exit(1)')
        ->and($scripts['check-steps'])->toContain('@php artisan test')
        ->and($scripts['test'])->toContain('bash scripts/clean-env.sh php artisan test')
        ->and($build)->toContain('exec bash "$ROOT/scripts/clean-env.sh" bash "$ROOT/scripts/build-release.sh" "$@"')
        ->and($build)->not->toContain('UNSET_ARGS');
});

it('refuse composer check-steps lancé sans clean-env.sh', function () {
    $guard = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR)['scripts']['check-steps'][1];
    $code = preg_replace('/^@php -r "(.*)"$/s', '$1', $guard);

    $without = new Process([PHP_BINARY, '-r', $code], base_path(), ['AVIATOR_CLEAN_ENV' => false]);
    $with = new Process([PHP_BINARY, '-r', $code], base_path(), ['AVIATOR_CLEAN_ENV' => '1']);

    expect($without->run())->toBe(1)
        ->and($without->getErrorOutput())->toContain('composer check')
        ->and($with->run())->toBe(0);
});

it('décrit un workflow « Release » manuel et sur étiquette, sans secret, archive conservée 30 jours', function () {
    $release = (string) file_get_contents(base_path('.github/workflows/release.yml'));
    $ci = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

    expect($release)->toContain('workflow_dispatch:', 'tags: ["v[0-9]+.[0-9]+.[0-9]+"]', 'image: mariadb:10.11', 'php-version: "8.3"')
        ->toContain('bash scripts/build-release.sh "$RELEASE_REF"', 'bash scripts/ci-env-testing.sh')
        ->toContain('retention-days: 30', 'aviator-*.tar.gz.sha256', 'permissions:', 'contents: read')
        ->not->toContain('secrets.')
        // Jamais sur une poussée de branche : manuel ou étiquette seulement.
        ->not->toContain('branches:')
        // La référence saisie n'est jamais interpolée dans un script (injection).
        ->not->toMatch('/run:.*\$\{\{\s*inputs\./')
        // Écriture dans le dépôt : un seul job, réservé aux étiquettes poussées.
        ->and(substr_count($release, 'contents: write'))->toBe(1)
        ->and($release)->toMatch("/  publication:\n(?:.*\n)*?    if: github\.event_name == 'push' && startsWith\(github\.ref, 'refs\/tags\/v'\)\n(?:.*\n)*?    permissions:\n      contents: write\n/")
        ->toContain('gh release create "$TAG" aviator-*.tar.gz aviator-*.tar.gz.sha256', '--verify-tag', 'GH_TOKEN: ${{ github.token }}')
        ->and($ci)->toContain('bash scripts/ci-env-testing.sh')
        ->not->toContain('secrets.')
        ->not->toMatch('/^\s+DB_DATABASE:/m');
});

// --- Contenu de l'archive ------------------------------------------------------------

function releaseFixture(string $root): void
{
    makeTree($root, [
        'artisan' => '<?php',
        'vendor/autoload.php' => '<?php',
        'vendor/acme/package/tests/OwnTest.php' => '<?php',
        'public/index.php' => '<?php',
        'public/build/manifest.json' => '{}',
        'RELEASE' => "version=v9.9.9\n",
        '.env.example' => "APP_KEY=\n",
        '.env' => "APP_KEY=secret\n",
        '.env.testing' => "DB_PASSWORD=secret\n",
        'tests/Pest.php' => '<?php',
        'node_modules/vite/index.js' => '',
        '.git/HEAD' => 'ref: refs/heads/main',
        'docs/projet.md' => '# Projet',
        'scripts/install-release.sh' => '#!/usr/bin/env bash',
        'scripts/rollback-release.sh' => '#!/usr/bin/env bash',
        'scripts/build-release.sh' => '#!/usr/bin/env bash',
        'storage/logs/.gitignore' => "*\n",
        'storage/logs/laravel.log' => 'journal',
        'storage/app/private/.gitignore' => "*\n",
        'storage/app/private/cin.jpg' => 'photo',
        'storage/framework/sessions/abc' => 'session',
    ]);
}

it('produit une archive sans .env, sans tests, sans node_modules, sans .git, avec storage/ vide', function () {
    $root = tempDir('release-tree');
    releaseFixture($root.'/stage');

    $lib = escapeshellarg(bashPath(base_path('scripts/lib/release-files.sh')));
    $dir = escapeshellarg(bashPath($root));
    $process = runBash(
        "source {$lib} && prune_release_tree {$dir}/stage && verify_release_tree {$dir}/stage"
        ." && (cd {$dir}/stage && tar -czf ../aviator-test.tar.gz .)"
        ." && verify_release_archive {$dir} aviator-test.tar.gz && (cd {$dir} && tar -tzf aviator-test.tar.gz)"
    );

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $entries = array_map(
        fn (string $entry): string => preg_replace('#^\./#', '', $entry) ?? $entry,
        array_filter(explode("\n", trim($process->getOutput()))),
    );

    expect($entries)->toContain('artisan', 'vendor/autoload.php', '.env.example', 'RELEASE', 'scripts/install-release.sh', 'scripts/rollback-release.sh', 'storage/logs/.gitignore')
        ->not->toContain('.env')
        ->not->toContain('.env.testing')
        ->not->toContain('scripts/build-release.sh')
        ->not->toContain('storage/logs/laravel.log')
        ->not->toContain('storage/app/private/cin.jpg')
        ->not->toContain('storage/framework/sessions/abc');

    foreach ($entries as $entry) {
        expect($entry)->not->toStartWith('tests/')
            ->not->toStartWith('.git/')
            ->not->toStartWith('node_modules/')
            ->not->toStartWith('docs/');
    }
});

it('refuse une arborescence ou une archive qui contient .env, tests, node_modules ou .git', function () {
    $root = tempDir('release-bad');
    releaseFixture($root.'/stage');

    $lib = escapeshellarg(bashPath(base_path('scripts/lib/release-files.sh')));
    $dir = escapeshellarg(bashPath($root));

    $tree = runBash("source {$lib} && verify_release_tree {$dir}/stage");
    $archive = runBash("source {$lib} && (cd {$dir}/stage && tar -czf ../bad.tar.gz .) && verify_release_archive {$dir} bad.tar.gz");

    expect($tree->isSuccessful())->toBeFalse()
        ->and($tree->getErrorOutput())->toContain('.env', '.git', 'node_modules', 'tests')
        ->and($archive->isSuccessful())->toBeFalse()
        ->and($archive->getErrorOutput())->toContain('.env', 'storage/');
});

it('construit dans AVIATOR_BUILD_DIR, jamais dans le dossier temporaire du système (contrôle statique)', function () {
    $script = (string) file_get_contents(base_path('scripts/build-release.sh'));

    expect($script)->toContain('BUILD_DIR="${AVIATOR_BUILD_DIR:-/d/aviator-builds}"')
        ->toContain('mktemp -d "$BUILD_DIR/work.XXXXXX"')
        ->not->toMatch('/mktemp -d\s*\)/')
        ->not->toContain('$ROOT/dist')
        ->toContain('npm ci --prefer-offline')
        ->and(substr_count($script, 'mktemp'))->toBe(1);
});

it('refuse une arborescence qui contient une dépendance de développement', function (string $package) {
    $root = tempDir('release-dev');
    releaseFixture($root.'/stage');
    makeTree($root.'/stage', ["vendor/{$package}/composer.json" => '{}']);

    $lib = escapeshellarg(bashPath(base_path('scripts/lib/release-files.sh')));
    $dir = escapeshellarg(bashPath($root));

    // Arborescence par ailleurs conforme : seule la dépendance de développement est en cause.
    $tree = runBash("source {$lib} && prune_release_tree {$dir}/stage && verify_release_tree {$dir}/stage");

    expect($tree->isSuccessful())->toBeFalse()
        // Le contrôle porte sur le dossier de l'éditeur (vendor/phpunit) ou du paquet (vendor/laravel/pint).
        ->and($tree->getErrorOutput())->toContain("Dépendance de développement dans l'archive : vendor/".strtok($package, '/'));
})->with(['phpunit/phpunit', 'pestphp/pest', 'larastan/larastan', 'laravel/pint', 'laravel/pail', 'laravel/pao', 'fakerphp/faker', 'mockery/mockery', 'nunomaduro/collision']);

it('couvre dans le contrôle de l\'archive chaque paquet de développement de composer.json', function () {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
    $script = (string) file_get_contents(base_path('scripts/lib/release-files.sh'));

    foreach (array_keys($composer['require-dev']) as $package) {
        $vendor = strtok($package, '/');
        $covered = str_contains($script, "vendor/{$package}") || str_contains($script, "vendor/{$vendor} ");

        expect($covered)->toBeTrue("Paquet de développement non contrôlé dans l'archive : {$package}");
    }
});

// --- Installation et retour arrière (essai complet, Linux) ---------------------------

it('installe, conserve 3 versions et revient en arrière dans un dossier personnel simulé', function () {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('Essai réservé à Linux (liens symboliques réels, mv -T) : il tourne sur la CI.');
    }

    $home = tempDir('release-home');
    $bin = $home.'/bin';
    mkdir($bin);
    file_put_contents($bin.'/mysqldump', "#!/usr/bin/env bash\necho '-- sauvegarde de test'\n");
    chmod($bin.'/mysqldump', 0755);

    $env = ['HOME' => $home, 'PATH' => $bin.':'.(getenv('PATH') ?: '/usr/bin:/bin'), 'AVIATOR_PHP' => PHP_BINARY];
    $install = escapeshellarg(base_path('scripts/install-release.sh'));
    $rollback = escapeshellarg(base_path('scripts/rollback-release.sh'));

    // Fausse application : artisan note chaque commande dans calls.log. $argv[0] garde le
    // chemin tel qu'il a été appelé (current/…), alors que __DIR__ résoudrait le lien.
    $artisan = '<?php file_put_contents(getenv("HOME")."/calls.log", basename(dirname($argv[0]))." ".implode(" ", array_slice($argv, 1)).PHP_EOL, FILE_APPEND);';

    $buildArchive = function (string $version) use ($home, $artisan): string {
        $stage = $home.'/stage-'.$version;
        makeTree($stage, [
            'artisan' => $artisan,
            'vendor/autoload.php' => '<?php',
            'public/index.php' => '<?php',
            '.env.example' => "DB_DATABASE=aviator_recette\nAPP_KEY=\n",
            'RELEASE' => "version={$version}\ncommit=abc\n",
            'storage/logs/.gitignore' => "*\n",
        ]);
        $archive = $home.'/aviator-'.$version.'.tar.gz';
        (new Process(['tar', '-czf', $archive, '-C', $stage, '.']))->mustRun();
        file_put_contents($archive.'.sha256', hash_file('sha256', $archive).'  '.basename($archive).PHP_EOL);

        return $archive;
    };

    // 1. Première exécution : crée shared/.env et s'arrête.
    $first = runBash("bash {$install} recette ".escapeshellarg($buildArchive('v1.0.0')), $env);
    expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
        ->and($first->getOutput())->toContain('PREMIÈRE INSTALLATION')
        ->and(file_exists($home.'/apps/recette/shared/.env'))->toBeTrue()
        ->and(file_exists($home.'/apps/recette/current'))->toBeFalse();

    // 2. Sans ~/.my.recette.cnf : arrêt propre, rien en service.
    $noCnf = runBash("bash {$install} recette ".escapeshellarg($home.'/aviator-v1.0.0.tar.gz'), $env);
    expect($noCnf->isSuccessful())->toBeFalse()
        ->and($noCnf->getErrorOutput())->toContain('.my.recette.cnf')
        ->and(glob($home.'/apps/recette/releases/*', GLOB_ONLYDIR))->toBe([]);
    file_put_contents($home.'/.my.recette.cnf', "[client]\nuser=test\npassword=test\n");

    // 3. Installation de v1.0.0, puis v1.1.0, v1.2.0, v1.3.0.
    foreach (['v1.0.0', 'v1.1.0', 'v1.2.0', 'v1.3.0'] as $version) {
        $archive = file_exists($home.'/aviator-'.$version.'.tar.gz') ? $home.'/aviator-'.$version.'.tar.gz' : $buildArchive($version);
        $run = runBash("bash {$install} recette ".escapeshellarg($archive), $env);

        expect($run->isSuccessful())->toBeTrue($version.' : '.$run->getErrorOutput().$run->getOutput())
            ->and(readlink($home.'/apps/recette/current'))->toBe('releases/'.$version);
    }

    $releases = array_map('basename', glob($home.'/apps/recette/releases/*', GLOB_ONLYDIR) ?: []);
    sort($releases);
    $calls = (string) file_get_contents($home.'/calls.log');

    expect($releases)->toBe(['v1.1.0', 'v1.2.0', 'v1.3.0'])
        ->and(is_link($home.'/apps/recette/releases/v1.3.0/.env'))->toBeTrue()
        ->and(is_link($home.'/apps/recette/releases/v1.3.0/storage'))->toBeTrue()
        ->and($calls)->toContain('v1.3.0 migrate --force', 'v1.3.0 aviator:sync-permissions', 'v1.3.0 optimize', 'current up', 'current aviator:check-environment', 'v1.0.0 db:seed --force')
        ->and($calls)->not->toContain('v1.1.0 db:seed')
        ->and(glob($home.'/backups/recette_*_avant_v1.3.0.sql.tar.gz'))->not->toBeEmpty();

    // 4. Empreinte fausse : refus avant toute modification.
    $tampered = $buildArchive('v1.4.0');
    file_put_contents($tampered.'.sha256', str_repeat('0', 64).'  x'.PHP_EOL);
    $bad = runBash("bash {$install} recette ".escapeshellarg($tampered), $env);
    expect($bad->isSuccessful())->toBeFalse()
        ->and($bad->getErrorOutput())->toContain('Empreinte différente')
        ->and(readlink($home.'/apps/recette/current'))->toBe('releases/v1.3.0');

    // 5. Retour arrière : v1.2.0, avec le rappel sur les migrations.
    $back = runBash("bash {$rollback} recette", $env, "OUI\n");
    expect($back->isSuccessful())->toBeTrue($back->getErrorOutput())
        ->and(readlink($home.'/apps/recette/current'))->toBe('releases/v1.2.0')
        ->and($back->getOutput())->toContain('migrations', 'ne sont pas annulées');

    // 6. Production : refus d'une version non étiquetée, puis confirmation exigée.
    file_put_contents($home.'/.my.prod.cnf', "[client]\nuser=test\npassword=test\n");
    mkdir($home.'/apps/prod/shared', 0777, true);
    file_put_contents($home.'/apps/prod/shared/.env', "DB_DATABASE=aviator_prod\n");
    $refused = runBash("bash {$install} prod ".escapeshellarg($home.'/aviator-v1.3.0.tar.gz'), $env, "non\n");
    expect($refused->isSuccessful())->toBeFalse()
        ->and($refused->getErrorOutput())->toContain('Confirmation refusée')
        ->and(file_exists($home.'/apps/prod/current'))->toBeFalse();
});
