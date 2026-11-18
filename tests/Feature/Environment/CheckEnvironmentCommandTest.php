<?php

use App\Settings\NotificationSettings;
use App\Support\Environment\CheckResult;
use App\Support\Environment\CheckStatus;
use App\Support\Environment\EnvironmentChecker;
use App\Support\Environment\EnvironmentProbe;
use App\Support\Scheduling\SchedulerHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Environment\FakeEnvironmentProbe;

uses(RefreshDatabase::class);

const TEST_APP_KEY = 'base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';
const TEST_INDEX_KEY = 'aa11bb22cc33dd44ee55ff66aa11bb22cc33dd44ee55ff66aa11bb22cc33dd44';

/**
 * Serveur de recette conforme : production, HTTPS, clés valides, e-mail SMTP, cron
 * chaque minute. Chaque test dégrade un seul élément.
 */
beforeEach(function () {
    $this->probe = new FakeEnvironmentProbe;
    app()->instance(EnvironmentProbe::class, $this->probe);

    app()->detectEnvironment(fn (): string => 'production');
    config([
        'app.env' => 'production',
        'app.debug' => false,
        'app.url' => 'https://recette.aviator.example',
        'app.key' => TEST_APP_KEY,
        'aviator.blind_index_key' => TEST_INDEX_KEY,
        'queue.default' => 'database',
        'cache.default' => 'database',
        'session.driver' => 'database',
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'mail.aviator.example',
        'mail.from.address' => 'noreply@aviator.example',
    ]);

    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00', 'Africa/Tunis'));
    app(SchedulerHeartbeat::class)->beat();
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:01:00', 'Africa/Tunis'));
    app(SchedulerHeartbeat::class)->beat();
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:01:20', 'Africa/Tunis'));
});

function checkResult(string $check): CheckResult
{
    foreach (app(EnvironmentChecker::class)->run() as $result) {
        if ($result->check === $check) {
            return $result;
        }
    }

    throw new RuntimeException("Contrôle introuvable : {$check}");
}

// --- Commande -------------------------------------------------------------------

it('réussit sur un serveur conforme, sans aucune erreur', function () {
    $this->artisan('aviator:check-environment')
        ->expectsOutputToContain('0 erreur(s)')
        ->assertSuccessful();

    $statuses = array_map(fn (CheckResult $r): CheckStatus => $r->status, app(EnvironmentChecker::class)->run());

    expect($statuses)->not->toContain(CheckStatus::Error);
});

it('renvoie un code non nul dès qu\'une erreur est détectée', function () {
    $this->probe->autocommit = false;

    $this->artisan('aviator:check-environment')
        ->expectsOutputToContain('1 erreur(s)')
        ->assertFailed();
});

it('n\'affiche jamais la valeur des clés', function () {
    $this->artisan('aviator:check-environment')
        ->doesntExpectOutputToContain(TEST_APP_KEY)
        ->doesntExpectOutputToContain(substr(TEST_APP_KEY, 7, 20))
        ->doesntExpectOutputToContain(TEST_INDEX_KEY)
        ->doesntExpectOutputToContain(substr(TEST_INDEX_KEY, 0, 16))
        ->assertSuccessful();
});

it('donne une action conseillée pour chaque avertissement et chaque erreur', function () {
    $this->probe->php = '8.2.10';
    $this->probe->extensions = ['ctype'];
    config(['app.debug' => true]);

    foreach (app(EnvironmentChecker::class)->run() as $result) {
        if ($result->status !== CheckStatus::Ok) {
            expect($result->advice)->not->toBe('—', "Pas d'action pour « {$result->check} »");
        }
    }
});

// --- PHP --------------------------------------------------------------------------

it('contrôle la version de PHP', function (string $version, CheckStatus $expected) {
    $this->probe->php = $version;

    expect(checkResult('Version de PHP')->status)->toBe($expected);
})->with([
    ['8.3.16', CheckStatus::Ok],
    ['8.4.2', CheckStatus::Warning],
    ['8.2.10', CheckStatus::Error],
]);

it('signale les extensions obligatoires manquantes et conseille de les activer', function () {
    $this->probe->extensions = array_values(array_diff($this->probe->extensions, ['intl', 'zip']));

    $result = checkResult('Extensions obligatoires');

    expect($result->status)->toBe(CheckStatus::Error)
        ->and($result->finding)->toContain('intl', 'zip')
        ->and($result->advice)->toContain('PHP Selector');
});

it('avertit si une extension recommandée manque', function () {
    $this->probe->extensions = array_values(array_diff($this->probe->extensions, ['exif']));

    expect(checkResult('Extensions recommandées')->status)->toBe(CheckStatus::Warning);
});

