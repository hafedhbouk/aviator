# Phase 10 — Recette et mise en production du lot 1 : éléments reportés

Le plan complet sera établi au démarrage de la phase et soumis à validation.

## Décidé le 18/11/2026 (spécifications du cabinet comptable)

| Élément | Charge en phase 10 | Détail |
| --- | ---: | --- |
| **Aucune règle fiscale non `CONFIRMED` en production** | 0 (contrôle de la liste de mise en production) | Avant la mise en production : `php artisan aviator:check-environment` sur la production ne signale aucune ERREUR sur la ligne « Règles fiscales » ; le rapport de configuration fiscale est signé par la direction (ligne suivante) ; chaque règle `CONFIRMED` renvoie à un document de `docs/reference/regles_fiscales_validees/`. Sinon, la mise en production est refusée |
| **Rapport de configuration fiscale signé par la direction** | 0 (procédure ; le rapport est une fonction livrée en phase 3, E5) | Rapport produit par l'application (fonction de la phase 3) sur la **base de production** : chaque code fiscal, chaque règle de timbre, taux, nature, dates de validité, statut (CONFIRMED, CONDITIONAL, PENDING_DOCUMENTATION), référence de validation et SHA-256 du référentiel validé, exemple chiffré de la §5. La direction le signe **avant** la mise en production ; l'original signé est conservé par Aviator et une copie est versée dans `docs/reference/regles_fiscales_validees/` (fichier nouveau, sans modifier les précédents). Sans rapport signé, la mise en production est refusée |
| Facturation électronique (TEIF / TTN) | Avenant séparé | Situation B (Q84) : pas un préalable à la mise en production ; vérifier avant la mise en service que la preuve du dépôt de la demande d'adhésion est conservée. Le connecteur doit être prêt dès la finalisation de l'adhésion |
