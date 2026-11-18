<?php

declare(strict_types=1);

namespace App\Support\Environment;

use App\Settings\NotificationSettings;
use App\Support\Scheduling\SchedulerHeartbeat;
use Throwable;

/**
 * Contrôle de l'environnement (Q8, Q71, D14, D20, D21, D23), utilisé par
 * « aviator:check-environment ». Chaque contrôle produit une ligne OK, AVERTISSEMENT
 * ou ERREUR, avec une action conseillée. Aucune valeur de clé n'est jamais restituée.
 */
final class EnvironmentChecker
{
    /** Extensions exigées par Laravel 13 et les paquets retenus (docs/compatibilite_paquets.md §4). */
    public const REQUIRED_EXTENSIONS = [
        'bcmath', 'ctype', 'curl', 'dom', 'fileinfo', 'filter', 'gd', 'hash', 'iconv', 'intl', 'json',
        'libxml', 'mbstring', 'openssl', 'pcre', 'pdo_mysql', 'session', 'simplexml', 'tokenizer', 'xml',
        'xmlreader', 'xmlwriter', 'zip', 'zlib',
    ];

    public const RECOMMENDED_EXTENSIONS = ['exif', 'gmp', 'sodium'];

    /** Connexions MariaDB en dessous desquelles on avertit (une connexion de plus pour les séquences). */
    public const MIN_RECOMMENDED_CONNECTIONS = 10;

    /** Dossiers que l'application doit pouvoir écrire. */
    public const WRITABLE_PATHS = [
        'storage/app/private',
        'storage/framework/cache',
        'storage/framework/sessions',
        'storage/framework/views',
        'storage/logs',
        'bootstrap/cache',
    ];

    public function __construct(
        private readonly EnvironmentProbe $probe,
        private readonly SchedulerHeartbeat $heartbeat,
        private readonly NotificationSettings $notifications,
    ) {}

    /**
     * @return list<CheckResult>
     */
    public function run(): array
    {
        return [
            ...$this->php(),
            ...$this->database(),
            ...$this->scheduler(),
            ...$this->keys(),
            ...$this->application(),
            ...$this->filesystem(),
            ...$this->mail(),
        ];
    }

    /**
     * @return list<CheckResult>
     */
    private function php(): array
    {
        $results = [];
        $version = $this->probe->phpVersion();

        $results[] = match (true) {
            str_starts_with($version, '8.3.') => CheckResult::ok('Version de PHP', $version),
            version_compare($version, '8.4.0', '>=') => CheckResult::warning('Version de PHP', $version, 'Le projet cible PHP 8.3 (config.platform.php = 8.3.16) : choisir PHP 8.3 dans PHP Selector, ou valider la montée de version.'),
            default => CheckResult::error('Version de PHP', $version, 'Choisir PHP 8.3 dans cPanel → Sélection de la version de PHP.'),
        };

        $loaded = $this->probe->loadedExtensions();
        $missing = array_values(array_diff(self::REQUIRED_EXTENSIONS, $loaded));
        $results[] = $missing === []
            ? CheckResult::ok('Extensions obligatoires', count(self::REQUIRED_EXTENSIONS).' présentes')
            : CheckResult::error('Extensions obligatoires', 'Manquantes : '.implode(', ', $missing), 'Activer dans PHP Selector → Extensions : '.implode(', ', $missing).'.');

        $missingRecommended = array_values(array_diff(self::RECOMMENDED_EXTENSIONS, $loaded));
        $results[] = $missingRecommended === []
            ? CheckResult::ok('Extensions recommandées', implode(', ', self::RECOMMENDED_EXTENSIONS))
            : CheckResult::warning('Extensions recommandées', 'Manquantes : '.implode(', ', $missingRecommended), 'Activer si possible (exif : orientation des photos ; gmp, sodium : notifications push).');

        sort($loaded);
        $results[] = CheckResult::ok('Extensions chargées (liste complète)', implode(', ', $loaded));

        $disabled = $this->probe->disabledFunctions();
        $results[] = CheckResult::ok('Fonctions désactivées (disable_functions)', $disabled === [] ? 'Aucune' : implode(', ', $disabled));

        foreach (['exec' => 'D14', 'proc_open' => 'D14', 'symlink' => 'D21'] as $function => $decision) {
            $results[] = CheckResult::ok(
                "Fonction {$function}",
                $this->probe->functionAvailable($function) ? 'Disponible' : 'Désactivée',
                "Aucune action : l'application n'en dépend pas ({$decision}).",
            );
        }

        $dump = $this->probe->executableInPath('mysqldump') ?? $this->probe->executableInPath('mariadb-dump');
        $results[] = $dump !== null
            ? CheckResult::ok('mysqldump dans le PATH', 'Trouvé : '.basename($dump))
            : CheckResult::warning('mysqldump dans le PATH', 'Introuvable dans le PATH de PHP', 'Vérifier dans le terminal SSH (« which mysqldump ») : deploy.sh l\'utilise pour la sauvegarde avant déploiement.');

        return $results;
    }