it('liste disable_functions et indique que exec, proc_open et symlink ne sont pas nécessaires', function () {
    $this->probe->disabled = ['exec', 'proc_open', 'symlink', 'shell_exec'];

    expect(checkResult('Fonctions désactivées (disable_functions)')->finding)->toBe('exec, proc_open, symlink, shell_exec')
        ->and(checkResult('Fonction exec')->status)->toBe(CheckStatus::Ok)
        ->and(checkResult('Fonction exec')->finding)->toBe('Désactivée')
        ->and(checkResult('Fonction symlink')->advice)->toContain('D21');
});

it('confirme que les fonctions désactivées chez Oxahost sont attendues (D14, D25)', function () {
    $this->probe->disabled = ['exec', 'proc_open', 'shell_exec', 'system', 'passthru'];

    foreach (['exec', 'proc_open', 'shell_exec', 'system', 'passthru'] as $function) {
        expect(checkResult("Fonction {$function}")->status)->toBe(CheckStatus::Ok)
            ->and(checkResult("Fonction {$function}")->finding)->toBe('Désactivée')
            ->and(checkResult("Fonction {$function}")->advice)->toContain('Attendu chez Oxahost', 'D14');
    }

    expect(checkResult('Fonction symlink')->finding)->toBe('Disponible');
});

it('conseille le terminal web de cPanel et install-release.sh si mysqldump manque (D25)', function () {
    $this->probe->mysqldump = null;

    expect(checkResult('mysqldump dans le PATH')->advice)->toContain('terminal web de cPanel', 'install-release.sh')
        ->not->toContain('SSH');
});

it('avertit si mysqldump est introuvable', function () {
    $this->probe->mysqldump = null;

    expect(checkResult('mysqldump dans le PATH')->status)->toBe(CheckStatus::Warning);
});

// --- Base -------------------------------------------------------------------------

it('contrôle la version de la base', function (string $version, CheckStatus $expected) {
    $this->probe->database['version'] = $version;

    expect(checkResult('Version de la base')->status)->toBe($expected);
})->with([
    ['10.11.19-MariaDB-cll-lve', CheckStatus::Ok],
    ['10.6.18-MariaDB', CheckStatus::Warning],
    ['8.0.36', CheckStatus::Error],
]);

it('exige utf8mb4_unicode_ci pour la base et la connexion', function () {
    $this->probe->database['collation_database'] = 'latin1_swedish_ci';

    expect(checkResult('Interclassement de la base')->status)->toBe(CheckStatus::Error)
        ->and(checkResult('Interclassement de la connexion')->status)->toBe(CheckStatus::Ok);
});

it('contrôle les limites de connexions simultanées', function (int $max, int $maxUser, CheckStatus $expected) {
    $this->probe->database['max_connections'] = $max;
    $this->probe->database['max_user_connections'] = $maxUser;

    expect(checkResult('Connexions simultanées autorisées')->status)->toBe($expected);
})->with([
    'illimité par utilisateur' => [151, 0, CheckStatus::Ok],
    '25 par utilisateur' => [151, 25, CheckStatus::Ok],
    'moins de 10 par utilisateur' => [151, 5, CheckStatus::Warning],
    'moins de 10 au total' => [8, 0, CheckStatus::Warning],
    'une seule connexion' => [151, 1, CheckStatus::Error],
]);

it('signale une connexion des séquences indisponible', function () {
    $this->probe->autocommit = false;

    expect(checkResult('Connexion des séquences (mariadb_autocommit)')->status)->toBe(CheckStatus::Error);
});

it('signale une base injoignable', function () {
    $this->probe->database = null;

    expect(checkResult('Connexion à la base')->status)->toBe(CheckStatus::Error);
});

// --- Planificateur ----------------------------------------------------------------

it('signale un cron absent', function () {
    cache()->forget(SchedulerHeartbeat::CACHE_KEY);

    $result = checkResult('Planificateur (cron)');

    expect($result->status)->toBe(CheckStatus::Error)
        ->and($result->advice)->toContain('schedule:run');
});

it('signale un cron arrêté depuis plus de 10 minutes', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:20:00', 'Africa/Tunis'));

    expect(checkResult('Planificateur (cron)')->status)->toBe(CheckStatus::Error);
});

