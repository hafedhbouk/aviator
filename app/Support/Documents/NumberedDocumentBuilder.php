<?php

declare(strict_types=1);

namespace App\Support\Documents;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Constructeur de requêtes des documents numérotés : une suppression en masse
 * (Model::query()->delete()), qui contourne les événements du modèle, est refusée
 * dès qu'elle toucherait un document numéroté.
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class NumberedDocumentBuilder extends Builder
{
    public function delete(): mixed
    {
        $this->guardAgainstNumbered();

        return parent::delete();
    }

    public function forceDelete(): mixed
    {
        $this->guardAgainstNumbered();

        return parent::forceDelete();
    }

    private function guardAgainstNumbered(): void
    {
        $model = $this->getModel();
        $column = method_exists($model, 'sequenceNumberColumn') ? (string) $model->sequenceNumberColumn() : 'number';

        if ((clone $this)->whereNotNull($model->qualifyColumn($column))->exists()) {
            throw NumberedDocumentException::cannotDeleteInBulk();
        }
    }
}
