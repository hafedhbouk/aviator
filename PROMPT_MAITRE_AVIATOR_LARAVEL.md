# PROMPT MAÎTRE CLAUDE CODE — Aviator, application de location de voitures (Laravel, MySQL)

> **Mode d'emploi**
> 1. Placez ce kit à la racine du dépôt (le `CLAUDE.md` court y est déjà).
> 2. Dans Claude Code, demandez : *« Crée une skill `aviator-laravel` à partir de PROMPT_MAITRE_AVIATOR_LARAVEL.md, découpée en fichiers de référence comme pour la skill précédente. »* Désactivez ou supprimez l'ancienne skill `tunisia-car-rental-erpnext` pour ce projet.
> 3. Lancez la phase 1 avec le prompt de l'annexe A, puis chaque phase avec l'annexe B, une fois la précédente validée.
>
> La source fonctionnelle de référence est le cahier des charges Aviator, exporté en Markdown dans `docs/reference/cahier_des_charges_aviator.md`. En cas d'écart entre ce prompt et le cahier des charges, **le cahier des charges fait foi** et l'écart est signalé.

---

## 1. Rôle et posture

Tu es l'assistant de codage d'un **développeur Laravel expert**, responsable technique du projet. Il relit tout ce que tu produis. Tu développes pour Aviator, loueur tunisien de voitures (4 agences, 6 utilisateurs, environ 1 000 clients, 20 à 50 véhicules), une application Laravel en deux lots :

- **Lot 1** : application de gestion (back-office), modules A à K.
- **Lot 2** : espace client en ligne, module L, plus tard.

Règles de conduite non négociables :

1. **Plan avant code.** Chaque phase commence par un plan écrit, validé avant d'écrire du code.
2. **Rien n'est « fait » sans test exécuté.** Toute affirmation s'appuie sur une commande lancée et son résultat.
3. **Aucune règle fiscale inventée** : taux de TVA, timbre fiscal, mentions obligatoires, format de numérotation. Seuls les documents de `docs/reference/regles_fiscales_validees/` font foi (section 9).
4. **Signaler plutôt que deviner.** Une ambiguïté devient une question dans `docs/questions_ouvertes.md`, avec une valeur par défaut **paramétrable**, jamais codée en dur.
5. **Respect du budget.** Charge de référence : lot 1 = 245 jours, lot 2 = 45 jours (section 12). Une phase qui dépasse sa charge de plus de 20 % : arrête-toi et signale-le.
6. **Arrêt en fin de phase** : rapport dans `docs/rapports/phase_N.md` (section 13), puis attente d'une validation explicite.
7. **Hors périmètre, ne rien développer** : comptabilité générale, paiement en ligne, SMS, application native. Les architecturer pour plus tard si c'est demandé, sans les coder.

---

## 2. Pile technique

| Élément | Choix | Contrainte |
| --- | --- | --- |
| Framework | **Laravel 13** | Sécurité jusqu'au 17/03/2028 |
| PHP | **8.3** | Version maximale disponible chez Oxahost ; ne rien utiliser qui exige PHP 8.4 |
| Base de données | **MySQL 8**, InnoDB, `utf8mb4` | Fournie par Oxahost (version exacte à confirmer) ; poste en MySQL 8.4.3 : aucune fonctionnalité propre à 8.4 ; tests sur MySQL, pas SQLite (verrous de ligne) |
| Back-office | **Filament v5** (Livewire), panneau `/admin` | Responsive, utilisable sur smartphone par le super admin |
| Espace client (lot 2) | Blade + Livewire + Tailwind CSS, garde d'authentification `customer` séparée | Conçu d'abord pour le smartphone |
| Rôles et droits | `spatie/laravel-permission` + Policies | Contrôle serveur systématique |
| Journal d'audit | `spatie/laravel-activitylog` | Opérations sensibles et historique des documents |
| Notifications | Web Push (`laravel-notification-channels/webpush`, ou `minishlink/web-push` si incompatible) + canal `database` | Seul canal de notification ; pas de SMS ; e-mail seulement pour la sécurité des comptes |
| PWA | Manifeste + service worker | Application installable sur l'écran d'accueil |
| PDF | Bibliothèque PHP pure (dompdf ; mPDF si documents en arabe) | Aucun binaire externe sur le mutualisé |
| Files et tâches | Driver de file `database`, planificateur lancé par cron chaque minute | Pas de Redis, pas de processus permanent |
| Montants | `DECIMAL(15,3)` (dinar à 3 décimales), calculs avec `brick/math` | Jamais de `float` pour l'argent |
| Tests | Pest, base MySQL de test dédiée | Tests de concurrence obligatoires |
| Qualité | Laravel Pint, Larastan (niveau 6 au moins), `composer audit` | Bloquants en intégration continue |
| Poste de développement | Windows 11 + Laragon (PHP 8.3.16, MySQL 8.4.3), Git Bash | Projet dans `C:\laragon\www\aviator`, site local `https://aviator.test` ; commandes compatibles Git Bash ; fins de ligne LF |
| Hébergement | Oxahost Ultimate (mutualisé cPanel), Tunis | Production + recette en sous-domaine ; voir `docs/installation_hebergement_oxahost.md` |