it('avertit en mode dégradé (cron toutes les 5 minutes) et conseille le réglage des notifications urgentes', function () {
    cache()->forget(SchedulerHeartbeat::CACHE_KEY);
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00', 'Africa/Tunis'));
    app(SchedulerHeartbeat::class)->beat();
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:05:00', 'Africa/Tunis'));
    app(SchedulerHeartbeat::class)->beat();

    $result = checkResult('Planificateur (cron)');

    expect($result->status)->toBe(CheckStatus::Warning)
        ->and($result->finding)->toContain('300 s')
        ->and($result->advice)->toContain('send_urgent_in_request');

    $settings = app(NotificationSettings::class);
    $settings->send_urgent_in_request = true;
    $settings->save();

    expect(checkResult('Planificateur (cron)')->advice)->toContain('déjà envoyées pendant la requête');
});

// --- Clés -------------------------------------------------------------------------

it('contrôle la présence et le format des clés, sans afficher leur valeur', function (string $appKey, string $indexKey, CheckStatus $app, CheckStatus $index) {
    config(['app.key' => $appKey, 'aviator.blind_index_key' => $indexKey]);

    $appResult = checkResult('Clé APP_KEY');
    $indexResult = checkResult('Clé AVIATOR_BLIND_INDEX_KEY');

    expect($appResult->status)->toBe($app)
        ->and($indexResult->status)->toBe($index)
        ->and($appResult->finding.$appResult->advice)->not->toContain($appKey === '' ? 'base64:' : $appKey)
        ->and($indexResult->finding.$indexResult->advice)->not->toContain($indexKey === '' ? '§§§' : $indexKey);
})->with([
    'conformes' => [TEST_APP_KEY, TEST_INDEX_KEY, CheckStatus::Ok, CheckStatus::Ok],
    'vides' => ['', '', CheckStatus::Error, CheckStatus::Error],
    'APP_KEY mal formée' => ['base64:Y291cnRl', TEST_INDEX_KEY, CheckStatus::Error, CheckStatus::Ok],
    'clé d\'index non hexadécimale' => [TEST_APP_KEY, str_repeat('Zz', 20), CheckStatus::Ok, CheckStatus::Warning],
    'clé d\'index trop courte' => [TEST_APP_KEY, 'k3y-x9', CheckStatus::Ok, CheckStatus::Error],
]);

// --- Application ------------------------------------------------------------------

it('refuse APP_DEBUG=true en production', function () {
    config(['app.debug' => true]);

    expect(checkResult('APP_DEBUG')->status)->toBe(CheckStatus::Error);
});

it('exige HTTPS en production', function () {
    config(['app.url' => 'http://recette.aviator.example']);

    expect(checkResult('HTTPS (APP_URL)')->status)->toBe(CheckStatus::Error);
});

it('avertit si APP_ENV n\'est pas production', function () {
    app()->detectEnvironment(fn (): string => 'local');
    config(['app.env' => 'local']);

    expect(checkResult('APP_ENV')->status)->toBe(CheckStatus::Warning);
});

it('refuse Redis ou un service externe pour le cache, la file ou les sessions, sans planter', function () {
    config(['cache.default' => 'redis']);

    $cron = checkResult('Planificateur (cron)');

    expect(checkResult('Cache (CACHE_STORE)')->status)->toBe(CheckStatus::Error)
        // Le battement, lu dans un cache indisponible, est signalé au lieu de faire échouer la commande.
        ->and($cron->status)->toBe(CheckStatus::Error)
        ->and($cron->advice)->toContain('CACHE_STORE=database');
});

it('signale un dossier non accessible en écriture', function () {
    $this->probe->notWritable = ['storage/app/private'];

    $result = checkResult('Écriture : storage/app/private');

    expect($result->status)->toBe(CheckStatus::Error)
        ->and($result->advice)->toContain('chmod');
});

it('avertit si les e-mails de sécurité ne peuvent pas partir en production', function () {
    config(['mail.default' => 'log']);

    expect(checkResult('Envoi des e-mails (MAIL_MAILER)')->status)->toBe(CheckStatus::Warning);
});

it('signale un serveur SMTP non renseigné', function () {
    config(['mail.mailers.smtp.host' => '']);

    expect(checkResult('Envoi des e-mails (MAIL_MAILER)')->status)->toBe(CheckStatus::Error);
});

it('fonctionne aussi avec la vraie sonde, sans rien modifier ni planter', function () {
    app()->forgetInstance(EnvironmentProbe::class);
    app()->bind(EnvironmentProbe::class, EnvironmentProbe::class);

    $results = app(EnvironmentChecker::class)->run();

    expect($results)->not->toBeEmpty()
        ->and(collect($results)->pluck('check'))->toContain('Version de PHP', 'Version de la base', 'Planificateur (cron)');
});
