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
| E13. Interface `ElectronicInvoicingProvider` | 0,75 | Interface, fournisseur « désactivé » par défaut, champs de suivi et table des envois (structure seulement), **aucune transmission simulée** ; le connecteur TEIF / TTN relève d'un avenant séparé, pas préalable à la mise en production (situation B, Q84), à livrer dès la finalisation de l'adhésion |
| E17. Banque sur l'encaissement | 0,25 | Banque pour les chèques et virements ; imputation selon Q81 |
| E19. Avoirs remboursés ou imputés | 1 | Remboursement (décaissement tracé) ou imputation sur une autre facture, totale ou partielle |
| E23. Qualification des retenues sur caution | 0,25 | Nature fiscale de la retenue (Q51) |
| N2. Qualification selon la nature réelle (référentiel v1.0) | 1 | Code fiscal choisi pour chaque ligne, jamais déduit du libellé ; ligne exonérée ou hors champ : justificatif rattaché obligatoire (contrat d'assurance, clause de retard, dossier de dommage de la phase 5, justificatifs du sinistre), sinon validation refusée ; règle `PENDING_DOCUMENTATION` jamais applicable en production |
| N3. Exonération du timbre | 0,25 | Appliquée à la main sur une facture, par une règle active de `stamp_duty_rules`, avec motif et justificatif ; jamais automatique pour une facture « impayée » |
| N4. Numérotation continue | 0 | Compteur sans remise à zéro ; année et agence dans des champs distincts (référentiel §4.1) |
| **Total** | **5** | |

### Numérotation des factures et des avoirs (D27, Q30)

Source : courriel de la direction du 18/11/2026 (`docs/reference/regles_fiscales_validees/reponse_q30_numerotation.pdf`), arbitré le 18/11/2026. Le courriel reste **à confirmer** jusqu'au dépôt de la confirmation nominative de Faouzi Bannour ; les règles sur les avoirs ne contredisent pas le référentiel et sont planifiées.

| Élément | Charge (j) | Contenu |
| --- | ---: | --- |
| D27-1. Format par modèle et compteur par série | 0,5 | `SequenceDefinition` actuelle : l'année n'apparaît dans le numéro que si le compteur repart à zéro chaque année, et la clé du compteur est le préfixe. D27 exige un **modèle** (`{PREFIX}`, `{YEAR}`, `{SEQUENCE}`) indépendant de la période du compteur, et un compteur attaché à la **série** (factures, avoirs), pour qu'un changement de préfixe ne remette pas le compteur à zéro. **Réglage initial : `AVI-{SEQUENCE}` et `AV-{SEQUENCE}`** (format du référentiel validé, 6 chiffres) |
| D27-2. Verrous | 0,25 | Format avec l'année (`AVI-{YEAR}-{SEQUENCE}`, `{YEAR}` = année d'émission à l'heure de Tunis) **prévu mais désactivé**, activable seulement quand une confirmation écrite du cabinet est enregistrée ; remise à zéro annuelle présente mais refusée tant qu'elle n'est pas CONFIRMED dans une version ultérieure du référentiel |
| D27-3. Commande Artisan de réglage du format | 0,25 | Réservée au terminal (aucun écran en phase 6) ; changement journalisé (avant, après, qui, quand) ; **refusée en production sans `--force`** ; appliquée aux prochains numéros seulement ; refus d'un format sans `{SEQUENCE}` ou pouvant produire un numéro déjà attribué, dans sa série ou dans l'autre ; refus du format avec l'année tant que le verrou D27-2 est fermé |
| D27-4. Avoirs liés, plafonnés | 0 | Déjà prévu (E-04, RG-06) : avoir lié obligatoirement à sa facture ; avoirs partiels et totaux dans la série `AV-` ; total des avoirs d'une facture ≤ son montant, contrôlé sous verrou |
| D27-5. Factures complémentaires | 0 | Série `AVI-` par défaut, paramétrable (Q87) |
| **Total D27 en phase 6** | **1** | L'écran de numérotation (1 j) est en phase 9 |

### Dinar uniquement (D26)

Aucune devise étrangère (réponse de la direction à Q78, 18/11/2026) : multidevise retirée du plan. Toute devise autre que `TND` est refusée sur les factures, avoirs, encaissements et cautions. Sans charge supplémentaire (contrôle de saisie et test).

| Phase 6 | Charge (j) |
| --- | ---: |
| Référence | 31 |
| Demande d'évolution (indispensable, référentiel v1.0) | +5 |
| Numérotation D27 (écran en phase 9) | +1 |
| **Nouveau total** | **37** |
| **Écart** | **+6 j (+19,4 %)**, sous le seuil d'alerte (37,2 j), avec 0,2 j de marge |

**Arbitrage du 18/11/2026** : l'écran de numérotation passe en phase 9 ; en phase 6, une commande Artisan le remplace (+0,25 j). La phase 6 reste sous le seuil d'alerte, sans marge réelle.

### Tests de référence (référentiel v1.0)

| Test | Attendu |
| --- | --- |
| **Exemple chiffré de la §5** sur une facture complète | Facture de location 100,000 + GPS 10,000 + siège enfant 5,000 : total HT **115,000**, TVA 19 % **21,850**, TTC **136,850**, timbre **1,000**, net à payer **137,850**, identiques à l'écran, dans le PDF et dans le journal des ventes |
| **Libellé « assurance »** | Une ligne dont le libellé contient « assurance » (« Assurance tous risques », « ASSURANCE », « assurance complémentaire »…) n'est **jamais** exonérée automatiquement : sans code fiscal choisi, la validation est refusée ; avec `INSURANCE_PREMIUM` sans contrat ni justificatif de l'assureur, la validation est refusée ; avec `ADDITIONAL_PROTECTION_SERVICE`, TVA 19 % |
| Hors champ sans justificatif | `LATE_RETURN_PENALTY`, `VEHICLE_DAMAGE_COMPENSATION` ou `INSURANCE_EXCESS_COMPENSATION` sans justificatif : validation refusée |
| Timbre d'une facture impayée | Le timbre reste dû ; aucune exonération automatique |
| **Passage d'année (D27)** | Format initial : dernière facture de 2026 `AVI-000125`, première de 2027 `AVI-000126` (aucune remise à zéro) ; même chose pour les avoirs. Format avec l'année, une fois déverrouillé : `AVI-2026-000125` (31/12/2026 à 23:59, heure de Tunis) puis `AVI-2027-000126` ; une facture émise le 31/12/2026 à 23:30 UTC (00:30 à Tunis le 01/01/2027) porte l'année 2027 |
| **Format avec l'année verrouillé (D27)** | Activer `AVI-{YEAR}-{SEQUENCE}` est refusé tant que la confirmation écrite du cabinet n'est pas enregistrée |
| **Deux séries indépendantes (D27)** | Factures et avoirs ont chacun leur compteur : émettre un avoir n'avance pas le compteur des factures ; numéros uniques sous émission simultanée, dans chaque série |
| **Remise à zéro verrouillée (D27)** | Activer la remise à zéro annuelle est refusé tant que la règle n'est pas CONFIRMED |
| **Avoirs plafonnés (D27)** | Facture de 137,850 : avoir partiel de 50,000 accepté, second avoir de 87,850 accepté, troisième avoir de 0,001 refusé ; un avoir sans facture d'origine est refusé ; deux avoirs simultanés qui dépasseraient ensemble le montant : un seul passe |
| **Commande de réglage du format (D27)** | Changement journalisé ; refusé en production sans `--force` ; le numéro suivant prend le nouveau format, les numéros existants ne changent pas ; format sans `{SEQUENCE}` refusé ; format pouvant reproduire un numéro existant refusé (par exemple préfixe de facture remplacé par `AV-`) ; changer le préfixe ne remet pas le compteur à zéro |
| **Dinar uniquement (D26)** | Une facture, un avoir, un encaissement ou une caution dans une devise autre que `TND` (par exemple `EUR`) est refusé avec un message clair ; rien n'est enregistré |
