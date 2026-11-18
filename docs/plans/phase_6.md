# Phase 6 — Facturation, encaissements, cautions : éléments reportés

Le plan complet sera établi au démarrage de la phase et soumis à validation.

## Décidé pendant la phase 2

| Élément | Charge en phase 6 | Détail |
| --- | ---: | --- |
| **Documents numérotés jamais supprimés** | Incluse (brique livrée en phase 2) | Trait `HasSequenceNumber` (refus de suppression par le modèle et par suppression en masse), concern de policy `ForbidsDeletingNumberedDocuments` (aucune suppression dans l'interface, super admin compris), test qui exige la protection de toute table portant une colonne `number`. À poser sur les factures, avoirs, factures complémentaires et reçus de caution |
| **Mode d'annulation à confirmer avec l'expert-comptable** | 0 (question) | Facture émise : annulation par un **avoir** total ou partiel (E-04, RG-06) ; autres documents numérotés (reçus de caution, encaissements) : par un **statut** d'annulation avec motif, ou par un document d'annulation. À valider avec Q11, Q30 et Q16 avant d'écrire les écrans |
| **Format des numéros** | Selon la règle fiscale | `SequenceDefinition` des factures et avoirs (préfixe, période, séquence commune ou par agence) fixée par la règle validée ; aucun format n'est écrit avant |
