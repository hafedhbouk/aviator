<?php

namespace Database\Seeders;

use App\Models\Option;
use App\Models\TaxCode;
use Illuminate\Database\Seeder;

/**
 * Options du cahier des charges (A-05), avec le code fiscal par défaut du plan de la
 * phase 3 (§3.2). Créées **inactives et sans prix** : l'admin du siège saisit les prix,
 * puis les active. « Livraison » n'a pas de code dans le référentiel (Q85) : elle reste
 * sans code, donc impossible à activer. Idempotent : ne modifie jamais une option existante.
 * À lancer après FiscalReferenceSeeder.
 */
class OptionSeeder extends Seeder
{
    /** @var array<string, array{0: string, 1: string, 2: string|null}> code => [nom, unité, code fiscal] */
    private const OPTIONS = [
        'EXTRA_DRIVER' => ['Conducteur supplémentaire', Option::PER_DAY, 'EXTRA_DRIVER'],
        'CHILD_SEAT' => ['Siège enfant', Option::PER_DAY, 'CHILD_SEAT'],
        'GPS' => ['GPS', Option::PER_DAY, 'GPS'],
        'EXCESS_REDUCTION' => ['Franchise réduite', Option::PER_DAY, 'EXCESS_REDUCTION_OPTION'],
        'DELIVERY' => ['Livraison', Option::PER_RENTAL, null],
    ];

    public function run(): void
    {
        foreach (self::OPTIONS as $code => [$name, $unit, $taxCode]) {
            Option::query()->firstOrCreate(['code' => $code], [
                'name' => $name,
                'pricing_unit' => $unit,
                'tax_code_id' => $taxCode === null ? null : TaxCode::query()->where('code', $taxCode)->value('id'),
                'is_active' => false,
            ]);
        }
    }
}
