<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\ClosedPeriodGuard;
use App\Domain\Billing\Documents\InvoiceDocuments;
use App\Domain\Billing\Exceptions\InvoiceValidationException;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Files\PrivateFiles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Émission d'une facture validée (D56, E-10) : remise au client, PDF figé sur le
 * disque privé avec son empreinte SHA-256, jamais régénéré ensuite. Une facture émise
 * ne s'annule plus : elle se corrige par un avoir (L6). Hors production, le PDF porte
 * la mention « Règles fiscales non confirmées » (E6). Aucun envoi par e-mail (Q25).
 */
final class IssueInvoice
{
    public function __construct(
        private readonly InvoiceDocuments $documents,
        private readonly ClosedPeriodGuard $periods,
    ) {}

    public function handle(Invoice $invoice, User $actor): Invoice
    {
        if (! $actor->can('issue', $invoice)) {
            throw InvoiceValidationException::notAllowed();
        }

        return DB::transaction(function () use ($invoice, $actor): Invoice {
            $locked = Invoice::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($invoice->id);

            if ($locked->status !== InvoiceStatus::Validated) {
                throw InvoiceValidationException::invalidStatus($locked->status->label(), 'l\'émission');
            }

            $at = CarbonImmutable::now();
            $this->periods->assertOpen($at, 'l\'émission de la facture');

            $bytes = $this->documents->invoicePdf($locked);
            $path = "invoices/{$locked->id}/".str_replace('/', '-', (string) $locked->number).'.pdf';
            Storage::disk(PrivateFiles::DISK)->put($path, $bytes);

            $locked->fill([
                'status' => InvoiceStatus::Issued,
                'issued_at' => $at,
                'issued_by' => $actor->id,
                'issued_pdf_path' => $path,
                'issued_pdf_sha256' => hash('sha256', $bytes),
            ])->save();

            return $locked->refresh();
        });
    }
}
