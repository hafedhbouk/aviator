<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Exceptions\InvoiceDraftException;
use App\Enums\InvoiceStatus;
use App\Enums\JustificationKind;
use App\Models\DamageFile;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceLineJustification;
use App\Models\User;
use App\Support\Files\PrivateFiles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Justificatifs d'une ligne (N2, D55) : dossier de dommage **complet** du même contrat,
 * ou pièce jointe privée (contrat d'assurance, justificatif de l'assureur, clause,
 * constat, devis). Le justificatif ne change jamais le code fiscal de la ligne. Sous
 * verrou de la facture, seulement au brouillon.
 */
final class ManageLineJustifications
{
    public function attachDamageFile(InvoiceLine $line, DamageFile $damageFile, User $actor): InvoiceLineJustification
    {
        return $this->onDraft($line, $actor, function (Invoice $invoice) use ($line, $damageFile, $actor): InvoiceLineJustification {
            $file = DamageFile::query()->findOrFail($damageFile->id);

            if ($invoice->contract_id === null || $file->contract_id !== $invoice->contract_id) {
                throw ValidationException::withMessages(['damage_file_id' => 'Ce dossier de dommage n\'appartient pas au contrat de la facture.']);
            }

            if (! $file->isComplete()) {
                throw ValidationException::withMessages(['damage_file_id' => 'Le dossier de dommage n\'est pas complet : il ne justifie pas encore la ligne.']);
            }

            return InvoiceLineJustification::query()->create([
                'invoice_line_id' => $line->id,
                'kind' => JustificationKind::DamageFile,
                'damage_file_id' => $file->id,
                'created_by' => $actor->id,
            ]);
        });
    }

    /**
     * @param  string  $uploadedPath  fichier déposé dans le dossier de dépôt privé
     */
    public function attachDocument(InvoiceLine $line, JustificationKind $kind, string $uploadedPath, ?string $description, User $actor): InvoiceLineJustification
    {
        if ($kind === JustificationKind::DamageFile) {
            throw ValidationException::withMessages(['kind' => 'Un dossier de dommage se rattache depuis le contrat, pas en pièce jointe.']);
        }

        return $this->onDraft($line, $actor, function (Invoice $invoice) use ($line, $kind, $uploadedPath, $description, $actor): InvoiceLineJustification {
            try {
                $path = PrivateFiles::attach($uploadedPath, "invoices/{$invoice->id}/justifications");
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['file' => $exception->getMessage()]);
            }

            return InvoiceLineJustification::query()->create([
                'invoice_line_id' => $line->id,
                'kind' => $kind,
                'file_path' => $path,
                'file_sha256' => hash('sha256', (string) Storage::disk(PrivateFiles::DISK)->get($path)),
                'description' => $description === null ? null : mb_substr(trim($description), 0, 255),
                'created_by' => $actor->id,
            ]);
        });
    }

    public function detach(InvoiceLineJustification $justification, User $actor): void
    {
        $line = InvoiceLine::query()->findOrFail($justification->invoice_line_id);

        $this->onDraft($line, $actor, function () use ($justification): void {
            InvoiceLineJustification::query()->findOrFail($justification->id)->delete();
        });
    }

    /**
     * @template T
     *
     * @param  callable(Invoice): T  $callback
     * @return T
     */
    private function onDraft(InvoiceLine $line, User $actor, callable $callback): mixed
    {
        return DB::transaction(function () use ($line, $actor, $callback): mixed {
            $invoice = Invoice::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($line->invoice_id);

            if ($invoice->status !== InvoiceStatus::Draft) {
                throw InvoiceDraftException::notDraft();
            }

            if (! $actor->can('update', $invoice)) {
                throw InvoiceDraftException::notAllowed();
            }

            return $callback($invoice);
        });
    }
}
