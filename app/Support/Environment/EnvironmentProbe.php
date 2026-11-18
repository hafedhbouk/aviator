<?php

declare(strict_types=1);

namespace App\Support\Environment;

use App\Support\Sequences\SequenceAllocator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Recueille les faits de l'environnement (PHP, base, système de fichiers), sans
 * lancer de commande système (D14) et sans rien modifier, sauf un fichier témoin
 * d'écriture aussitôt supprimé. Les tests la remplacent par une sonde factice.
 */
class EnvironmentProbe
{
    public function phpVersion(): string
    {
        return PHP_VERSION;
    }

    /**
     * @return list<string> Extensions chargées, en minuscules
     */
    public function loadedExtensions(): array
    {
        return array_map('strtolower', get_loaded_extensions());
    }

    /**
     * @return list<string> Fonctions listées dans disable_functions
     */
    public function disabledFunctions(): array
    {
        $raw = (string) ini_get('disable_functions');

        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn (string $f): bool => $f !== ''));
    }

    public function functionAvailable(string $function): bool
    {
        return function_exists($function) && ! in_array($function, $this->disabledFunctions(), true);
    }

    public function phpTimezoneSetting(): string
    {
        return (string) ini_get('date.timezone');
    }

    /**
     * Variables techniques de MariaDB (aucune donnée métier). Lecture par la connexion,
     * pas par DB::select (règle de CLAUDE.md sur les lectures hors Eloquent).
     *
     * @return array{version: string, collation_database: string, collation_connection: string, time_zone: string, system_time_zone: string, max_connections: int, max_user_connections: int}|null
     */
    public function databaseVariables(): ?array
    {
        try {
            $row = DB::connection()->selectOne(
                'SELECT VERSION() AS version, @@collation_database AS collation_database,
                        @@collation_connection AS collation_connection, @@time_zone AS time_zone,
                        @@system_time_zone AS system_time_zone, @@GLOBAL.max_connections AS max_connections,
                        @@SESSION.max_user_connections AS max_user_connections'
            );
        } catch (Throwable) {
            return null;
        }

        if (! is_object($row)) {
            return null;
        }

        return [
            'version' => (string) $row->version,
            'collation_database' => (string) $row->collation_database,
            'collation_connection' => (string) $row->collation_connection,
            'time_zone' => (string) $row->time_zone,
            'system_time_zone' => (string) $row->system_time_zone,
            'max_connections' => (int) $row->max_connections,
            'max_user_connections' => (int) $row->max_user_connections,
        ];
    }

    public function autocommitConnectionWorks(): bool
    {
        try {
            DB::connection(SequenceAllocator::AUTOCOMMIT_CONNECTION)->selectOne('SELECT 1 AS ok');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Cherche un exécutable dans les dossiers du PATH (sans lancer de commande).
     */
    public function executableInPath(string $name): ?string
    {
        $path = (string) getenv('PATH');
        $suffixes = PHP_OS_FAMILY === 'Windows' ? ['.exe', '.bat', ''] : [''];

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            foreach ($suffixes as $suffix) {
                $candidate = rtrim($directory, '\\/').DIRECTORY_SEPARATOR.$name.$suffix;

                if ($directory !== '' && @is_file($candidate) && @is_executable($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Écrit puis supprime un fichier témoin dans le dossier.
     */
    public function isWritable(string $directory): bool
    {
        if (! is_dir($directory)) {
            return false;
        }

        $file = rtrim($directory, '\\/').DIRECTORY_SEPARATOR.'.aviator-check-'.Str::random(12);

        if (@file_put_contents($file, 'ok') === false) {
            return false;
        }

        @unlink($file);

        return true;
    }
}
