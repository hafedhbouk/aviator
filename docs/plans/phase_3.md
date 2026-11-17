# Phase 3 — Paramétrage et référentiels : éléments reportés

Ce document recueille ce qui a été décidé pendant les phases précédentes pour la phase 3. Le plan complet sera établi au démarrage de la phase et soumis à validation.

## Reporté de la phase 2

| Élément | Origine | Détail |
| --- | --- | --- |
| **Route publique du logo (D21)** | Proposition de rééquilibrage de la phase 2, à valider | Servir le logo de la fiche société (A-01) par une route dédiée, sans `storage:link` : lecture seule, liste blanche, en-têtes de cache |
| **Écran des réglages de sécurité** | Q76 | Rendre `security.require_2fa_for_agency_users` modifiable par le super admin, avec les autres groupes de réglages |
| **Bloquant fiscal** | Q11, Q30 | Taxes, mentions et numérotation des factures : la phase 3 reste bloquée sur ces points tant que l'expert-comptable n'a pas répondu |

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
