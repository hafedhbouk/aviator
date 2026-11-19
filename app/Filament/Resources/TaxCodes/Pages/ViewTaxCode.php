<?php

namespace App\Filament\Resources\TaxCodes\Pages;

use App\Filament\Resources\TaxCodes\TaxCodeResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * Fiche d'un code fiscal et versions de sa règle. Les actions d'écriture (nouvelle
 * version, activation) sont dans RulesRelationManager, réservées au super admin.
 */
class ViewTaxCode extends ViewRecord
{
    protected static string $resource = TaxCodeResource::class;
}
