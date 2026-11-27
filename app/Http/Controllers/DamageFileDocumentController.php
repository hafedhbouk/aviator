<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\DamageFile;
use App\Models\DamageFileDocument;
use App\Support\Files\PrivateFiles;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Pièce d'un dossier de dommage (N2, D21), servie depuis le disque privé après la policy
 * du **contrat** (agence du contrat, RG-13). Désignée par la pièce, jamais par un chemin ;
 * hors de portée : 404.
 */
class DamageFileDocumentController extends Controller
{
    public function __invoke(int $document): BinaryFileResponse
    {
        $record = DamageFileDocument::query()->find($document);
        $file = $record === null ? null : DamageFile::query()->find($record->damage_file_id);
        // Lecture avec la portée par agence : un contrat d'une autre agence est introuvable.
        $contract = $file === null ? null : Contract::query()->find($file->contract_id);

        abort_if($record === null || $contract === null || Gate::denies('view', $contract), 404);

        return PrivateFiles::response($record->path, "{$record->kind->label()} {$record->id}");
    }
}
