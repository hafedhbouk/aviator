<?php

declare(strict_types=1);

namespace App\Support\Documents;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Document qui reçoit un numéro de séquence (réservation, contrat, facture, avoir,
 * reçu…). Règle (RG-06, RG-11) : un document numéroté n'est jamais supprimé, ni par
 * le modèle, ni par une suppression en masse, ni par l'interface (policies :
 * ForbidsDeletingNumberedDocuments). Son annulation passe par un statut ou un
 * document d'annulation (avoir), à confirmer avec l'expert-comptable en phase 6.
 *
 * Un brouillon sans numéro reste supprimable.
 *
 * @mixin Model
 */
trait HasSequenceNumber
{
    public static function bootHasSequenceNumber(): void
    {
        static::deleting(function (Model $model): void {
            /** @var Model&self $model */
            $number = $model->getAttribute($model->sequenceNumberColumn());

            if ($number !== null && $number !== '') {
                throw NumberedDocumentException::cannotDelete((string) $number);
            }
        });
    }

    /**
     * Colonne du numéro de séquence.
     */
    public function sequenceNumberColumn(): string
    {
        return 'number';
    }

    public function isNumbered(): bool
    {
        $number = $this->getAttribute($this->sequenceNumberColumn());

        return $number !== null && $number !== '';
    }

    /**
     * Même type de retour que Model::newEloquentBuilder() dans Laravel (Builder<*>).
     *
     * @param  QueryBuilder  $query
     * @return NumberedDocumentBuilder<*>
     */
    public function newEloquentBuilder($query): NumberedDocumentBuilder
    {
        return new NumberedDocumentBuilder($query);
    }
}
