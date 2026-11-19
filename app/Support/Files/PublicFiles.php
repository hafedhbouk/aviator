<?php

declare(strict_types=1);

namespace App\Support\Files;

use App\Models\Company;

/**
 * Fichiers publics de l'application (D21) : sans lien symbolique, ils restent sur le
 * disque privé et ne sont servis que par la route « public-files.show », en lecture
 * seule. Un fichier n'est servi que s'il remplit toutes les conditions :
 *
 * - il est dans la liste blanche (aujourd'hui : le logo enregistré sur la fiche société) ;
 * - son chemin commence par DIRECTORY, sans « .. » ni barre oblique inverse ;
 * - son extension et son type réel (lu dans le contenu) sont des images PNG, JPEG ou WebP.
 *
 * Un document client (pièce, permis, contrat) n'est donc jamais servi ici, même si
 * son chemin était recopié sur la fiche société.
 */
final class PublicFiles
{
    public const DISK = 'private';

    public const DIRECTORY = 'public-files';

    public const COMPANY_DIRECTORY = self::DIRECTORY.'/company';

    /** Extensions servies et type de contenu envoyé. SVG exclu (scripts possibles). */
    public const TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
    ];

    /**
     * Le chemin a-t-il une forme acceptable pour un fichier public ?
     */
    public static function hasAllowedShape(string $path): bool
    {
        if (! str_starts_with($path, self::DIRECTORY.'/')
            || str_contains($path, '..')
            || str_contains($path, '\\')
            || str_contains($path, "\0")) {
            return false;
        }

        return self::contentType($path) !== null;
    }

    /**
     * Type de contenu attendu d'après l'extension, ou null si l'extension n'est pas servie.
     */
    public static function contentType(string $path): ?string
    {
        return self::TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
    }

    /**
     * Le chemin figure-t-il dans la liste blanche des fichiers publics ?
     */
    public static function isListed(string $path): bool
    {
        return in_array($path, self::listed(), true);
    }

    /**
     * @return list<string>
     */
    public static function listed(): array
    {
        $logo = Company::current()?->logo_path;

        return $logo !== null && self::hasAllowedShape($logo) ? [$logo] : [];
    }

    public static function url(string $path): string
    {
        return route('public-files.show', ['path' => $path]);
    }
}
