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