    /**
     * @return list<CheckResult>
     */
    private function database(): array
    {
        $driver = (string) config('database.connections.'.config('database.default').'.driver');

        if ($driver !== 'mariadb') {
            return [CheckResult::error('Connexion à la base', "Pilote « {$driver} »", 'Mettre DB_CONNECTION=mariadb dans .env.')];
        }

        $vars = $this->probe->databaseVariables();

        if ($vars === null) {
            return [CheckResult::error('Connexion à la base', 'Connexion impossible', 'Vérifier DB_HOST, DB_DATABASE, DB_USERNAME et DB_PASSWORD dans .env, et les droits de l\'utilisateur dans cPanel.')];
        }

        $results = [];
        $version = $vars['version'];
        $results[] = match (true) {
            str_contains($version, 'MariaDB') && str_starts_with($version, '10.11.') => CheckResult::ok('Version de la base', $version),
            str_contains($version, 'MariaDB') => CheckResult::warning('Version de la base', $version, 'MariaDB 10.11 attendue (production Oxahost : 10.11.19) : faire confirmer la version par Oxahost.'),
            default => CheckResult::error('Version de la base', $version, 'MariaDB 10.11 attendue (D12) : vérifier le serveur de base de données.'),
        };

        foreach (['collation_database' => 'Interclassement de la base', 'collation_connection' => 'Interclassement de la connexion'] as $key => $label) {
            $results[] = $vars[$key] === 'utf8mb4_unicode_ci'
                ? CheckResult::ok($label, $vars[$key])
                : CheckResult::error($label, $vars[$key], 'ALTER DATABASE <base> CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; et DB_COLLATION=utf8mb4_unicode_ci dans .env.');
        }

        $results[] = CheckResult::ok('Fuseau horaire de la base', "time_zone={$vars['time_zone']}, système={$vars['system_time_zone']}", 'Information : les dates sont gérées par l\'application (Africa/Tunis).');

        $limits = array_filter([$vars['max_connections'], $vars['max_user_connections']], fn (int $value): bool => $value > 0);
        $limit = $limits === [] ? null : min($limits);
        $finding = "max_connections={$vars['max_connections']}, max_user_connections={$vars['max_user_connections']}";
        $results[] = match (true) {
            $limit === null => CheckResult::ok('Connexions simultanées autorisées', $finding.' (illimité)'),
            $limit < 2 => CheckResult::error('Connexions simultanées autorisées', $finding, 'L\'application ouvre jusqu\'à 2 connexions (dont celle des séquences) : demander à Oxahost d\'augmenter max_user_connections.'),
            $limit < self::MIN_RECOMMENDED_CONNECTIONS => CheckResult::warning('Connexions simultanées autorisées', $finding, 'Limite inférieure à 10 : risque de refus aux heures chargées (une connexion de plus pour les séquences). Demander une limite d\'au moins 10 à Oxahost.'),
            default => CheckResult::ok('Connexions simultanées autorisées', $finding),
        };

        $results[] = $this->probe->autocommitConnectionWorks()
            ? CheckResult::ok('Connexion des séquences (mariadb_autocommit)', 'Disponible')
            : CheckResult::error('Connexion des séquences (mariadb_autocommit)', 'Connexion impossible', 'Elle utilise les mêmes paramètres DB_* que la connexion principale : vérifier .env et la limite de connexions.');

        return $results;
    }

