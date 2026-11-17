# Estimation des phases 2 à 10 — Aviator (lot 1)

Estimation de fin de phase 1 (17/11/2026), comparée à la charge de référence (`docs/projet.md` §5 et feuille « Charge par phase » de `docs/reference/chiffrage_aviator.xlsx`). Elle repose sur l'architecture proposée (`docs/architecture.md`) et sur les **valeurs par défaut** de `docs/questions_ouvertes.md`. Les réponses aux questions peuvent la faire varier : voir §3.

## 1. Synthèse

| Phase | Référence (j) | Estimation (j) | Écart | Principaux facteurs |
| --- | ---: | ---: | ---: | --- |
| 2. Architecture et socle | 19 | 20 | +1 (+5 %) | Environ 60 tables (23 de plus que le §5.3), `Money`, `AgencyScope` multi-agences (Q69), empreintes HMAC ; en contrepartie, la 2FA est intégrée à Filament |
| 3. Paramétrage et référentiels | 16 | 15 | −1 (−6 %) | Ressources Filament simples ; pages de paramètres générées par `spatie/laravel-settings` |
| 4. Parc, leasing et réservations | 39 | 41 | +2 (+5 %) | Capacité par catégorie (C-05), table `vehicle_blocks`, 5 transitions ajoutées (Q29), prototype du planning sur `guava/calendar`, test de concurrence en processus séparé sous Windows |
| 5. Contrats et états des lieux | 30 | 33 | +3 (+10 %) | Approbations génériques (remise, retenue, dérogation) développées ici, schéma de dommages interactif sur tablette, compression des photos, 3 modèles PDF |
| 6. Facturation, encaissements, cautions | 31 | 30 | −1 (−3 %) | Mécanismes simples si les règles fiscales arrivent à temps ; sinon, développement « à blanc » et reprise (voir §3) |
| 7. CRM et recommandations | 21 | 23 | +2 (+10 %) | Fermeture des fuites du carnet (accès temporaires, sélecteur dédié, tests d'architecture), import avec rapport de doublons |
| 8. Maintenance, dépenses, exports | 20 | 19 | −1 (−5 %) | Maintenance déjà en partie couverte par `vehicle_blocks` (phase 4) ; exports dépendants de Q1 |
| 9. Statistiques, mobile et sécurité | 38 | 38 | 0 | 22 indicateurs, agrégats nocturnes, PWA, push Android et iPhone, revue de sécurité |
| 10. Recette et mise en production | 19 | 19 | 0 | Reprise des données (Q21), guides, sauvegarde et restauration |
| **Total des phases 2 à 10** | **233** | **238** | **+5 (+2,1 %)** | |
| Phase 1 (référence) | 12 | 12 | 0 | Voir le rapport de phase : ateliers avec les agences non encore réalisés |
| **Lot 1** | **245** | **250** | **+5 (+2,0 %)** | Aucune phase ne dépasse le seuil d'alerte de +20 % |

## 2. Hypothèses

- Un développeur Laravel senior assisté par Claude Code. Charges en jours de réalisation, hors aléas (provision de 15 % au chiffrage).
- Réponses conformes aux valeurs par défaut de `docs/questions_ouvertes.md` : retour à l'agence de départ, documents en français seulement, pas de facturation électronique.
- Règles fiscales (Q11, Q30) et format des exports (Q1) déposés **avant** les phases 6 et 8.
- Hébergement Oxahost conforme (cron chaque minute, Composer ; base MariaDB 10.11.19 confirmée, Q70) : sinon, prévoir une solution de repli (Q8, Q71).

## 3. Risques de dépassement (non inclus dans l'estimation)

| Risque | Question | Impact estimé | Phase |
| --- | --- | ---: | --- |
| Facturation électronique TEIF / TTN obligatoire | Q16 | +10 à +20 j (non chiffré au cahier) | 6 |
| Documents clients en arabe (mPDF, mise en page de droite à gauche) | Q5, Q75 | +3 à +5 j | 5, 6 |
| Restitution dans une autre agence et transferts entre agences | Q3, Q26 | +3 à +4 j | 4, 5, 6 |
| Règles fiscales en retard : développement à blanc puis reprise | Q11, Q30 | +2 à +4 j | 6 |
| Export au format d'import du logiciel comptable (H-08) | Q1 | +3 à +5 j | 8 |
| `exec` indisponible : sauvegarde en PHP pur | Q71 | +1 j | 9, 10 |
| Fichier client de mauvaise qualité (dédoublonnage manuel assisté) | Q18 | +2 à +4 j | 7 |
| Historique à reprendre pour les comparaisons N-1 | Q60 | +3 à +5 j | 10 |

**Seuil d'alerte** : la phase 6 dépasserait +20 % (plus de 37 j) si la facturation électronique est obligatoire. Q16 doit donc être tranchée avant la phase 6, et idéalement dès la phase 2.
