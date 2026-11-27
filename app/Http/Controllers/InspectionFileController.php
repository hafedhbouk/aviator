<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\Inspection;
use App\Models\InspectionPhoto;
use App\Support\Files\PrivateFiles;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Photos et signature d'un état des lieux (D-06, D21, D44, D45), servies depuis le
 * disque privé après la policy du **contrat** (agence du contrat, RG-13). Le fichier
 * est désigné par la photo ou l'état des lieux, jamais par un chemin. Hors de portée :
 * 404, sans indiquer que le fichier existe.
 */
class InspectionFileController extends Controller
{
    public function photo(int $photo): BinaryFileResponse
    {
        $record = InspectionPhoto::query()->find($photo);
        $inspection = $record === null ? null : Inspection::query()->find($record->inspection_id);

        abort_if($record === null || ! $this->canView($inspection), 404);

        return PrivateFiles::response($record->path, "photo-etat-des-lieux-{$record->id}");
    }

    public function signature(int $inspection): BinaryFileResponse
    {
        $record = Inspection::query()->find($inspection);

        abort_if($record === null || $record->signature_path === null || ! $this->canView($record), 404);

        return PrivateFiles::response($record->signature_path, "signature-{$record->id}");
    }

    private function canView(?Inspection $inspection): bool
    {
        // Lecture avec la portée par agence : un contrat d'une autre agence est introuvable.
        $contract = $inspection === null ? null : Contract::query()->find($inspection->contract_id);

        return $contract !== null && Gate::allows('view', $contract);
    }
}
