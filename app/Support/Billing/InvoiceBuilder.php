<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Domain\Billing\Exceptions\InvoiceLockedException;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Support\Documents\NumberedDocumentBuilder;

/**
 * Constructeur de requêtes des factures : une modification en masse (update,
 * increment, decrement), qui contourne les contrôles du modèle, est refusée dès
 * qu'elle toucherait une facture validée (RG-06). L'enregistrement d'un modèle
 * passe (forModelSave) : ses contrôles ont déjà joué dans l'événement « updating ».
 *
 * @extends NumberedDocumentBuilder<Invoice>
 */
class InvoiceBuilder extends NumberedDocumentBuilder
{
    private bool $modelSave = false;

    /**
     * Requête d'enregistrement d'un seul modèle (Model::save), déjà contrôlé.
     */
    public function forModelSave(): static
    {
        $this->modelSave = true;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): int
    {
        $this->guardAgainstLocked();

        return parent::update($values);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function increment($column, $amount = 1, array $extra = []): int
    {
        $this->guardAgainstLocked();

        return parent::increment($column, $amount, $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function decrement($column, $amount = 1, array $extra = []): int
    {
        $this->guardAgainstLocked();

        return parent::decrement($column, $amount, $extra);
    }

    private function guardAgainstLocked(): void
    {
        if ($this->modelSave) {
            return;
        }

        if ((clone $this)->where($this->getModel()->qualifyColumn('status'), '!=', InvoiceStatus::Draft->value)->exists()) {
            throw InvoiceLockedException::bulkUpdate();
        }
    }
}
