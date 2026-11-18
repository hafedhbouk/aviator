# Phase 3 — Paramétrage et référentiels : éléments reportés

Ce document recueille ce qui a été décidé pendant les phases précédentes pour la phase 3. Le plan complet sera établi au démarrage de la phase et soumis à validation.

## Reporté de la phase 2

| Élément | Origine | Détail |
| --- | --- | --- |
| **Route publique du logo (D21)** | Proposition de rééquilibrage de la phase 2, à valider | Servir le logo de la fiche société (A-01) par une route dédiée, sans `storage:link` : lecture seule, liste blanche, en-têtes de cache |
| **Écran des réglages de sécurité** | Q76 | Rendre `security.require_2fa_for_agency_users` modifiable par le super admin, avec les autres groupes de réglages |
| **Fiscal : plus bloquant sur le principe** (18/11/2026) | Q11, Q30, spécifications du cabinet | Les valeurs du cabinet sont saisies en PROPOSED ou PENDING_ACCOUNTANT_VALIDATION ; aucune n'est utilisable en production avant d'être CONFIRMED. Voir la section « Paramètres fiscaux » ci-dessous |

## Reports de la phase 2 (chiffrés)

Rééquilibrage validé le 17/11/2026 (`docs/plans/phase_2.md`, point budgétaire).

| Élément | Charge (j) | Contenu |
| --- | ---: | --- |
| Route publique du logo (D21) | 0,25 | Route en lecture seule, liste blanche, en-têtes de cache, sans `storage:link` ; tests |
| **Total des reports** | **0,25** | |

| Phase 3 | Charge (j) |
| --- | ---: |
| Référence (`docs/projet.md` §5) | 16 |
| Reports de la phase 2 | +0,25 |
| **Nouveau total** | **16,25** |
| **Écart** | **+0,25 j (+1,6 %)** |

L'écran des réglages de sécurité (Q76) fait partie du paramétrage prévu en phase 3 (module A, réglages) : pas de charge supplémentaire.

## Paramètres fiscaux (décision du 18/11/2026)

Source : spécifications du cabinet du 18/11/2026 (`docs/reference/cabinet/specifications_facturation_2026-11-18.md`) et analyse d'écarts (`docs/analyse_ecarts_cabinet.md`, E1 à E7). **La phase 3 ne démarre qu'après la validation du rapport de la phase 2 et de cette analyse.**

