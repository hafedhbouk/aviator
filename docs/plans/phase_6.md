# Phase 6 — Facturation, encaissements, cautions : éléments reportés

Le plan complet sera établi au démarrage de la phase et soumis à validation.

## Décidé pendant la phase 2

| Élément | Charge en phase 6 | Détail |
| --- | ---: | --- |
| **Documents numérotés jamais supprimés** | Incluse (brique livrée en phase 2) | Trait `HasSequenceNumber` (refus de suppression par le modèle et par suppression en masse), concern de policy `ForbidsDeletingNumberedDocuments` (aucune suppression dans l'interface, super admin compris), test qui exige la protection de toute table portant une colonne `number`. À poser sur les factures, avoirs, factures complémentaires et reçus de caution |
| **Mode d'annulation à confirmer avec l'expert-comptable** | 0 (question) | Facture émise : annulation par un **avoir** total ou partiel (E-04, RG-06) ; autres documents numérotés (reçus de caution, encaissements) : par un **statut** d'annulation avec motif, ou par un document d'annulation. À valider avec Q11, Q30 et Q16 avant d'écrire les écrans |
| **Format des numéros** | Selon la règle fiscale | `SequenceDefinition` des factures et avoirs (préfixe, période, séquence commune ou par agence) fixée par la règle validée ; aucun format n'est écrit avant |

## Demande d'évolution du cabinet (18/11/2026)

Source : `docs/analyse_ecarts_cabinet.md`, validée le 18/11/2026 avec décisions provisoires, à confirmer par la direction.

### Indispensable, intégré au lot 1

| Élément (analyse) | Charge (j) | Contenu |
| --- | ---: | --- |
| E6. Garde-fou fiscal à l'émission | 0,5 | Refus d'émettre en production avec une règle non `CONFIRMED` ; filigrane « Règles fiscales non confirmées » sur les PDF hors production |
| E8. Mentions | 0,5 | RNE du client entreprise, mentions adaptées au type de client, type de document et agence émettrice |
| E11. Statuts | 0,5 | Statut du document (brouillon, validée, émise, avoir total, annulée) et statut de paiement calculé (non payée, partiellement payée, payée) ; « annulée » selon Q79 |
| E13. Interface `ElectronicInvoicingProvider` | 0,75 | Interface, fournisseur « désactivé » par défaut, champs de suivi et table des envois (structure seulement), **aucune transmission simulée** ; le connecteur TEIF / TTN relève d'un avenant séparé |
| E17. Banque sur l'encaissement | 0,25 | Banque pour les chèques et virements ; imputation selon Q81 |
| E19. Avoirs remboursés ou imputés | 1 | Remboursement (décaissement tracé) ou imputation sur une autre facture, totale ou partielle |
| E23. Qualification des retenues sur caution | 0,25 | Nature fiscale de la retenue (Q51) |
| N2. Qualification selon la nature réelle (référentiel v1.0) | 1 | Code fiscal choisi pour chaque ligne, jamais déduit du libellé ; ligne exonérée ou hors champ : justificatif rattaché obligatoire (contrat d'assurance, clause de retard, dossier de dommage de la phase 5, justificatifs du sinistre), sinon validation refusée ; règle `PENDING_DOCUMENTATION` jamais applicable en production |
| N3. Exonération du timbre | 0,25 | Appliquée à la main sur une facture, par une règle active de `stamp_duty_rules`, avec motif et justificatif ; jamais automatique pour une facture « impayée » |
| N4. Numérotation `AVI-000001` | 0 | Série unique sans remise à zéro (`SequencePeriod::None`, longueur 6) ; année et agence dans des champs distincts |
| **Total** | **5** | |

### Option, selon Q78

| Élément | Charge (j) | Contenu |
| --- | ---: | --- |
| E24 (a). Multidevise minimale | 1,5 | Factures en TND ; encaissement en devise : devise, montant d'origine, taux, contre-valeur TND figée ; caisse par devise |

| Phase 6 | Charge (j) |
| --- | ---: |
| Référence | 31 |
| Demande d'évolution (indispensable, référentiel v1.0) | +5 |
| **Nouveau total** | **36** |
| **Écart** | **+5 j (+16,1 %)** ; **37,5 j (+21 %, au-delà du seuil d'alerte)** avec l'option multidevise |

### Tests de référence (référentiel v1.0)

| Test | Attendu |
| --- | --- |
| **Exemple chiffré de la §5** sur une facture complète | Facture de location 100,000 + GPS 10,000 + siège enfant 5,000 : total HT **115,000**, TVA 19 % **21,850**, TTC **136,850**, timbre **1,000**, net à payer **137,850**, identiques à l'écran, dans le PDF et dans le journal des ventes |
| **Libellé « assurance »** | Une ligne dont le libellé contient « assurance » (« Assurance tous risques », « ASSURANCE », « assurance complémentaire »…) n'est **jamais** exonérée automatiquement : sans code fiscal choisi, la validation est refusée ; avec `INSURANCE_PREMIUM` sans contrat ni justificatif de l'assureur, la validation est refusée ; avec `ADDITIONAL_PROTECTION_SERVICE`, TVA 19 % |
| Hors champ sans justificatif | `LATE_RETURN_PENALTY`, `VEHICLE_DAMAGE_COMPENSATION` ou `INSURANCE_EXCESS_COMPENSATION` sans justificatif : validation refusée |
| Timbre d'une facture impayée | Le timbre reste dû ; aucune exonération automatique |
| Numérotation | `AVI-000001`, puis `AVI-000002` d'une autre agence et d'une autre année ; aucune remise à zéro ; numéros uniques sous facturation simultanée |