    /**
     * @return list<CheckResult>
     */
    private function scheduler(): array
    {
        $cron = '* * * * * cd ~/apps/<env> && <php 8.3> artisan schedule:run >> /dev/null 2>&1';

        // Le battement est lu dans le cache : un cache mal configuré ne doit pas faire
        // échouer le diagnostic, il doit être signalé.
        try {
            $since = $this->heartbeat->secondsSinceLastRun();
            $interval = $this->heartbeat->measuredInterval();
        } catch (Throwable $exception) {
            return [CheckResult::error('Planificateur (cron)', 'Battement illisible : cache indisponible ('.class_basename($exception).')', 'Vérifier CACHE_STORE=database dans .env, puis relancer ce contrôle.')];
        }

        if ($since === null) {
            return [CheckResult::error('Planificateur (cron)', 'Aucun passage enregistré', "Créer la tâche cron dans cPanel : {$cron}, puis relancer ce contrôle après 2 minutes.")];
        }

        if ($since > 600) {
            return [CheckResult::error('Planificateur (cron)', "Dernier passage il y a {$since} s", "Le cron ne tourne plus : vérifier la tâche cron dans cPanel ({$cron}).")];
        }

        if ($interval === null) {
            return [CheckResult::warning('Planificateur (cron)', "Un seul passage (il y a {$since} s)", 'Relancer ce contrôle dans 2 minutes pour mesurer la cadence.')];
        }

        if ($interval <= 90) {
            return [CheckResult::ok('Planificateur (cron)', "Cadence mesurée : {$interval} s (chaque minute)")];
        }

        $advice = $this->notifications->send_urgent_in_request
            ? 'Mode dégradé (D20) : notifications urgentes déjà envoyées pendant la requête. Demander à Oxahost un cron chaque minute.'
            : 'Mode dégradé (D20) : activer le réglage notifications.send_urgent_in_request, et demander à Oxahost un cron chaque minute.';

        return [CheckResult::warning('Planificateur (cron)', "Cadence mesurée : {$interval} s", $advice)];
    }

    /**
     * @return list<CheckResult>
     */
    private function keys(): array
    {
        $results = [];
        $appKey = (string) config('app.key');
        $decoded = str_starts_with($appKey, 'base64:') ? base64_decode(substr($appKey, 7), true) : false;

        $results[] = match (true) {
            $appKey === '' => CheckResult::error('Clé APP_KEY', 'Absente ou vide', 'Première installation seulement : php artisan key:generate, puis copie chez Aviator (docs/gestion_des_cles.md). Sinon, restaurer la clé conservée : ne jamais en générer une nouvelle.'),
            $decoded !== false && strlen($decoded) === 32 => CheckResult::ok('Clé APP_KEY', 'Présente, format valide (valeur non affichée)'),
            default => CheckResult::error('Clé APP_KEY', 'Présente mais format invalide (valeur non affichée)', 'Restaurer la clé conservée chez Aviator (docs/gestion_des_cles.md).'),
        };

        $indexKey = (string) config('aviator.blind_index_key');
        $results[] = match (true) {
            $indexKey === '' => CheckResult::error('Clé AVIATOR_BLIND_INDEX_KEY', 'Absente ou vide', 'Première installation seulement : générer 64 caractères hexadécimaux (docs/gestion_des_cles.md §5). Sinon, restaurer la clé conservée.'),
            preg_match('/^[0-9a-f]{64}$/', $indexKey) === 1 => CheckResult::ok('Clé AVIATOR_BLIND_INDEX_KEY', 'Présente, format valide (valeur non affichée)'),
            strlen($indexKey) >= 32 => CheckResult::warning('Clé AVIATOR_BLIND_INDEX_KEY', 'Présente, format non standard (valeur non affichée)', '64 caractères hexadécimaux recommandés (D23) ; tout changement impose aviator:rehash-blind-index.'),
            default => CheckResult::error('Clé AVIATOR_BLIND_INDEX_KEY', 'Trop courte (valeur non affichée)', 'Restaurer la clé conservée chez Aviator, ou suivre la procédure de changement (docs/gestion_des_cles.md §3.2).'),
        };

        $results[] = CheckResult::ok('Clés VAPID (notifications push)', 'Non requises avant la phase 9');

        return $results;
    }

