# Décisions — Aviator

Les décisions validées sont aussi reportées dans `docs/projet.md` §6. Statuts : **Proposée** (en attente de validation) · **Validée** · **Rejetée**.

## Décisions techniques proposées en phase 1 (17/11/2026)

| N° | Décision | Raison | Statut | Validée par |
| --- | --- | --- | --- | --- |
| D1 | Toute occupation d'un véhicule (réservation affectée, contrat, maintenance, immobilisation) est matérialisée dans une table `vehicle_blocks`, sur laquelle porte le contrôle RG-01 | Une seule requête d'intersection, indexée et testable, quelle que soit la source de l'occupation ; la maintenance bloque la disponibilité (G-03) | Proposée | |
| D2 | Deux contextes de domaine ajoutés au prompt maître : `Rental` (contrats) et `Approvals` (demandes au super admin) | RG-03, RG-04, RG-10, F-05 et K-11 ont des règles propres | Proposée | |
| D3 | Trois rôles fixes créés par seeder ; pas d'éditeur de rôles (`filament-shield` non retenu) | Moins de surface d'attaque ; matrice des droits lisible et testée dans le code | Proposée | |
| D4 | Pas de multi-tenancy Filament ; portée par agence via `AgencyScope` et les policies | Le super admin et l'admin travaillent sur toutes les agences | Proposée | |
| D5 | Planning sur `guava/calendar` (MIT), pas sur FullCalendar. **Prototype obligatoire au début de la phase 4** ; si la frise par véhicule ne convient pas, **repli : frise Livewire maison** (grille véhicules × jours) | La vue « timeline par ressource » de FullCalendar est payante | **Validée** (17/11/2026) | Responsable technique |
| D6 | `composer.json` avec `config.platform.php = 8.3.16` ; versions majeures compatibles PHP 8.3 : Pest 4, laravel-activitylog 4, laravel-backup `~10.3.0` | Les dernières versions majeures exigent PHP 8.4 (`docs/compatibilite_paquets.md`) | **Validée** (17/11/2026) | Responsable technique |
| D7 | Table `settings` gérée par `spatie/laravel-settings`, avec son extension Filament | Paramètres typés, valeurs par défaut posées par migration, écrans générés | Proposée | |
| D8 | Numéros de pièce et de permis chiffrés en base, recherche exacte par empreinte HMAC | Protection des données personnelles sans perdre la recherche exacte (K-04) | Proposée | |
| D9 | 2FA par l'authentification multifacteur intégrée à Filament v5 (application TOTP) | Pas de paquet supplémentaire | Proposée | |
| D10 | PDF avec dompdf ; mPDF seulement si des documents en arabe sont demandés | Licence LGPL au lieu de GPL ; suffisant pour le français | Proposée | |
| D11 | Fiche client accessible aux non-super-admins seulement après une recherche exacte (accès temporaire) ou par un document actif de leur agence | Empêche de parcourir le carnet en changeant le numéro de fiche dans l'adresse de la page (RG-14) | Proposée (paramètres : Q64) | |
| D12 | Base MariaDB 10.11 en local, en test et en production ; connexion Laravel `mariadb` (`DB_CONNECTION=mariadb`), interclassement `utf8mb4_unicode_ci` ; aucune fonctionnalité propre à MySQL 8 (index fonctionnels, index JSON, `joinLateral`, interclassements `utf8mb4_0900_*`) | La production Oxahost est en MariaDB 10.11.19 (confirmé par Oxahost le 17/11/2026, Q70) : le poste et les tests utilisent le même moteur | **Validée** (17/11/2026) | Responsable technique |
| D13 | **RG-14 précisée (Q27)** : voir ci-dessous | Lever l'ambiguïté entre le carnet d'adresses réservé au super admin et les listes métier de l'admin (ventes, impayés, statistiques, segments) | **Appliquée par défaut** (17/11/2026), **à confirmer par la direction** | Responsable technique |

## RG-14 précisée (D13, Q27)

> **RG-14** — Le **carnet d'adresses** est réservé au super admin. Il comprend :
> 1. les **coordonnées** des clients (téléphone, e-mail, adresse, numéro et image de la pièce d'identité, numéro et image du permis) **consultées en liste**, c'est-à-dire pour plusieurs clients à la fois ;
> 2. **tout export de fichier clients**, quel que soit l'écran d'origine.
>
> Chaque consultation du carnet et chaque export sont journalisés ; toute tentative refusée aussi.

Conséquences, appliquées par défaut :

| Sujet | Super admin | Admin | Utilisateur d'agence |
| --- | --- | --- | --- |
| Carnet d'adresses : liste avec coordonnées, filtres | Oui, journalisé | Non | Non |
| Export d'un fichier clients (Excel, CSV), depuis n'importe quel écran | Oui, journalisé | Non | Non |
| Noms des clients dans les listes métier : ventes, factures, impayés, meilleurs clients (I-19), segments (K-16), statistiques clients (I-18) | Oui | **Oui, sans coordonnées** | Noms dans les documents de son agence (contrats, factures, impayés), sans coordonnées ; ni segments ni statistiques clients |
| Exports comptables (module H) | Nom et matricule fiscal seulement | Nom et matricule fiscal seulement | Non |
| Exports de statistiques et de listes métier | Oui | Oui, **sans aucune colonne client** (agrégats uniquement) | Non |
| Fiche d'un client (coordonnées d'**un** client) | Oui | Après recherche exacte (accès temporaire, D11) | Après recherche exacte, ou contrat actif de son agence (Q4) |
| Envois groupés de notifications push aux clients (K-18, lot 2) | Oui, seul profil | Non | Non |

**Point à confirmer par la direction** : par prudence, un export de liste métier fait par l'admin (meilleurs clients, segments) est considéré comme un « fichier clients » dès qu'il contient des noms. L'admin peut donc **voir** ces noms à l'écran, mais pas les **exporter** ; ses exports ne contiennent que des agrégats. Si la direction veut autoriser l'export de ces noms par l'admin, il suffit de modifier la policy correspondante.
