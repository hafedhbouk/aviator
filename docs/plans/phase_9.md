# Phase 9 — Statistiques, mobile et sécurité : éléments reportés

Le plan complet sera établi au démarrage de la phase et soumis à validation.

## Ajouts décidés pendant la phase 2

| Élément | Charge (j) | Contenu |
| --- | ---: | --- |
| **Purge des journaux (Q77)** | 0,5 | Tâche planifiée **quotidienne**, à une minute multiple de 5 (D20), par exemple **03:15**, exécutée dans le processus du planificateur (D15). Durées de conservation paramétrables par journal, dans un groupe de réglages : `audit` 5 ans, `security` 1 an, `customer_access` (dont les recherches de clients, D24) 1 an, en attendant la réponse à Q77. Purge **désactivée** tant que les durées ne sont pas validées. Suppression par lots (pour ne pas bloquer la base), puis une entrée de synthèse dans le journal `security` (nombre d'entrées supprimées par journal, sans contenu). **Tests** : entrées plus anciennes que la durée supprimées, plus récentes conservées, bornes à la date près (fuseau Africa/Tunis), durée vide = aucune purge, journal non paramétré jamais purgé, planification à une minute multiple de 5, synthèse journalisée |
| **Total** | **0,5** | |

| Phase 9 | Charge (j) |
| --- | ---: |
| Référence (`docs/projet.md` §5) | 38 |
| Ajouts | +0,5 |
| **Nouveau total** | **38,5** |
| **Écart** | **+0,5 j (+1,3 %)** |

## Demande d'évolution du cabinet : clôture mensuelle (18/11/2026)

**Indispensable depuis le référentiel fiscal v1.0 du cabinet (§11), qui l'exige** (18/11/2026). Analyse : `docs/analyse_ecarts_cabinet.md`, E26, E27 et N6. Placée en phase 9 pour que la phase 8 reste sous le seuil d'alerte.

| Élément | Charge (j) | Contenu |
| --- | ---: | --- |
| E26. Préparation à la clôture mensuelle | 3 | Écran qui réunit les 12 contrôles du cabinet (factures, avoirs, encaissements, impayés, cautions non restituées, dépenses, justificatifs manquants, écarts de caisse, TVA collectée, devises, régularisations, cohérence contrats / factures / encaissements), avec un état et un lien vers chaque anomalie |
| E27. Réouverture exceptionnelle d'une période close | 0,5 | Super admin, motif obligatoire, journalisée, nouvelle version des exports (selon Q83) |
| **Total** | **3,5** | |

Le contrôle « opérations en devises » est **sans objet** (D26 : dinar uniquement) ; il est affiché comme tel, sans calcul.

## Écran de numérotation (D27, arbitrage du 18/11/2026)

| Élément | Charge (j) | Contenu |
| --- | ---: | --- |
| Écran de numérotation | 1 | Déplacé de la phase 6. Préfixe et format modifiables par le **super admin seulement**, depuis l'administration ; changement journalisé (avant, après, qui, quand), appliqué aux **prochains numéros** seulement ; refus d'un format sans `{SEQUENCE}` ou pouvant produire un numéro déjà attribué, dans sa série ou dans l'autre ; format avec l'année proposé seulement si la confirmation écrite du cabinet est enregistrée. Il reprend les contrôles de la commande Artisan de la phase 6 |

| Phase 9 | Charge (j) |
| --- | ---: |
| Référence | 38 |
| Purge des journaux | +0,5 |
| Clôture mensuelle (exigence du cabinet) | +3,5 |
| Écran de numérotation (D27) | +1 |
| **Total** | **43** |
| **Écart** | **+5 j (+13,2 %)** |
