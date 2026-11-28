<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Nature d'un justificatif de ligne de facture (N2, D55, référentiel §2.2 à §2.6, §14).
 * Le justificatif ne fixe jamais le code fiscal : il en prouve la condition.
 */
enum JustificationKind: string
{
    case DamageFile = 'damage_file';
    case InsuranceContract = 'insurance_contract';
    case InsurerCertificate = 'insurer_certificate';
    case ContractClause = 'contract_clause';
    case ClaimReport = 'claim_report';
    case RepairQuote = 'repair_quote';

    public function label(): string
    {
        return match ($this) {
            self::DamageFile => 'Dossier de dommage',
            self::InsuranceContract => 'Contrat d\'assurance',
            self::InsurerCertificate => 'Justificatif de l\'assureur',
            self::ContractClause => 'Clause contractuelle',
            self::ClaimReport => 'Constat ou déclaration de sinistre',
            self::RepairQuote => 'Devis ou facture de réparation',
        };
    }
}
