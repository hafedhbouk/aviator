<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Pièce d'un dossier de dommage (N2) : constat, devis ou facture de réparation, autre.
 */
enum DamageDocumentKind: string
{
    case Report = 'report';
    case Quote = 'quote';
    case RepairInvoice = 'repair_invoice';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Report => 'Constat (amiable, rapport, déclaration)',
            self::Quote => 'Devis de réparation',
            self::RepairInvoice => 'Facture de réparation',
            self::Other => 'Autre pièce',
        };
    }
}
