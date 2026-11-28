<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Domain\Billing\Exceptions\InvoiceLockedException;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Ligne ou taxe d'une facture : créée, modifiée et supprimée seulement tant que la
 * facture est un brouillon (RG-06), par le modèle comme par une requête en masse.
 *
 * @mixin Model
 */
trait LocksWithInvoice
{
    public static function bootLocksWithInvoice(): void
    {
        static::saving(function (Model $model): void {
            self::assertInvoiceIsDraft($model->getAttribute('invoice_id'));

            if ($model->exists && $model->getOriginal('invoice_id') !== $model->getAttribute('invoice_id')) {
                self::assertInvoiceIsDraft($model->getOriginal('invoice_id'));
            }
        });

        static::deleting(fn (Model $model) => self::assertInvoiceIsDraft($model->getOriginal('invoice_id')));
    }

    private static function assertInvoiceIsDraft(mixed $invoiceId): void
    {
        $status = Invoice::query()->withoutGlobalScopes()->whereKey($invoiceId)->value('status');

        if ($status !== null && $status !== InvoiceStatus::Draft) {
            throw InvoiceLockedException::lineLocked();
        }
    }

    /**
     * @param  QueryBuilder  $query
     * @return InvoiceChildBuilder<*>
     */
    public function newEloquentBuilder($query): InvoiceChildBuilder
    {
        return new InvoiceChildBuilder($query);
    }

    /**
     * Marque la requête d'enregistrement du modèle : ses contrôles ont déjà joué
     * dans l'événement « updating ».
     *
     * @param  Builder<static>  $query
     */
    protected function performUpdate(Builder $query): bool
    {
        self::markModelSave($query);

        return parent::performUpdate($query);
    }

    /**
     * @param  Builder<*>  $query
     */
    private static function markModelSave(Builder $query): void
    {
        if ($query instanceof InvoiceChildBuilder) {
            $query->forModelSave();
        }
    }
}
