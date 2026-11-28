<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Sequences\Series;

/**
 * Type de document de facturation : facture de location (E-01), facture
 * complémentaire (E-05 : retard, dommages, kilomètres constatés après coup) et avoir
 * (E-04, toujours lié à sa facture d'origine).
 */
enum InvoiceType: string
{
    case Invoice = 'invoice';
    case Supplementary = 'supplementary';
    case CreditNote = 'credit_note';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Facture',
            self::Supplementary => 'Facture complémentaire',
            self::CreditNote => 'Avoir',
        };
    }

    /**
     * Série de numérotation : les factures complémentaires suivent la série des
     * factures (Q87, valeur par défaut, D27-5) ; les avoirs ont la leur (D27).
     */
    public function series(): Series
    {
        return $this === self::CreditNote ? Series::CreditNote : Series::Invoice;
    }
}