Vérifie la compatibilité Laravel 13 / PHP 8.3 de chaque paquet **avant** de l'ajouter. Aucun paquet payant sans accord écrit.

---

## 3. Architecture et conventions

```
app/
├── Domain/                 # logique métier pure, testable sans HTTP
│   ├── Availability/       # disponibilité, verrous, chevauchements (RG-01, RG-02)
│   ├── Pricing/            # tarification, options, retards, km, carburant
│   ├── Billing/            # factures, avoirs, numérotation (RG-05 à RG-07)
│   ├── Deposits/           # cautions et mouvements (RG-08)
│   ├── Fleet/              # statuts véhicule (machine à états), leasing
│   ├── Crm/                # recommandations, liste noire, interactions
│   ├── Stats/              # agrégats mensuels et indicateurs
│   └── Exports/            # exports pour l'expert-comptable
├── Models/                 # Eloquent, sans logique métier lourde
├── Policies/               # une policy par modèle exposé
├── Filament/               # ressources, pages, widgets du back-office
├── Http/                   # contrôleurs de l'espace client (lot 2), webhooks
├── Notifications/          # notifications push et database
└── Support/                # Money, AgencyScope, helpers
```

- **Code, tables et colonnes en anglais** ; interface, messages, documentation **en français** (fichiers `lang/fr`).
- **Services d'action** (`ReserveVehicle`, `IssueInvoice`, `RefundDeposit`…) appelés par Filament **et** par l'espace client : aucune règle métier dans les ressources Filament ni dans les vues.
- **Transactions** autour de toute opération qui touche plusieurs tables.
- **Portée par agence** : global scope `AgencyScope` + policies ; un utilisateur d'agence ne voit en écriture que son agence (RG-13).
- **Paramètres métier** dans une table `settings` éditable par le super admin (délais, tolérances, seuils, alertes), jamais en constante.
- **Migrations réversibles**, jamais de modification manuelle du schéma en production.
- **Git** : une branche par phase (`phase/04-parc-leasing-reservations`), commits `feat:`, `fix:`, `test:`, `docs:`, `chore:` ; pas de commit qui casse les tests.
- **Secrets** uniquement dans `.env` (jamais commité) ; `.env.example` à jour.

---

## 4. Profils et droits

| Profil | Comptes | Portée | Points clés |
| --- | --- | --- | --- |
| `super_admin` | 1 | Toutes agences | Seul accès au **carnet d'adresses complet** et aux **exports de clients** ; approbations (remises, retenues, liste noire, dérogations) ; utilisateurs et droits |
| `admin` | 1 (siège, Djerba) | Toutes agences | Parc, leasing, tarifs, facturation, cautions, exports comptables, statistiques ; pas de carnet complet ni d'utilisateurs |
| `agency_user` | 4 (Djerba, Sfax, Sousse, Tunis) | Son agence en écriture ; disponibilité des autres agences en lecture | Réservations, contrats, états des lieux, encaissements, création de clients, recommandations |
| `customer` (lot 2) | Un par client | Ses propres données uniquement | Garde séparée ; jamais d'accès au back-office ni aux recommandations internes |

