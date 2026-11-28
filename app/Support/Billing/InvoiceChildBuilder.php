<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Domain\Billing\Exceptions\InvoiceLockedException;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Constructeur de requêtes des lignes et des taxes d'une facture : une modification
 * ou une suppression en masse est refusée dès qu'elle toucherait une facture validée.
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class InvoiceChildBuilder extends Builder
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

    public function delete(): mixed
    {
        $this->guardAgainstLocked();

        return parent::delete();
    }

    public function forceDelete(): mixed
    {
        $this->guardAgainstLocked();

        return parent::forceDelete();
    }

    private function guardAgainstLocked(): void
    {
        if ($this->modelSave) {
            return;
        }

        $locked = Invoice::query()->withoutGlobalScopes()
            ->where('status', '!=', InvoiceStatus::Draft->value)
            ->select('id')
            ->toBase();

        if ((clone $this)->whereIn($this->getModel()->qualifyColumn('invoice_id'), $locked)->exists()) {
            throw InvoiceLockedException::lineLocked();
        }
    }
}