    /**
     * @return list<CheckResult>
     */
    private function application(): array
    {
        $results = [];
        $production = app()->environment('production');
        $env = (string) config('app.env');

        $results[] = $production
            ? CheckResult::ok('APP_ENV', $env)
            : CheckResult::warning('APP_ENV', $env, 'Sur la recette et la production : APP_ENV=production dans .env.');

        $debug = (bool) config('app.debug');
        $results[] = match (true) {
            ! $debug => CheckResult::ok('APP_DEBUG', 'false'),
            $production => CheckResult::error('APP_DEBUG', 'true', 'Mettre APP_DEBUG=false dans .env : les erreurs afficheraient des informations sensibles.'),
            default => CheckResult::warning('APP_DEBUG', 'true', 'Acceptable sur le poste de développement uniquement ; APP_DEBUG=false sur la recette et la production.'),
        };

        $url = (string) config('app.url');
        $results[] = match (true) {
            str_starts_with($url, 'https://') => CheckResult::ok('HTTPS (APP_URL)', $url),
            $production => CheckResult::error('HTTPS (APP_URL)', $url, 'Installer le certificat SSL du sous-domaine dans cPanel et mettre APP_URL=https://… dans .env.'),
            default => CheckResult::warning('HTTPS (APP_URL)', $url, 'APP_URL doit commencer par https:// sur la recette et la production.'),
        };

        $results[] = CheckResult::ok('Fuseau horaire de l\'application', (string) config('app.timezone').' (PHP date.timezone : '.($this->probe->phpTimezoneSetting() ?: 'non défini').')');

        foreach (['queue.default' => 'File (QUEUE_CONNECTION)', 'cache.default' => 'Cache (CACHE_STORE)', 'session.driver' => 'Sessions (SESSION_DRIVER)'] as $key => $label) {
            $driver = (string) config($key);
            // Seuls les pilotes locaux sont admis : tout autre (Redis, service externe…) est une erreur.
            $results[] = match ($driver) {
                'database' => CheckResult::ok($label, $driver),
                'file', 'array', 'sync' => CheckResult::warning($label, $driver, '« database » attendu sur la recette et la production.'),
                default => CheckResult::error($label, $driver, 'Hébergement mutualisé : mettre « database » dans .env (pas de Redis ni de service externe).'),
            };
        }

        return $results;
    }

    /**
     * @return list<CheckResult>
     */
    private function filesystem(): array
    {
        $results = [];

        foreach (self::WRITABLE_PATHS as $path) {
            $results[] = $this->probe->isWritable(base_path($path))
                ? CheckResult::ok("Écriture : {$path}", 'Accessible en écriture')
                : CheckResult::error("Écriture : {$path}", 'Non accessible en écriture', "Créer le dossier si besoin et donner les droits d'écriture (chmod 775 {$path}).");
        }

        return $results;
    }

    /**
     * @return list<CheckResult>
     */
    private function mail(): array
    {
        $production = app()->environment('production');
        $mailer = (string) config('mail.default');
        $from = (string) config('mail.from.address');
        $results = [];

        $results[] = match (true) {
            $mailer === 'smtp' && (string) config('mail.mailers.smtp.host') === '' => CheckResult::error('Envoi des e-mails (MAIL_MAILER)', 'smtp sans serveur', 'Renseigner MAIL_HOST, MAIL_PORT, MAIL_USERNAME et MAIL_PASSWORD (compte e-mail cPanel).'),
            $mailer === 'smtp' => CheckResult::ok('Envoi des e-mails (MAIL_MAILER)', 'smtp ('.(string) config('mail.mailers.smtp.host').')'),
            $production => CheckResult::warning('Envoi des e-mails (MAIL_MAILER)', $mailer, 'Les liens de réinitialisation du mot de passe ne partiront pas : configurer le compte e-mail cPanel (MAIL_MAILER=smtp).'),
            default => CheckResult::ok('Envoi des e-mails (MAIL_MAILER)', $mailer.' (poste de développement)'),
        };

        $results[] = $from === '' || str_ends_with($from, '.invalid') || str_ends_with($from, 'example.com')
            ? ($production
                ? CheckResult::warning('Adresse d\'expédition (MAIL_FROM_ADDRESS)', $from === '' ? 'Vide' : $from, 'Utiliser une adresse du domaine d\'Aviator, créée dans cPanel.')
                : CheckResult::ok('Adresse d\'expédition (MAIL_FROM_ADDRESS)', $from.' (poste de développement)'))
            : CheckResult::ok('Adresse d\'expédition (MAIL_FROM_ADDRESS)', $from);

        return $results;
    }
}