- Double authentification obligatoire pour `super_admin` et `admin`.
- **Carnet d'adresses (RG-14)** : la liste complète des clients, leurs coordonnées en masse et tout export sont réservés au `super_admin`. Les autres profils n'accèdent aux clients que par une **recherche exacte** (pièce, permis, téléphone, nom) qui renvoie les seules informations nécessaires au contrat. Vérifie aussi les fuites indirectes : recherche globale Filament, sélecteurs de relation, exports de tableaux, filtres, API. Chaque consultation du carnet et chaque export sont journalisés.
- Toute méthode exposée vérifie l'autorisation côté serveur ; masquer un bouton ne suffit jamais.

---

## 5. Modules et exigences

Le détail des exigences (identifiants, priorités P1/P2/P3) est dans le cahier des charges. Résumé :

| Module | Contenu | Lot |
| --- | --- | --- |
| A — Paramétrage | Société, 4 agences, catégories, grilles tarifaires (durée, saison), options, règles de réservation, numérotation, taxes validées, notifications | 1 |
| B — Parc et leasing | Fiche véhicule, kilométrage tracé, assurance et visite, alertes, statuts (machine à états), leasing et crédit (apport, échéancier, fin de contrat) | 1 |
| C — Réservations | Recherche de disponibilité, planning, création, modification, annulation, anti-chevauchement, expiration | 1 |
| D — Contrats | Contrat depuis réservation ou comptoir, conducteurs et permis, remises soumises à seuil, états des lieux sur tablette (photos, signature), prolongation, frais de retour, PDF | 1 |
| E — Facturation et encaissements | Factures, avoirs, numérotation continue, factures complémentaires, encaissements multi-modes, impayés, journal de caisse | 1 |
| F — Cautions | Réception, restitution, retenues approuvées, plafond, jamais en chiffre d'affaires | 1 |
| G — Maintenance et dépenses | Entretiens, réparations, sinistres, immobilisations, dépenses par véhicule, fournisseurs | 1 |
| H — Exports pour l'expert-comptable | Journaux des ventes, encaissements, cautions, dépenses ; Excel et CSV ; clôture de période | 1 |
| I — Statistiques | 22 indicateurs : charges par véhicule et par mois, apports, leasing, entretiens, coût au km, marge, rentabilité, point mort, utilisation, comparaison des agences, clients | 1 |
| J — Administration, mobile, notifications | Utilisateurs, rôles, 2FA, audit, import Excel, back-office sur smartphone, PWA, notifications push | 1 |
| K — CRM | Fiche client, carnet réservé, recherche limitée, doublons, import des ~1 000 clients, recommandations (recommandé, standard, vigilance, liste noire), alertes, interactions, réclamations, segments | 1 |
| L — Espace client | Compte, profil, documents, réservation **sans paiement**, suivi, prolongation, factures, caution, réclamations, avis, notifications push, pages publiques | 2 |

**Exclus** : comptabilité générale, paiement en ligne, SMS, applications natives, conception graphique par une agence.

---

## 6. Machine à états du véhicule

Statuts : `available`, `reserved`, `rented`, `maintenance`, `unavailable`, `retired` (sorti du parc, final). Le statut est **dérivé des opérations** par un service unique (`Fleet\VehicleStatusService`) et historisé (`vehicle_status_logs`). Toute transition non prévue lève une exception. Un véhicule dont l'assurance ou la visite technique est expirée ne peut pas partir en location. Une commande de contrôle signale tout écart entre le statut et les documents actifs.

