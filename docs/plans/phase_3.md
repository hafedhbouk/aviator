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
| Statut de validation par règle | `CONFIRMED`, `PROPOSED`, `PENDING_ACCOUNTANT_VALIDATION`. Passage à `CONFIRMED` par le super admin seulement, avec la référence du document déposé dans `docs/reference/regles_fiscales_validees/` (valideur, date, exemple chiffré qui devient un test) |
| Timbre fiscal | Montant fixe, ligne distincte, conditions d'application et exonérations paramétrables |
| Rapport de configuration fiscale | Écran et export (XLSX et PDF) de toutes les règles : valeur, nature, validité, statut, source, exemple chiffré ; remis au cabinet pour validation |

**Valeurs initiales** : celles du cabinet, saisies en `PROPOSED` ou `PENDING_ACCOUNTANT_VALIDATION` selon `docs/projet.md` §8, par un seeder idempotent qui ne crée jamais de règle `CONFIRMED`.

**Garde-fou : aucune règle non `CONFIRMED` en production.**

- `aviator:check-environment` : ligne « Règles fiscales ». En production, ERREUR si une règle active n'est pas `CONFIRMED`, avec la liste des règles en cause (codes seulement). Hors production : AVERTISSEMENT. Livré avec la table, en phase 3, avec ses tests.
- Phase 6 : refus d'émettre une facture en production si une règle appliquée n'est pas `CONFIRMED` ; filigrane « Règles fiscales non confirmées » sur les PDF hors production.
- Phase 10 : contrôle dans la liste de mise en production (`docs/plans/phase_10.md`).

| Élément (analyse d'écarts) | Charge (j) |
| --- | ---: |
| E2. Natures fiscales et lien prestation → traitement | 0,5 |
| E3. Versions datées et historique | 0,5 |
| E5. Rapport de configuration fiscale | 0,5 |
| E6. Garde-fou dans `aviator:check-environment` | 0,25 |
| E7. Conditions et exonérations du timbre | 0,5 |
| **Total proposé, à valider avec l'analyse** | **2,25** |

Si l'analyse est validée telle quelle, la phase 3 passe de 16,25 j à **18,5 j** (+15,6 % sur la référence de 16 j, sous le seuil d'alerte de 20 %).
