# Phase 4 — Parc, leasing et réservations : éléments reportés

Ce document recueille ce qui a été décidé pendant les phases précédentes pour la phase 4. Le plan complet de la phase 4 sera établi au démarrage de la phase et soumis à validation (règle 1 du prompt maître).

## Reporté de la phase 2

| Élément | Origine | Détail |
| --- | --- | --- |
| **Recherche de clients (D24)** | Décision du 17/11/2026 : ne tient pas dans le budget de la phase 2 | Critères, harmonisation, limites et journalisation : voir D24 dans `docs/decisions.md`. Codée avec la réservation, qui en est le premier utilisateur |
| **Test « client bloqué à Sfax, refusé à Sousse »** | Décision du 17/11/2026 | Avec RG-15 (contrôle de la liste noire à la réservation, `CustomerRiskGate`) et une première version de la table `customer_recommendations` (niveau, statut, ajout seul). Le blocage vaut dans toutes les agences (K-10, K-11, scénario 14) |
| **Sélecteur d'agence active** | Lot 4 | Le service `CurrentAgency` est prêt ; ajouter le sélecteur dans le menu utilisateur avec les premiers documents d'agence |
| **Scénario 12 par type de document** | Lot 4 | Rejouer « un agent de Sfax modifie un document de Sousse : refus » pour les réservations (puis pour chaque nouveau type de document) |
| **Contrôleur des fichiers privés (D21)** | Proposition de rééquilibrage de la phase 2, à valider | Servir les documents des véhicules (carte grise, attestation d'assurance) depuis le disque `private`, par une route soumise aux policies ; à réutiliser en phase 5 (photos, signatures, pièces) |
| **Prototype du planning** | D5 | Prototype `guava/calendar` au début de la phase ; repli : frise Livewire maison |

## Reports de la phase 2 (chiffrés)

Rééquilibrage validé le 17/11/2026 (`docs/plans/phase_2.md`, point budgétaire).

| Élément | Charge (j) | Contenu |
| --- | ---: | --- |
| Contrôleur des fichiers privés (D21) | 0,5 | Route servant les fichiers du disque `private` après policy ; en-têtes ; tests d'accès par profil et par agence |
| Recherche de clients (D24) | 1 | Nom exact complet dans n'importe quel ordre, pièce, permis, téléphone ; `SearchNormalizer::phone` au format `+216XXXXXXXX` et recalcul de `customers.phone_search` ; 10 résultats ; journal sans la valeur ; 30 recherches par heure et notification au super admin ; tests |
| Migration `drivers` avancée de la phase 5 | 0,5 | Table, modèle, permis chiffré et empreinte `license_hash`, rechiffrement couvert (D23) ; tests |
| Sélecteur d'agence active | 0,25 | Choix de l'agence active dans le menu utilisateur (`CurrentAgency`) ; test |
| Test RG-15 entre agences | 0,5 | Première version de `customer_recommendations` (niveau, statut, ajout seul) et `CustomerRiskGate` ; « client bloqué à Sfax, refusé à Sousse » |
| **Total des reports** | **2,75** | |

Le scénario 12 rejoué sur les réservations et le prototype du planning (D5) font partie du périmètre déjà prévu de la phase 4 : pas de charge supplémentaire.

| Phase 4 | Charge (j) |
| --- | ---: |
| Référence (`docs/projet.md` §5) | 39 |
| Reports de la phase 2 | +2,75 |
| **Nouveau total** | **41,75** |
| **Écart** | **+2,75 j (+7,1 %)** |

### Effets sur les phases suivantes

| Phase | Effet | Charge (j) | Nouveau total | Écart avec `docs/projet.md` §5 |
| --- | --- | ---: | ---: | --- |
| 5. Contrats | La migration `drivers` en sort (faite en phase 4) | −0,5 | 29,5 | −0,5 j (−1,7 %) |
| 7. CRM | La création de la table `customer_recommendations` en sort (faite en phase 4) ; l'écran, les propositions de liste noire et les approbations restent en phase 7 | −0,25 | 20,75 | −0,25 j (−1,2 %) |