| Statut | Entrée | Sorties autorisées |
| --- | --- | --- |
| Disponible | Création, retour clôturé, fin de maintenance | Réservé, Loué, Maintenance, Indisponible, Sorti |
| Réservé | Réservation confirmée avec véhicule affecté | Loué, Disponible |
| Loué | État des lieux de départ validé | Disponible, Maintenance |
| Maintenance | Ordre de maintenance ouvert | Disponible |
| Indisponible | Sinistre, immobilisation, assurance ou visite expirée | Disponible, Maintenance, Sorti |
| Sorti du parc | Vente ou mise au rebut | Aucune |

---

## 7. Règles de gestion (contrôles serveur obligatoires)

| ID | Règle | Mise en œuvre |
| --- | --- | --- |
| RG-01 | Pas de chevauchement sur un véhicule, délai de préparation compris | Requête d'intersection `start < other_end + buffer AND end + buffer > other_start` dans `Availability` |
| RG-02 | Robuste aux saisies simultanées | Transaction + `lockForUpdate()` sur la ligne du véhicule avant le contrôle ; test de concurrence avec deux connexions MySQL |
| RG-03 | Contrat issu d'une réservation confirmée ou d'une saisie comptoir contrôlée | Même service |
| RG-04 | Km retour ≥ km départ | Validation de l'état des lieux |
| RG-05 | Une période d'un contrat n'est facturée qu'une fois | Contrôle à l'émission |
| RG-06 | Facture émise jamais modifiée ni supprimée ; correction par avoir | Statut verrouillé, suppression interdite |
| RG-07 | Numérotation continue, chronologique, sans trou | Table `invoice_sequences` verrouillée, numéro attribué à l'émission |
| RG-08 | Restitué + retenu ≤ caution reçue ; pas de double restitution | Verrou sur la caution, recalcul serveur |
| RG-09 | Retards facturés au-delà de la tolérance paramétrée | `Pricing` |
| RG-10 | Remise au-delà du seuil soumise au super admin | Statut d'approbation + notification push |
| RG-11 | Documents validés annulés, jamais supprimés | Pas de suppression ; audit |
| RG-12 | Période exportée clôturée non modifiable | Contrôle de date sur factures, avoirs, encaissements |
| RG-13 | Utilisateur d'agence limité à son agence | `AgencyScope` + policies, testé par type de document |
| RG-14 | Carnet complet et exports clients réservés au super admin | Policies + journal d'audit |
| RG-15 | Client sur liste noire : pas de réservation ni de contrat sans dérogation | Contrôle dans `Availability` / réservation |
| RG-16 | Recommandations en ajout seul | Pas de mise à jour ni suppression |
| RG-17 | Une charge de véhicule rattachée à un seul mois, comptée une fois | Date d'imputation obligatoire, contrôle des doublons |
| RG-18 | Réservation en ligne (lot 2) = mêmes services qu'au comptoir | Aucun calcul côté interface |
| RG-19 | Un client ne voit que ses données | Contrôle d'appartenance sur chaque requête |
| RG-20 | Push uniquement aux appareils du destinataire, sans donnée sensible | Texte générique, détail après connexion |
| RG-21 | Réservation en ligne en attente expirée après délai | Tâche planifiée, client notifié |

Chacune a au moins un test dédié.

---

## 8. Données personnelles et sécurité

- Données hébergées en Tunisie uniquement (loi 2004-63, art. 52) ; ne jamais activer de sauvegarde ou de service qui copie les données à l'étranger.
- Pièces d'identité, permis, photos : disque `private` hors du dossier public, servis par une route contrôlée.
- Recommandations et liste noire : motifs factuels, durée de conservation paramétrée (en attente de la question correspondante).
- Protection CSRF, XSS, injection (requêtes liées), limitation des tentatives de connexion, en-têtes de sécurité, HTTPS forcé.
- Aucun secret dans le code, les migrations ou les seeders.

---

## 9. Règle impérative : taxes et factures

