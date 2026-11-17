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
| Poste de développement | Windows 11 + Laragon (PHP 8.3.16, MariaDB 10.11) ; dépôt dans `D:\aviator`, jonction `C:\laragon\www\aviator` → `D:\aviator` pour Laragon ; `https://aviator.test` — `docs/installation_poste_dev.md` |
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
| 1. Analyse et maquettes | 1 | 12 | | Validée avec réserves | [À COMPLÉTER] | 17/11/2026 | 1) Ateliers avec les 4 agences, à tenir pendant la phase 2 et avant la phase 4 ; 2) Q27 (carnet d'adresses) appliquée par défaut, à confirmer par la direction ; 3) Q70 : bascule du poste et des tests en MariaDB 10.11, à vérifier en phase 2. Rapport : `docs/rapports/phase_1.md` |
| 2. Architecture et socle | 1 | 19 | | En cours | | | Projeté 18,5 j après rééquilibrage ; plafond accepté 19,5 j (`docs/plans/phase_2.md`) |
| 3. Paramétrage et référentiels | 1 | 16 | | | | | Projeté 16,25 j : reports de la phase 2 (`docs/plans/phase_3.md`) |
| 4. Parc, leasing et réservations | 1 | 39 | | | | | Projeté 41,75 j : reports de la phase 2 (`docs/plans/phase_4.md`) |
| 5. Contrats et états des lieux | 1 | 30 | | | | | Projeté 29,5 j : `drivers` avancé en phase 4 (`docs/plans/phase_5.md`) |
| 6. Facturation, encaissements, cautions | 1 | 31 | | | | | |
| 7. CRM et recommandations | 1 | 21 | | | | | Projeté 20,75 j : table des recommandations créée en phase 4 |
| 8. Maintenance, dépenses, exports | 1 | 20 | | | | | |
| 9. Statistiques, mobile et sécurité | 1 | 38 | | | | | |
| 10. Recette et mise en production du lot 1 | 1 | 19 | | | | | |
| 11. Espace client | 2 | 45 | | | | | |
| **Total** | | **290** | | | | | |
| **Écart cumulé du lot 1** (au 17/11/2026) | 1 | **245** prévus | **246,75** projetés | **+1,75 j (+0,7 %)** | | | Phases 3 à 10 : référence corrigée des reports chiffrés ; phase 1 comptée à sa référence (12 j, charge réelle à renseigner) ; phase 2 projetée à 18,5 j. Mise à jour à chaque fin de phase |

Statuts : À démarrer · En cours · En attente de validation · Validée · Validée avec réserves.

## 6. Décisions

| Date | Décision | Prise par |
| --- | --- | --- |
| 17/11/2026 | Laravel plutôt qu'ERPNext (expertise du développeur, hébergement mutualisé, interface mobile, espace client) | Responsable technique |
| 17/11/2026 | Comptabilité tenue par l'expert-comptable à partir des exports | [À confirmer par la direction] |
| 17/11/2026 | Hébergement Oxahost Ultimate (mutualisé), recette en sous-domaine | Responsable technique |
| 17/11/2026 | Espace client en lot 2 ; pas de paiement en ligne ni de SMS ; notifications push uniquement | Direction |
| 17/11/2026 | Base MariaDB 10.11 en local, en test et en production ; connexion Laravel `mariadb` (production Oxahost en MariaDB 10.11.19, confirmé par Oxahost) | Responsable technique |
| 17/11/2026 | Validation de la phase 1 avec réserves ; décisions techniques D1 à D13 (`docs/decisions.md`) | [À COMPLÉTER] |
| 17/11/2026 | D6 : `config.platform.php = 8.3.16` dans `composer.json` ; versions majeures compatibles PHP 8.3 (Pest 4, laravel-activitylog 4, laravel-backup `~10.3.0`) | Responsable technique |
| 17/11/2026 | D5 : planning sur `guava/calendar`, prototype obligatoire au début de la phase 4 ; repli : frise Livewire maison | Responsable technique |
| 17/11/2026 | D13 (RG-14, Q27) : le carnet d'adresses = coordonnées des clients en liste et tout export de fichier clients, réservés au super admin ; l'admin voit les noms dans les listes métier ; exports comptables limités au nom et au matricule fiscal ; envois groupés aux clients : super admin | Responsable technique ; **à confirmer par la direction** |
| 17/11/2026 | Dépôt dans `D:\aviator`, jonction `C:\laragon\www\aviator` pour Laragon | Responsable technique |
| 17/11/2026 | Q11, Q30 et Q16 envoyées à l'expert-comptable ; la phase 3 reste bloquée sur les taxes, mentions et numérotation | Responsable technique |
| 17/11/2026 | Ateliers avec les agences pendant la phase 2, avant la phase 4 ; maquettes publiées sur une page privée pour ces ateliers | Responsable technique |

## 7. Points à confirmer avec Oxahost avant commande

| Point | Statut |
| --- | --- |
| Version de PHP | Confirmé (17/11/2026) : PHP 8.3 avec PHP Selector (CloudLinux) ; certaines extensions peuvent être restreintes |
| Calendrier de disponibilité de PHP 8.4 | « Prochainement », sans date : le projet reste en PHP 8.3 |
| SSH : Git, Composer, mysqldump, tar | Confirmé (17/11/2026) |
| SSH depuis l'extérieur avec une clé, ou seulement le terminal cPanel | **Ouvert** (relance envoyée) ; variante manuelle du déploiement prévue (D22) |
| Cron toutes les minutes | **Ouvert** (relance envoyée) ; l'application supporte un cron toutes les 5 minutes (D20) |
| Fonctions `exec`, `proc_open`, `symlink` | **Ouvert** (relance envoyée) ; l'application n'en dépend pas (D14, D21) |
| Liste des extensions PHP restreintes | **Ouvert** (relance envoyée) ; contrôlée par `aviator:check-environment` |
| Lieu de stockage des sauvegardes (Tunisie) | Confirmé (17/11/2026) : JetBackup stocké en Tunisie |
| Sous-domaines avec racine web hors de `public_html`, SSL gratuit | Confirmé (17/11/2026) |
| Prix annuel exact (358,80 ou 418,68 DT HT) | À confirmer |
| Version exacte de la base de données | Confirmé : MariaDB 10.11.19 |

Les points encore ouverts seront vérifiés par le responsable technique pendant la garantie de 30 jours, notamment avec `php artisan aviator:check-environment`.

## 8. Règles fiscales validées

| Règle | Document | Date d'effet | Statut |
| --- | --- | --- | --- |
| Taux de TVA sur la location, les options et les frais | — | — | Non fournie ; demandée le 17/11/2026 (Q11) |
| Timbre fiscal | — | — | Non fournie ; demandée le 17/11/2026 (Q11) |
| Mentions obligatoires et numérotation des factures et des avoirs | — | — | Non fournie ; demandée le 17/11/2026 (Q11, Q30) |
| Format des exports pour l'expert-comptable | — | — | Non fourni |
| Facturation électronique (TEIF / TTN) | — | — | Demandée le 17/11/2026 (Q16) |
