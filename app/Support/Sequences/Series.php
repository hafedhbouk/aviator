<?php

declare(strict_types=1);

namespace App\Support\Sequences;

/**
 * Séries de numérotation à format réglable (D27). Chaque série a son propre
 * compteur : émettre un avoir n'avance pas le compteur des factures.
 *
 * Réglage initial : format du référentiel fiscal v1.0 validé (§4.1), continu, sans
 * remise à zéro, `AVI-000001` pour les factures et `AV-000001` pour les avoirs
 * (courriel de la direction du 18/11/2026, Q30). Les factures complémentaires
 * suivent la série des factures (Q87, valeur par défaut). Le reçu de caution n'est
 * pas un document fiscal (Q56) : `CAU-AAAA-NNNNN`, remis à zéro chaque année.
 */
enum Series: string
{
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';
    case DepositReceipt = 'deposit_receipt';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Factures',
            self::CreditNote => 'Avoirs',
            self::DepositReceipt => 'Reçus de caution',
        };
    }

    /**
     * Série fiscale : le format avec l'année et la remise à zéro annuelle y sont
     * verrouillés tant que le cabinet ne les a pas confirmés par écrit (D27-2).
     */
    public function isFiscal(): bool
    {
        return $this !== self::DepositReceipt;
    }

    public function defaultFormat(): SeriesFormat
    {
        return match ($this) {
            self::Invoice => new SeriesFormat('AVI', SeriesFormat::DEFAULT_TEMPLATE, 6, SequencePeriod::None),
            self::CreditNote => new SeriesFormat('AV', SeriesFormat::DEFAULT_TEMPLATE, 6, SequencePeriod::None),
            self::DepositReceipt => new SeriesFormat('CAU', '{PREFIX}-{YEAR}-{SEQUENCE}', 5, SequencePeriod::Year),
        };
    }
}
