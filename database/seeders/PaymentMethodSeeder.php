<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

/**
 * Modes de paiement initiaux (plan de la phase 3, §3.2 ; A-07, E-06). La référence
 * (numéro de chèque, de transaction, de virement, de traite) est exigée sauf pour les
 * espèces. Idempotent : ne modifie jamais un mode existant.
 */
class PaymentMethodSeeder extends Seeder
{
    /** @var array<string, array{0: string, 1: bool}> */
    private const METHODS = [
        'cash' => ['Espèces', false],
        'cheque' => ['Chèque', true],
        'card' => ['Carte bancaire', true],
        'transfer' => ['Virement', true],
        'bill_of_exchange' => ['Traite', true],
    ];

    public function run(): void
    {
        foreach (self::METHODS as $code => [$name, $reference]) {
            PaymentMethod::query()->firstOrCreate(['code' => $code], [
                'name' => $name,
                'requires_reference' => $reference,
                'is_active' => true,
            ]);
        }
    }
}
