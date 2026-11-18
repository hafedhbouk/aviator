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

**En attente de la décision de la direction.** Recommandée par l'analyse d'écarts (`docs/analyse_ecarts_cabinet.md`, E26, E27), déplacée de la phase 8 vers la phase 9 pour que la phase 8 reste sous le seuil d'alerte.

| Élément | Charge (j) | Contenu |
| --- | ---: | --- |
| E26. Préparation à la clôture mensuelle | 3 | Écran qui réunit les 12 contrôles du cabinet (factures, avoirs, encaissements, impayés, cautions non restituées, dépenses, justificatifs manquants, écarts de caisse, TVA collectée, devises, régularisations, cohérence contrats / factures / encaissements), avec un état et un lien vers chaque anomalie |
| E27. Réouverture exceptionnelle d'une période close | 0,5 | Super admin, motif obligatoire, journalisée, nouvelle version des exports (selon Q83) |
| **Total, si retenue** | **3,5** | |

| Phase 9 | Sans clôture | Avec clôture |
| --- | ---: | ---: |
| Référence | 38 | 38 |
| Purge des journaux | +0,5 | +0,5 |
| Clôture mensuelle | — | +3,5 |
| **Total** | **38,5** | **42** |
| **Écart** | **+0,5 j (+1,3 %)** | **+4 j (+10,5 %)** |