- **N'invente aucun taux de TVA, montant de timbre fiscal, mention obligatoire ou format de numérotation.**
- Seuls les documents de `docs/reference/regles_fiscales_validees/` peuvent être paramétrés, et chaque règle est reportée dans `docs/regles_fiscales.md` (référence, date d'effet, valideur, exemple chiffré).
- Tant qu'une règle manque, l'émission de facture affiche « Règle fiscale non validée » et refuse d'émettre en production ; une question bloquante est ajoutée.
- Les tests fiscaux reproduisent exactement les exemples chiffrés de l'expert-comptable ; sans exemple, test écrit et marqué `skip` avec la raison.
- Le format des exports (module H) est celui validé par l'expert-comptable (question Q1).

---

## 10. Contraintes de l'hébergement mutualisé

- **PHP 8.3 maximum** ; **pas de Redis**, **pas de Supervisor**, **pas de processus permanent**.
- Planificateur : un seul cron `* * * * * php artisan schedule:run`. La file `database` est traitée par le planificateur : `queue:work --stop-when-empty --max-time=50` chaque minute, `withoutOverlapping()`.
- Cache et sessions : driver `database` ou `file`.
- Les assets (Vite) sont compilés **avant** le déploiement ; le serveur n'exécute pas `npm`.
- Le document root du domaine pointe sur `public/` ; le reste du code n'est jamais exposé.
- Photos compressées à l'envoi ; alerte quand l'espace utilisé dépasse un seuil paramétré.
- Tu n'as **aucun accès** aux serveurs de recette et de production : tu prépares les commandes de déploiement, le responsable technique les exécute.

---

## 11. Tests et qualité

Pour chaque fonctionnalité : test d'abord ou pendant ; cas normal, erreurs, limites (minuit, changement de mois, bornes de dates) ; permissions par profil ; suite complète avant chaque commit.

Commandes de référence :

```bash
php artisan test                   # ou vendor/bin/pest ; --parallel si ParaTest fonctionne sous Windows
vendor/bin/pint --test
vendor/bin/phpstan analyse
composer audit
```

Scénarios de recette automatisés obligatoires (numéros du cahier des charges) :

1. Deux réservations simultanées, même véhicule, même période : une seule aboutit.
2. Réservation annulée : véhicule de nouveau disponible.
3. Prolongation avec contrôle de disponibilité.
4. Retour en retard : frais calculés selon les paramètres.
5. Facture réglée en deux paiements partiels.
6. Caution partiellement restituée après retenue approuvée.
7. Avoir partiel ; numérotation sans trou.
8. Modification d'une facture émise ou d'une période clôturée : refus.
9. Export d'un mois rapproché à l'identique des factures, avoirs et encaissements.
10. Véhicule à l'assurance expirée : départ refusé.
11. Utilisateur non autorisé sur une opération sensible : refus serveur.
12. Utilisateur de Sfax modifiant un contrat de Sousse : refus.
13. Admin ou utilisateur d'agence ouvrant le carnet complet ou exportant les clients : refus, tentative journalisée.
14. Client mis sur liste noire à Tunis : alerte à Djerba, réservation bloquée sans dérogation.
15. Loyer de leasing compté une seule fois dans les charges et la rentabilité.
16. Rentabilité d'un véhicule sur un mois identique au calcul manuel.
17. (Lot 2) Réservation en ligne et au comptoir simultanées : une seule aboutit.
18. (Lot 2) Client accédant à la facture d'un autre client : refus.
19. Demande de remise : push au super admin, sans montant dans le texte ; approbation depuis le téléphone.
20. (Lot 2) Client sur liste noire : réservation en ligne impossible, motif non affiché.
21. Rentabilité d'un véhicule consultée sur Android et iPhone, application installée.

---

## 12. Phases, charge de référence et critères d'acceptation

| Phase | Lot | Branche | Charge (j) | Critère d'acceptation |
| --- | --- | --- | --- | --- |
| 1. Analyse et maquettes | 1 | `phase/01-analyse` | 12 | Maquettes (comptoir, planning, CRM, statistiques mobile) et questions validées |
| 2. Architecture et socle | 1 | `phase/02-socle` | 19 | Socle déployé en recette ; 3 profils, portée par agence, audit, 2FA ; CI verte ; points Oxahost vérifiés |
| 3. Paramétrage et référentiels | 1 | `phase/03-parametrage` | 16 | Module A, conducteurs, fournisseurs saisis en recette |
| 4. Parc, leasing et réservations | 1 | `phase/04-parc-leasing-reservations` | 39 | Scénarios 1, 2, 10, 15 verts ; machine à états ; alertes |
| 5. Contrats et états des lieux | 1 | `phase/05-contrats` | 30 | Scénarios 3, 4 verts ; PDF ; saisie sur tablette |
| 6. Facturation, encaissements, cautions | 1 | `phase/06-facturation-cautions` | 31 | Scénarios 5 à 8 verts ; règles fiscales validées appliquées |
| 7. CRM et recommandations | 1 | `phase/07-crm` | 21 | Scénarios 13, 14 verts ; import des clients avec rapport de doublons |
| 8. Maintenance, dépenses, exports | 1 | `phase/08-maintenance-exports` | 20 | Scénario 9 vert ; exports acceptés par l'expert-comptable sur un mois |
| 9. Statistiques, mobile et sécurité | 1 | `phase/09-stats-mobile-securite` | 38 | Scénarios 11, 12, 16, 19, 21 verts ; PWA ; push Android et iPhone ; revue de sécurité |
| 10. Recette et mise en production du lot 1 | 1 | `phase/10-mise-en-production` | 19 | Recette signée ; reprise des données ; sauvegarde et restauration testées |
| 11. Espace client | 2 | `phase/11-espace-client` | 45 | Scénarios 17, 18, 20 verts ; parcours complet sur smartphone |

---

## 13. Rapport de fin de phase (`docs/rapports/phase_N.md`)

1. Exigences traitées (ID) avec statut : fait, partiel, non fait.
2. Fichiers créés ou modifiés.
3. Tests : commandes, nombre, résultats, tests ignorés et raison.
4. Écarts au cahier des charges et décisions (reportées dans `docs/decisions.md`).
5. Charge consommée estimée contre charge de référence.
6. Risques nouveaux.
7. Questions pour la direction ou l'expert-comptable.
8. Commandes de déploiement en recette à exécuter par le responsable technique.
9. Ce qu'il faut valider avant la phase suivante.

---

## Annexe A — Prompt de démarrage (phase 1)

```text
Charge la skill aviator-laravel et lis docs/projet.md et docs/reference/cahier_des_charges_aviator.md.

PHASE 1 — Analyse et maquettes. Tu ne crées aucun code applicatif.
1. Relis le cahier des charges et liste ses incohérences ou manques dans docs/questions_ouvertes.md (reprends Q1 à Q23 et ajoute les tiennes).
2. Propose le découpage technique : modèles, tables principales, services de domaine, ressources Filament, et le schéma entité-relation en Mermaid dans docs/architecture.md.
3. Décris les parcours utilisateurs prioritaires (comptoir de réservation, départ, retour, approbation du super admin sur smartphone, statistiques mobile) et produis des maquettes en wireframes (Markdown ou HTML statique) dans docs/maquettes/.
4. Vérifie la compatibilité Laravel 13 / PHP 8.3 / Filament v5 des paquets prévus et note toute incompatibilité.
5. Estime la charge des phases 2 à 10 et compare-la à la référence.
6. Rapport de phase, puis attends ma validation.
```

## Annexe B — Prompts des phases suivantes

**Phase 2 — Architecture et socle**
```text
Phase 1 validée. Décisions : [colle les décisions et réponses].
Branche phase/02-socle.
1. Installe Laravel 13 (PHP 8.3) dans C:\laragon\www\aviator sans écraser le kit (CLAUDE.md, docs/, scripts/), puis Filament v5, spatie/laravel-permission, spatie/laravel-activitylog, Pest, Pint, Larastan ; configure MySQL de dev (aviator_dev) et de test (aviator_test, fichier .env.testing) ; ajoute un .gitattributes forçant les fins de ligne LF.
2. Crée les agences, les 3 profils, AgencyScope, les policies de base, la 2FA du super admin et de l'admin, le journal d'audit.
3. Configure files et cache en driver database, le planificateur compatible mutualisé (section 10), le disque privé.
4. Mets en place l'intégration continue (tests, Pint, Larastan, composer audit) et un script de déploiement (scripts/deploy.sh) que j'exécuterai.
5. Tests de portée par agence et de droits. Rapport, puis attends ma validation.
```

**Phase 3 — Paramétrage et référentiels**
```text
Phase 2 validée. Branche phase/03-parametrage.
Module A complet, conducteurs, fournisseurs, table settings éditable par le super admin.
Taxes : uniquement depuis docs/reference/regles_fiscales_validees/ ; sinon champs vides et blocage de l'émission.
Rapport, puis attends ma validation.
```

**Phase 4 — Parc, leasing et réservations**
```text
Phase 3 validée. Branche phase/04-parc-leasing-reservations.
Modules B (dont leasing, apport, échéancier) et C, P1 d'abord.
Priorité : service Availability (RG-01, RG-02) avec test de concurrence sur deux connexions MySQL, puis VehicleStatusService.
Scénarios 1, 2, 10, 15 verts. Rapport, puis attends ma validation.
```

**Phase 5 — Contrats et états des lieux**
```text
Phase 4 validée. Branche phase/05-contrats.
Module D : contrats, conducteurs et permis, remises soumises au seuil (approbation super admin), états des lieux sur tablette (photos compressées, signature), prolongation, frais de retour, PDF.
Scénarios 3, 4 verts. Rapport, puis attends ma validation.
```

**Phase 6 — Facturation, encaissements, cautions**
```text
Phase 5 validée. Branche phase/06-facturation-cautions.
Règles fiscales validées disponibles : [liste]. Ce qui manque reste bloqué.
Modules E et F : numérotation (RG-07), avoirs, encaissements, journal de caisse, cautions (RG-08).
Scénarios 5 à 8 verts. Rapport, puis attends ma validation.
```

**Phase 7 — CRM et recommandations**
```text
Phase 6 validée. Branche phase/07-crm.
Module K : fiche client, carnet réservé au super admin (vérifie toutes les fuites indirectes), recherche limitée, doublons, import des ~1 000 clients avec rapport, recommandations et liste noire, alertes, interactions, réclamations.
Scénarios 13, 14 verts. Rapport, puis attends ma validation.
```

**Phase 8 — Maintenance, dépenses, exports**
```text
Phase 7 validée. Branche phase/08-maintenance-exports.
Format d'export validé par l'expert-comptable : [fichier].
Modules G et H ; clôture des périodes (RG-12).
Scénario 9 vert. Rapport, puis attends ma validation.
```

**Phase 9 — Statistiques, mobile et sécurité**
```text
Phase 8 validée. Branche phase/09-stats-mobile-securite.
1. Module I : 22 indicateurs, agrégats mensuels par véhicule recalculés chaque nuit, filtres mois / année / agence / véhicule, exports Excel.
2. Back-office sur smartphone pour le super admin, PWA installable, notifications push (RG-20), centre de notifications, préférences.
3. Revue de sécurité complète documentée dans docs/securite.md.
Scénarios 11, 12, 16, 19, 21 verts. Rapport, puis attends ma validation.
```

**Phase 10 — Recette et mise en production du lot 1**
```text
Phase 9 validée. Branche phase/10-mise-en-production.
Scripts de reprise idempotents (véhicules, contrats en cours, impayés, cautions) avec rapport d'anomalies, guides utilisateurs par profil, procédure de sauvegarde et restauration testée sur la recette, version v1.0.0 et liste de contrôle de mise en production. Tu ne déploies pas en production.
Rapport final du lot 1, puis attends ma validation.
```

**Phase 11 — Espace client (lot 2)**
```text
Lot 1 en production et validé. Branche phase/11-espace-client.
Module L sans paiement en ligne : garde customer, compte et profil, documents, réservation via les services existants (RG-18), suivi, prolongation, factures, caution, réclamations, avis, notifications push client, pages publiques. Langues : [réponse à Q5].
Scénarios 17, 18, 20 verts. Rapport, puis attends ma validation.
```
