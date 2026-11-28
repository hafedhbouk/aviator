<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Enums\CustomerType;
use App\Models\Agency;
use App\Models\Company;
use App\Models\Customer;

/**
 * Client et émetteur figés sur la facture à la validation (D59, E8) : une
 * modification ultérieure de la fiche client ou de la fiche société ne change plus
 * une facture validée.
 */
final class InvoiceSnapshots
{
    /**
     * Nom, matricule fiscal, RNE du client entreprise, adresse et pays.
     *
     * @return array{type: string, name: string, tax_id: string|null, trade_register: string|null, address: string|null, city: string|null, country: string|null}
     */
    public static function customer(Customer $customer): array
    {
        $isCompany = $customer->type === CustomerType::Company;

        return [
            'type' => $customer->type->value,
            'name' => $customer->displayName(),
            'tax_id' => $customer->tax_id,
            'trade_register' => $isCompany ? $customer->trade_register : null,
            'address' => $customer->address,
            'city' => $customer->city,
            'country' => $customer->country,
        ];
    }

    /**
     * Société (raison sociale, matricule fiscal, RNE, coordonnées, mentions légales) et
     * agence émettrice.
     *
     * @return array{legal_name: string, trade_name: string|null, tax_id: string|null, trade_register: string|null, address: string|null, city: string|null, phone: string|null, email: string|null, legal_mentions: string|null, agency: array{id: int, code: string, name: string, city: string}}
     */
    public static function issuer(Company $company, Agency $agency): array
    {
        return [
            'legal_name' => $company->legal_name,
            'trade_name' => $company->trade_name,
            'tax_id' => $company->tax_id,
            'trade_register' => $company->trade_register,
            'address' => $company->address,
            'city' => $company->city,
            'phone' => $company->phone,
            'email' => $company->email,
            'legal_mentions' => $company->invoice_legal_mentions,
            'agency' => [
                'id' => $agency->id,
                'code' => $agency->code,
                'name' => $agency->name,
                'city' => $agency->city,
            ],
        ];
    }
}
