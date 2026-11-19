<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Files\PublicFiles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Sert un fichier public (le logo) depuis le disque privé, sans lien symbolique (D21).
 * Lecture seule ; tout fichier hors de la liste blanche reçoit 404, sans indiquer
 * s'il existe. Route hors du groupe « web » : ni session ni cookie.
 */
class PublicFileController extends Controller
{
    /** Chaque dépôt reçoit un nouveau nom aléatoire : le contenu d'un nom ne change jamais. */
    private const CACHE_CONTROL = 'public, max-age=31536000, immutable';

    public function __invoke(Request $request, string $path): BinaryFileResponse
    {
        abort_unless(PublicFiles::hasAllowedShape($path) && PublicFiles::isListed($path), 404);

        $disk = Storage::disk(PublicFiles::DISK);
        abort_unless($disk->exists($path), 404);

        // Type réel, lu dans le contenu : un fichier renommé en .png n'est pas servi.
        $expected = PublicFiles::contentType($path);
        abort_unless($expected !== null && $disk->mimeType($path) === $expected, 404);

        $response = new BinaryFileResponse($disk->path($path), 200, [
            'Content-Type' => $expected,
            'Cache-Control' => self::CACHE_CONTROL,
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'Cross-Origin-Resource-Policy' => 'same-site',
        ], public: true, contentDisposition: 'inline', autoEtag: true, autoLastModified: true);

        $response->isNotModified($request);

        return $response;
    }
}
