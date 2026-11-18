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
| **Total** | **3,75** | |

### Option, selon Q78

| Élément | Charge (j) | Contenu |
| --- | ---: | --- |
| E24 (a). Multidevise minimale | 1,5 | Factures en TND ; encaissement en devise : devise, montant d'origine, taux, contre-valeur TND figée ; caisse par devise |

| Phase 6 | Charge (j) |
| --- | ---: |
| Référence | 31 |
| Demande d'évolution (indispensable) | +3,75 |
| **Nouveau total** | **34,75** |
| **Écart** | **+3,75 j (+12,1 %)** ; 36,25 j (+16,9 %) avec l'option multidevise |
