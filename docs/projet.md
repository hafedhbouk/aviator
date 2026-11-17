# Fiche projet — Aviator (Laravel)

> Lue par Claude Code au début de chaque session. Mettez-la à jour à chaque validation de phase.
> Les valeurs `[À COMPLÉTER]` sont demandées au moment de la phase qui en a besoin.

Dernière mise à jour : 17/11/2026

## 1. Projet

| Élément | Valeur |
| --- | --- |
| Client | Aviator, location de voitures |
| Agences | Djerba (siège), Sfax, Sousse, Tunis |
| Utilisateurs | 1 super admin, 1 admin (siège), 4 utilisateurs d'agence |
| Clients existants | ~1 000, à reprendre dans le CRM |
| Lots | Lot 1 : application de gestion (phases 1 à 10) ; lot 2 : espace client (phase 11) |
| Hors périmètre | Comptabilité générale, paiement en ligne, SMS, application native |
| Cahier des charges | `docs/reference/cahier_des_charges_aviator.md` |
| Chiffrage | `docs/reference/chiffrage_aviator.xlsx` |

## 2. Technique

| Élément | Valeur |
| --- | --- |
| Framework | Laravel 13, Filament v5 |
| PHP | 8.3 (maximum disponible chez Oxahost) |
| Base de données | MariaDB 10.11.19 en production (confirmé par Oxahost le 17/11/2026) ; MariaDB 10.11 en local |
| Poste de développement | Windows 11 + Laragon (PHP 8.3.16, MariaDB 10.11), `C:\laragon\www\aviator`, `https://aviator.test` — `docs/installation_poste_dev.md` |
| Dépôt Git | [À COMPLÉTER] |
| Hébergement | Oxahost Ultimate (mutualisé cPanel), Tunis — `docs/installation_hebergement_oxahost.md` |
| Domaine | [À COMPLÉTER — question Q6] |
| Recette | [À COMPLÉTER — par exemple `recette.<domaine>`], protégée par mot de passe |
| Production | [À COMPLÉTER — par exemple `gestion.<domaine>`] ; espace client au lot 2 |
| Accès de Claude Code | Poste local uniquement ; **aucun** accès à la recette ni à la production |

## 3. Personnes

| Rôle | Nom | Valide |
| --- | --- | --- |
| Responsable technique et développeur | [À COMPLÉTER] | Toutes les phases ; déploiements |
| Super admin (direction d'Aviator) | [À COMPLÉTER — Q2] | Phases 1, 7, 9, 10, 11 ; approbations |
| Admin du siège (Djerba) | [À COMPLÉTER] | Phases 3, 4, 6 |
| Expert-comptable | [À COMPLÉTER] | Taxes des factures (phase 6), format des exports (phase 8) |
| Conseil juridique | [À COMPLÉTER] | Contrat, recommandations, conditions en ligne |

## 4. Budget de référence (scénario médian, HT)

| Élément | Valeur |
| --- | --- |
| Lot 1 | 245 j de réalisation ; 123 329 DT avec aléas, hébergement et validations externes |
| Lot 2 | 45 j de réalisation ; 22 390 DT |
| Exploitation annuelle | 13 019 DT |
| Seuil d'alerte | Phase dépassant sa charge de plus de 20 % |

## 5. Suivi des phases

| Phase | Lot | Charge prévue (j) | Charge réelle (j) | Statut | Validée par | Date | Réserves |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1. Analyse et maquettes | 1 | 12 | | À démarrer | | | |
| 2. Architecture et socle | 1 | 19 | | | | | |
| 3. Paramétrage et référentiels | 1 | 16 | | | | | |
| 4. Parc, leasing et réservations | 1 | 39 | | | | | |
| 5. Contrats et états des lieux | 1 | 30 | | | | | |
| 6. Facturation, encaissements, cautions | 1 | 31 | | | | | |
| 7. CRM et recommandations | 1 | 21 | | | | | |
| 8. Maintenance, dépenses, exports | 1 | 20 | | | | | |
| 9. Statistiques, mobile et sécurité | 1 | 38 | | | | | |
| 10. Recette et mise en production du lot 1 | 1 | 19 | | | | | |
| 11. Espace client | 2 | 45 | | | | | |
| **Total** | | **290** | | | | | |

Statuts : À démarrer · En cours · En attente de validation · Validée · Validée avec réserves.

## 6. Décisions

| Date | Décision | Prise par |
| --- | --- | --- |
| 17/11/2026 | Laravel plutôt qu'ERPNext (expertise du développeur, hébergement mutualisé, interface mobile, espace client) | Responsable technique |
| 17/11/2026 | Comptabilité tenue par l'expert-comptable à partir des exports | [À confirmer par la direction] |
| 17/11/2026 | Hébergement Oxahost Ultimate (mutualisé), recette en sous-domaine | Responsable technique |
| 17/11/2026 | Espace client en lot 2 ; pas de paiement en ligne ni de SMS ; notifications push uniquement | Direction |
| 17/11/2026 | Base MariaDB 10.11 en local, en test et en production ; connexion Laravel `mariadb` (production Oxahost en MariaDB 10.11.19, confirmé par Oxahost) | Responsable technique |

## 7. Points à confirmer avec Oxahost avant commande

| Point | Statut |
| --- | --- |
| Calendrier de disponibilité de PHP 8.4 | À confirmer |
| Cron toutes les minutes et Composer en SSH | À confirmer |
| Lieu de stockage des sauvegardes (Tunisie) | À confirmer |
| Prix annuel exact (358,80 ou 418,68 DT HT) | À confirmer |
| Version exacte de la base de données | Confirmé : MariaDB 10.11.19 |

## 8. Règles fiscales validées

| Règle | Document | Date d'effet | Statut |
| --- | --- | --- | --- |
| Taux de TVA sur la location, les options et les frais | — | — | Non fournie |
| Timbre fiscal | — | — | Non fournie |
| Mentions obligatoires et numérotation des factures | — | — | Non fournie |
| Format des exports pour l'expert-comptable | — | — | Non fourni |
| Facturation électronique (TEIF / TTN) | — | — | Non instruite |