La table des paramètres fiscaux (aujourd'hui `taxes` dans `docs/modele_donnees.md`, à réviser dans le plan de la phase) doit avoir :

| Exigence | Conception prévue |
| --- | --- |
| Plusieurs taux | Une règle par taux ou montant fixe (`kind` : taux ou montant fixe), plusieurs règles actives à la fois |
| Natures taxable, exonérée, hors champ | Nature fiscale portée par la règle ; chaque catégorie de prestation (location, options, frais, dégâts, retenues) est liée à une nature et, si elle est taxable, à un taux |
| Dates de validité | `valid_from`, `valid_to` ; aucun chevauchement pour une même nature (contrôle sous verrou) |
| Historique des changements | Une règle n'est jamais modifiée sur place : un changement crée une nouvelle version datée ; journal d'audit de chaque création, version et changement de statut (qui, quand, avant, après) |
| Statut de validation par règle | **Révisé le 18/11/2026 (référentiel v1.0, §14)** : `CONFIRMED`, `CONDITIONAL`, `PENDING_DOCUMENTATION`. Passage à `CONFIRMED` par le super admin seulement, avec la référence du document déposé dans `docs/reference/regles_fiscales_validees/` (valideur, date, exemple chiffré qui devient un test) |
| Timbre fiscal | **Table `stamp_duty_rules`** (référentiel §3.4) : `rule_code`, `description`, `amount`, `applicability`, `exemption_condition`, `legal_reference`, `effective_from`, `effective_to` ; 1,000 DT par facture ordinaire concernée ; exonérations de l'article 118 seulement, en PENDING_DOCUMENTATION tant qu'elles ne sont pas documentées ; aucune exonération automatique d'une facture « impayée » ; tarif « grandes surfaces » 2026 jamais appliqué |
| Rapport de configuration fiscale | Écran et export (XLSX et PDF) de toutes les règles : valeur, nature, validité, statut, source, exemple chiffré ; remis au cabinet pour validation |

**Valeurs initiales** (révisées le 18/11/2026) : les **15 codes fiscaux** du référentiel v1.0 (`docs/analyse_ecarts_cabinet.md`, V2.3), chacun avec nature, taux, statut fiscal (taxable, exonérée, hors champ), référence réglementaire, justificatif requis ou non, validité et statut de validation selon `docs/projet.md` §8. Seeder idempotent. Les règles notées CONFIRMED par le référentiel ne sont **applicables** qu'une fois la confirmation nominative du cabinet enregistrée ; aucune correspondance n'est jamais faite à partir d'un libellé.

**Garde-fou : aucune règle non `CONFIRMED` en production.**

- `aviator:check-environment` : ligne « Règles fiscales ». En production, ERREUR si une règle active est `PENDING_DOCUMENTATION` ou si la confirmation du référentiel n'est pas enregistrée, avec la liste des règles en cause (codes seulement) ; une règle `CONDITIONAL` est admise, car son justificatif est exigé ligne par ligne (phase 6). Hors production : AVERTISSEMENT. Livré avec la table, en phase 3, avec ses tests.
- Phase 6 : refus d'émettre une facture en production si une règle appliquée n'est pas `CONFIRMED` ; filigrane « Règles fiscales non confirmées » sur les PDF hors production.
- Phase 10 : contrôle dans la liste de mise en production (`docs/plans/phase_10.md`).

| Élément (analyse d'écarts) | Charge (j) |
| --- | ---: |
| E2. Natures fiscales et lien prestation → traitement ; 15 codes avec référence réglementaire et justificatif requis (N1) | 0,75 |
| E3. Versions datées et historique | 0,5 |
| E5. Rapport de configuration fiscale | 0,5 |
| E6. Garde-fou dans `aviator:check-environment` | 0,25 |
| E7. Table `stamp_duty_rules`, conditions et exonérations du timbre (N3) | 0,75 |
| **Total, intégré au lot 1 le 18/11/2026** (demande d'évolution du client, à confirmer par la direction ; révisé selon le référentiel v1.0) | **2,75** |

| Phase 3 | Charge (j) |
| --- | ---: |
| Référence | 16 |
| Reports de la phase 2 | +0,25 |
| Demande d'évolution du cabinet (indispensable) | +2,75 |
| **Nouveau total** | **19** |
| **Écart** | **+3 j (+18,75 %)**, sous le seuil d'alerte de 20 %, sans marge |

### Tests de référence (référentiel v1.0)

| Test | Attendu |
| --- | --- |
| **Exemple chiffré de la §5** (test de référence du service de calcul) | Location 100,000 + GPS 10,000 + siège enfant 5,000, tous `taxable` 19 % : total HT **115,000** ; TVA **21,850** (sur la base agrégée par taux, arrondie à 3 décimales) ; TTC **136,850** ; timbre **1,000** ajouté après le TTC ; net à payer **137,850** |
| Aucune correspondance par libellé | Le paramétrage ne lie jamais un code fiscal à un libellé : une prestation dont le libellé contient « assurance » (toutes casses, avec ou sans accent) n'obtient ni `INSURANCE_PREMIUM` ni l'exonération |
| Statuts | Une règle `PENDING_DOCUMENTATION` n'est jamais renvoyée comme applicable ; contrôle de l'environnement en ERREUR en production, en AVERTISSEMENT ailleurs |
| Versions | Un changement de taux crée une nouvelle version datée ; une date antérieure retrouve l'ancienne version |
| Timbre | Une règle d'exonération de `stamp_duty_rules` en PENDING_DOCUMENTATION ne s'applique jamais |
