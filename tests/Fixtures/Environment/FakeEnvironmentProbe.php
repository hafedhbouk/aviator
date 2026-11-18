<?php

namespace Tests\Fixtures\Environment;

use App\Support\Environment\EnvironmentChecker;
use App\Support\Environment\EnvironmentProbe;

/**
 * Sonde factice : décrit par défaut un serveur conforme (PHP 8.3, MariaDB 10.11,
 * toutes les extensions, dossiers accessibles en écriture). Chaque test modifie
 * seulement ce qu'il éprouve.
 */
class FakeEnvironmentProbe extends EnvironmentProbe
{
    public string $php = '8.3.16';

    /** @var list<string> */
    public array $extensions;

    /** @var list<string> */
    public array $disabled = [];

    /** @var array{version: string, collation_database: string, collation_connection: string, time_zone: string, system_time_zone: string, max_connections: int, max_user_connections: int}|null */
    public ?array $database = [
        'version' => '10.11.19-MariaDB-cll-lve',
        'collation_database' => 'utf8mb4_unicode_ci',
        'collation_connection' => 'utf8mb4_unicode_ci',
        'time_zone' => 'SYSTEM',
        'system_time_zone' => 'CET',
        'max_connections' => 151,
        'max_user_connections' => 25,
    ];

    public bool $autocommit = true;

    public ?string $mysqldump = '/usr/bin/mysqldump';

    /** @var list<string> */
    public array $notWritable = [];

    public function __construct()
    {
        $this->extensions = [...EnvironmentChecker::REQUIRED_EXTENSIONS, ...EnvironmentChecker::RECOMMENDED_EXTENSIONS];
    }

    public function phpVersion(): string
    {
        return $this->php;
    }

    public function loadedExtensions(): array
    {
        return $this->extensions;
    }

    public function disabledFunctions(): array
    {
        return $this->disabled;
    }

    public function functionAvailable(string $function): bool
    {
        return ! in_array($function, $this->disabled, true);
    }

    public function phpTimezoneSetting(): string
    {
        return 'Africa/Tunis';
    }

    public function databaseVariables(): ?array
    {
        return $this->database;
    }

    public function autocommitConnectionWorks(): bool
    {
        return $this->autocommit;
    }

    public function executableInPath(string $name): ?string
    {
        return $name === 'mysqldump' ? $this->mysqldump : null;
    }

    public function isWritable(string $directory): bool
    {
        foreach ($this->notWritable as $path) {
            if (str_ends_with(str_replace('\\', '/', $directory), $path)) {
                return false;
            }
        }

        return true;
    }
}
