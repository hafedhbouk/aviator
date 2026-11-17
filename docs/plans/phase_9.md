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
