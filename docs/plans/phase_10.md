# Phase 10 — Recette et mise en production du lot 1 : éléments reportés

Le plan complet sera établi au démarrage de la phase et soumis à validation.

## Décidé le 18/11/2026 (spécifications du cabinet comptable)

| Élément | Charge en phase 10 | Détail |
| --- | ---: | --- |
| **Aucune règle fiscale non `CONFIRMED` en production** | 0 (contrôle de la liste de mise en production) | Avant la mise en production : `php artisan aviator:check-environment` sur la production ne signale aucune ERREUR sur la ligne « Règles fiscales » ; le rapport de configuration fiscale (phase 3) est signé par le cabinet ; chaque règle `CONFIRMED` renvoie à un document de `docs/reference/regles_fiscales_validees/`. Sinon, la mise en production est refusée |
| Facturation électronique (TEIF / TTN) | Selon Q16 | Si l'obligation s'applique à Aviator avant la mise en production, le connecteur (avenant, `docs/analyse_ecarts_cabinet.md` §3) doit être recetté avec l'environnement de test de TTN avant la date d'obligation |
