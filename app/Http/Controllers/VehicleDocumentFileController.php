<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\VehicleDocument;
use App\Support\Files\PrivateFiles;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Pièce jointe d'un document de véhicule (B-08, D21), servie depuis le disque privé
 * après la policy du document (lue comme le véhicule : localisation pour l'agent).
 * Route authentifiée du panneau, 2FA imposée comme pour les pages (D9). Le fichier
 * est désigné par le document, jamais par un chemin : aucun autre fichier accessible.
 * Un document hors de portée reçoit 404, sans indiquer qu'il existe.
 */
class VehicleDocumentFileController extends Controller
{
    public function __invoke(int $document): BinaryFileResponse
    {
        $record = VehicleDocument::query()->find($document);

        abort_if($record === null || $record->file_path === null || Gate::denies('view', $record), 404);

        return PrivateFiles::response($record->file_path, "{$record->type->label()} {$record->id}");
    }
}
