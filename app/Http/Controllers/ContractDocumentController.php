<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Rental\Documents\ContractDocuments;
use App\Models\Contract;
use App\Models\Inspection;
use App\Support\Files\PrivateFiles;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Documents du contrat en PDF (D-10, D43), après la policy du **contrat** (agence du
 * contrat, RG-13) ; hors de portée : 404. L'état des lieux validé est servi depuis son
 * exemplaire signé figé ; un brouillon est généré à la demande. Téléchargement en pièce
 * jointe, sans cache partagé.
 */
class ContractDocumentController extends Controller
{
    public function __construct(private readonly ContractDocuments $documents) {}

    public function contract(int $contract): Response
    {
        $record = $this->visibleContract($contract);

        return $this->pdf($this->documents->contractPdf($record), "contrat-{$record->number}");
    }

    public function inspection(int $inspection): Response|BinaryFileResponse
    {
        $record = Inspection::query()->find($inspection);
        $contract = $record === null ? null : $this->visibleContract($record->contract_id);

        abort_if($record === null || $contract === null, 404);

        if ($record->signed_pdf_path !== null) {
            return PrivateFiles::response($record->signed_pdf_path, "etat-des-lieux-{$record->type->value}-{$contract->number}");
        }

        return $this->pdf($this->documents->inspectionPdf($record), "etat-des-lieux-{$record->type->value}-{$contract->number}-brouillon");
    }

    public function terms(): Response
    {
        abort_unless(Gate::allows('viewAny', Contract::class), 404);

        return $this->pdf($this->documents->termsPdf(), 'conditions-generales');
    }

    private function visibleContract(int $id): Contract
    {
        // Lecture avec la portée par agence : un contrat d'une autre agence est introuvable.
        $contract = Contract::query()->find($id);

        abort_if($contract === null || Gate::denies('view', $contract), 404);

        return $contract;
    }

    private function pdf(string $bytes, string $name): Response
    {
        return new Response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'.pdf"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
