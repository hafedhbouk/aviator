<?php

declare(strict_types=1);

namespace App\Support\Files;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Fichiers privés (D21) : pièces jointes des documents (carte grise, attestation
 * d'assurance ; puis photos, signatures et pièces en phase 5). Jamais de lien
 * symbolique ni d'URL publique : un fichier n'est servi que par un contrôleur qui
 * vérifie d'abord la policy du document auquel il appartient.
 *
 * - Dépôt : un fichier envoyé par l'interface arrive dans UPLOAD_DIRECTORY ; il n'est
 *   accepté que de là, puis déplacé sous le dossier de son document avec un nom
 *   aléatoire. Un chemin falsifié vers un autre fichier privé est donc refusé.
 * - Types : PDF, JPEG, PNG, WebP, contrôlés sur le contenu réel ; 10 Mo au plus.
 * - Envoi : type réel, `nosniff`, aucun cache partagé, bac à sable CSP.
 */
final class PrivateFiles
{
    public const DISK = 'private';

    /** Dossier de dépôt des formulaires (FileUpload), avant rattachement à un document. */
    public const UPLOAD_DIRECTORY = 'uploads';

    public const MAX_BYTES = 10 * 1024 * 1024;

    public const TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * Rattache un fichier déposé à son document : contrôle, puis déplacement sous
     * `$directory` avec un nom aléatoire. Renvoie le chemin définitif.
     *
     * @throws InvalidArgumentException fichier absent, hors du dossier de dépôt, d'un
     *                                  type non accepté ou trop gros
     */
    public static function attach(string $uploadedPath, string $directory): string
    {
        $disk = Storage::disk(self::DISK);

        if (! str_starts_with($uploadedPath, self::UPLOAD_DIRECTORY.'/')
            || str_contains($uploadedPath, '..')
            || str_contains($uploadedPath, '\\')
            || str_contains($uploadedPath, "\0")
            || ! $disk->exists($uploadedPath)) {
            throw new InvalidArgumentException('Fichier introuvable : déposez-le de nouveau.');
        }

        $extension = self::TYPES[(string) $disk->mimeType($uploadedPath)] ?? null;

        if ($extension === null) {
            throw new InvalidArgumentException('Type de fichier refusé : PDF, JPEG, PNG ou WebP seulement.');
        }

        if ($disk->size($uploadedPath) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Fichier trop volumineux : 10 Mo au plus.');
        }

        $path = trim($directory, '/').'/'.Str::uuid()->toString().'.'.$extension;
        $disk->move($uploadedPath, $path);

        return $path;
    }

    /**
     * Réponse qui envoie le fichier, après que l'appelant a vérifié la policy.
     */
    public static function response(string $path, string $downloadName): BinaryFileResponse
    {
        $disk = Storage::disk(self::DISK);

        abort_unless($disk->exists($path), 404);

        $type = (string) $disk->mimeType($path);
        $extension = self::TYPES[$type] ?? null;
        abort_if($extension === null, 404);

        $name = Str::slug(pathinfo($downloadName, PATHINFO_FILENAME)) ?: 'document';

        $response = new BinaryFileResponse($disk->path($path), 200, [
            'Content-Type' => $type,
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ], public: false, contentDisposition: null, autoEtag: false, autoLastModified: false);

        // Images affichées dans le bac à sable ; PDF téléchargés : aucun rendu de PDF
        // dans l'origine de l'application (le lecteur intégré refuse le bac à sable).
        $response->setContentDisposition(
            $extension === 'pdf' ? ResponseHeaderBag::DISPOSITION_ATTACHMENT : ResponseHeaderBag::DISPOSITION_INLINE,
            "{$name}.{$extension}",
        );

        return $response;
    }
}
