# Plan de la phase 2 — Architecture et socle

| Élément | Valeur |
| --- | --- |
| Phase | 2. Architecture et socle (lot 1) |
| Branche | `phase/02-architecture-socle`, créée depuis `main` après la fusion de `phase/01-analyse`. Le prompt maître proposait `phase/02-socle` : je retiens le nom que vous avez demandé |
| Budget | **19 j** (seuil d'alerte : 22,8 j) |
| Statut | **Plan à valider avant tout code** |
| Critère d'acceptation | 3 profils, portée par agence, audit, 2FA ; intégration continue au vert ; points Oxahost vérifiés par `aviator:check-environment`. Le déploiement en recette est reporté à la fin de la phase, une fois le domaine connu (Q6) : il ne bloque pas la phase |

## 0. État du poste vérifié (17/11/2026)

| Contrôle | Résultat |
| --- | --- |
| Git sans exception commande par commande | ✅ `git status` fonctionne (`D:/aviator` est en `safe.directory` global) |
| Jonction `C:\laragon\www\aviator` | ✅ elle mène à `D:\aviator` |
| Fusion de `phase/01-analyse` dans `main` | ✅ avance rapide jusqu'à `3cd9ef5` ; branche `phase/02-architecture-socle` créée depuis `main` |
| Serveur de base local | ✅ `SELECT VERSION()` → **`10.11.20-MariaDB-log`** (Laragon `mariadb-10.11.20-winx64`) |
| Bases `aviator_dev` et `aviator_test` | ⚠️ elles existent et sont vides, mais sont en **`armscii8` / `armscii8_bin`** au lieu de `utf8mb4_unicode_ci`. Le serveur est par défaut en `latin1`. Correction proposée au §8 |
| Utilisateur `aviator@localhost` | ✅ présent |
| Client `mysql` dans le `PATH` de Git Bash | ⚠️ absent : j'utilise `C:\laragon\bin\mysql\mariadb-10.11.20-winx64\bin\mysql.exe` |

Incident pendant la fusion : un premier `git checkout main` a échoué sur « Permission denied » en écrivant `.git/HEAD`, probablement à cause d'un verrou passager de l'éditeur. Les fichiers avaient été remplacés par ceux de `main`. Je les ai restaurés depuis `HEAD` (`git restore`), sans perte ; la fusion a ensuite été faite par avance rapide, sans changer de branche.

## 1. Périmètre

### Dans la phase

1. Installation de Laravel 13, Filament v5 et des outils, sans écraser le kit (`CLAUDE.md`, `docs/`, `scripts/`).
2. **Modèle de données détaillé** de toutes les tables (`docs/modele_donnees.md` : colonnes, types, index, contraintes) ; migrations des seules tables du socle (§3).
3. Authentification, 3 profils, 2FA obligatoire pour le super admin et l'admin, commande `aviator:create-super-admin` (Q2), gestion des utilisateurs (J-01, J-02).
4. Agences, portée par agence (`AgencyScope`, RG-13), policies de base.
5. **RG-14 / D13** : couche d'accès aux données clients, assouplissable par une permission (Q27).
6. Journal d'audit (J-04, J-05).
7. Contraintes du mutualisé : file, cache et sessions en base, planificateur **sans `exec` ni `proc_open`**, disque privé, en-têtes de sécurité.
8. Briques transverses : `Money` (brick/math), allocateur de séquences avec **premier test de concurrence** sur deux connexions MariaDB.
9. Commande `aviator:check-environment` (Q8, Q71).
10. Intégration continue et liste exacte des commandes de première installation en recette.

### Hors phase

Les écrans métier (phases 3 à 9), les notifications push et la PWA (phase 9), la table `settings` éditable (phase 3 : le paquet est installé maintenant, sans écran) et tout déploiement : je n'agis sur aucun serveur.

## 2. Décisions proposées

| N° | Décision | Raison |
| --- | --- | --- |
| D14 | **Aucune dépendance à `exec`, `proc_open`, `shell_exec`, `system`, `passthru` ni `popen` dans l'application**, vérifiée par un test d'architecture. `spatie/laravel-backup` est **retiré** du projet. Sauvegardes : `scripts/deploy.sh` (mysqldump en SSH) et JetBackup ; J-08 devient une procédure documentée | Votre condition 3. `laravel-backup` exige `exec` |
| D15 | **Planificateur exécuté dans le processus** : tâches déclarées par `Schedule::call()` / `Schedule::job()`, pas par `Schedule::command()`, et `queue:work --stop-when-empty --max-time=50` appelé par `Artisan::call()` en dernier | `Schedule::command()` lance un sous-processus PHP (`proc_open`), interdit si la fonction est désactivée chez Oxahost |
| D16 | Permissions **déclarées dans le code** (`App\Support\Authorization\Permission`, une énumération), rôles et permissions synchronisés par la commande idempotente `aviator:sync-permissions`, sans compte utilisateur | Pas de compte dans les seeders (Q2) ; rejouable à chaque déploiement |
| D17 | Assouplir D13 = accorder une permission à un rôle avec `aviator:permission grant admin customers.export_names`, sans changer le code. Seules les permissions marquées « assouplissables » sont acceptées, et chaque changement est journalisé | Votre condition 4 ; le super admin n'a pas d'éditeur de rôles (D3) |
| D18 | Migrations du **socle seulement** en phase 2, plus la table `customers` (structure complète), nécessaire aux tests de D13. Les autres tables sont migrées dans leur phase, d'après `docs/modele_donnees.md` | Évite de réécrire des migrations avant que les règles métier ne soient connues |
| D19 | Mots de passe : 12 caractères au minimum, avec majuscules, minuscules, chiffres et symboles ; **pas** de vérification en ligne des mots de passe compromis (`uncompromised()`) | Cette vérification appelle un service étranger |

## 3. Tables migrées en phase 2

`users` (avec `agency_id`, `is_active`, champs 2FA de Filament), `agency_user`, `agencies` (4 agences créées par seeder : Djerba siège, Sfax, Sousse, Tunis ; données de référence, pas de compte), tables de `spatie/laravel-permission`, `activity_log`, `settings` (paquet `spatie/laravel-settings`), `sequences` (allocateur, §5.6), `customers` (structure seulement, sans écran : D18), `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `notifications`.

Toutes en InnoDB, `utf8mb4_unicode_ci`, compatibles MariaDB 10.11 (pas d'index fonctionnel ni d'index JSON).

## 4. Lots de travail et charge

| Lot | Contenu | Charge (j) |
| --- | --- | ---: |
| L1. Installation et outillage | Laravel 13 (`config.platform.php = 8.3.16`), Filament 5.11, laravel-permission 8, activitylog 4.12, laravel-settings 3, brick/math, Pest 4, Pint, Larastan (niveau 6) ; `.gitattributes` (LF), `.gitignore` (dont `.env.testing`) ; `.env.example` et `.env.testing.example` (`DB_CONNECTION=mariadb`, sans mot de passe) ; `phpunit.xml` **sans** les variables SQLite ; connexion `mariadb` (utf8mb4, `utf8mb4_unicode_ci`) ; fuseau `Africa/Tunis` ; langue `fr` | 1,5 |
| L2. Modèle de données | `docs/modele_donnees.md` (environ 60 tables) ; migrations et modèles du socle (§3) | 3 |
| L3. Authentification et utilisateurs | Panneau Filament `/admin` ; 2FA obligatoire pour `super_admin` et `admin` (authentification multifacteur intégrée à Filament, application TOTP, codes de récupération) ; `aviator:create-super-admin` ; `aviator:sync-permissions` ; `UserResource` (super admin : création, désactivation, réinitialisation du mot de passe, rôle, agences) ; limitation des tentatives de connexion | 3 |
| L4. Agences et portée | `Agency`, `BelongsToAgency`, `AgencyScope` (actif pour `agency_user` seulement), `CurrentAgency` (agence active si l'utilisateur en a plusieurs, Q69), policies de base | 2,5 |
| L5. Données clients (D13) | `CustomerDataAccess` et `CustomerPolicy` (§5.4), permissions assouplissables, journal `customer_access`, tests d'architecture contre les exports non autorisés | 1,5 |
| L6. Audit | `LogsActivity` sur `User`, `Agency`, rôles et permissions ; journaux `audit`, `security`, `customer_access` ; page « Journal d'audit » en lecture (super admin) | 1 |
| L7. Mutualisé et sécurité | File, cache et sessions en base ; planificateur dans le processus (D15) avec battement de cœur ; disque `private` ; en-têtes de sécurité ; HTTPS forcé hors local ; test d'architecture « pas d'exec » (D14) | 1,5 |
| L8. Briques transverses | `Money` et `MoneyCast` ; allocateur de séquences ; banc de concurrence (§5.6) | 1,5 |
| L9. Contrôle de l'environnement | `aviator:check-environment` (§5.5) et ses tests | 1 |
| L10. Intégration continue et installation | Workflow CI (PHP 8.3, service `mariadb:10.11`, Pint, Larastan, `composer audit`, Pest) ; commandes de première installation en recette ; relecture de `scripts/deploy.sh` (modifications **proposées**, pas appliquées sans votre accord) | 1 |
| L11. Rapport | `docs/rapports/phase_2.md`, mise à jour des documents | 0,5 |
| Aléas | | 1 |
| **Total** | | **19** |

## 5. Détails de conception

### 5.1 Comptes et 2FA (Q2)

- **Aucun compte dans les seeders.** Un test vérifie que `db:seed` ne crée aucun utilisateur.
- `php artisan aviator:create-super-admin` demande au clavier le nom, l'e-mail et le mot de passe (saisie masquée, confirmation, règles D19). Elle refuse un e-mail déjà utilisé et refuse de dépasser **2 super admins**, le second étant le compte de secours (limite modifiable : `config/aviator.php`, `max_super_admins`). Elle journalise la création dans `security` et n'affiche jamais le mot de passe.
- **2FA imposée à la première connexion** pour `super_admin` et `admin` : tant qu'elle n'est pas activée, toute page du panneau redirige vers l'écran d'activation. Si l'option native de Filament ne permet pas d'imposer la 2FA par rôle, un middleware le fera (point vérifié au début de L3).

### 5.2 Rôles et permissions

| Permission | super_admin | admin | agency_user | Assouplissable |
| --- | :---: | :---: | :---: | :---: |
| `users.manage`, `audit.view` | ✔ | | | |
| `customers.address_book` (coordonnées en liste) | ✔ | | | |
| `customers.export` (fichier clients complet) | ✔ | | | |
| `customers.export_names` (export de listes métier avec noms, sans coordonnées) | ✔ | | | ✔ (admin) |
| `customers.view_names` (noms dans les listes métier) | ✔ | ✔ | ✔ (documents de son agence) | |
| `customers.notify_bulk` (K-18) | ✔ | | | |
| `exports.accounting` (nom et matricule fiscal) | ✔ | ✔ | | |
| `agencies.all` (toutes agences) | ✔ | ✔ | | |

Les permissions métier des phases suivantes s'ajouteront à l'énumération. La matrice complète figure dans `docs/architecture.md` §8.1.

### 5.3 Portée par agence (RG-13)

- `AgencyScope` restreint les requêtes d'un `agency_user` à ses agences. Il est sans effet pour le super admin et l'admin.
- Les policies vérifient l'agence **même si le scope est contourné** (`withoutGlobalScopes`).
- Tests sur un modèle de test rattaché à une agence (migration de test chargée seulement par la suite de tests) : lecture, modification, accès direct par l'adresse de la page, utilisateur rattaché à deux agences. Le scénario 12 complet (contrat de Sousse) viendra avec les contrats, en phase 5.

### 5.4 Données clients (D13, assouplissable)

Le service `App\Support\Privacy\CustomerDataAccess` est le seul point de décision. Il est appelé par les policies, et plus tard par les ressources, les exports et les statistiques :

| Méthode | Règle par défaut |
| --- | --- |
| `canViewAddressBook(User)` | permission `customers.address_book` |
| `canExportCustomerFile(User)` | permission `customers.export` |
| `exportLevel(User, ExportKind)` | `full` (super admin), `names` (permission `customers.export_names`), `accounting` (nom et matricule fiscal, pour les exports comptables), sinon **`aggregates`** |
| `canViewNames(User, ?agencyId)` | `customers.view_names` ; l'utilisateur d'agence est limité à son agence |

Tout refus est journalisé dans `customer_access`. Tests : chaque profil × chaque méthode ; **assouplissement** (`aviator:permission grant admin customers.export_names`, puis l'admin obtient `names`, sans changement de code) ; refus journalisé (scénario 13, partie droits).

### 5.5 `aviator:check-environment` (Q8, Q71)

Commande en lecture seule, que vous exécuterez sur le serveur. Pour chaque contrôle : OK, AVERTISSEMENT ou ÉCHEC, avec un code de sortie non nul en cas d'échec.

| Contrôle | Attendu | Méthode, sans `exec` |
| --- | --- | --- |
| Version de PHP | 8.3.x | `PHP_VERSION` |
| Extensions | obligatoires : `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `gd`, `hash`, `iconv`, `intl`, `json`, `libxml`, `mbstring`, `openssl`, `pcre`, `pdo_mysql`, `session`, `simplexml`, `tokenizer`, `xml`, `xmlreader`, `xmlwriter`, `zip`, `zlib` ; recommandées : `exif`, `gmp`, `sodium` | `extension_loaded()` |
| Base de données | MariaDB 10.11.x, connexion `mariadb`, interclassement `utf8mb4_unicode_ci` | `SELECT VERSION()`, `@@collation_database` |
| Fonctions `exec`, `proc_open`, `symlink` | Disponibles : information seulement (D14). `symlink` sert à `storage:link` | `function_exists()` et `ini_get('disable_functions')` |
| Droits d'écriture | `storage/` et ses sous-dossiers, `bootstrap/cache` | Écriture puis suppression d'un fichier témoin |
| `mysqldump` (ou `mariadb-dump`) | Présent dans le `PATH` (utilisé par `deploy.sh`) | Parcours des dossiers du `PATH`, sans lancer de commande |
| Fuseau horaire | `Africa/Tunis` pour l'application ; `date.timezone` de PHP et fuseau de la base affichés | `config('app.timezone')`, `ini_get()`, `SELECT @@time_zone` |
| Planificateur (complément) | Dernier battement de moins de 2 min | Clé de cache écrite chaque minute par le planificateur |

### 5.6 Premier test de concurrence (RG-02, préparation de RG-01 et RG-07)

- `App\Support\Sequences\SequenceAllocator::next(string $key)` : dans une transaction, `lockForUpdate()` sur la ligne `sequences`, puis incrément. Il servira aux numéros `RES-` et `CTR-` (Q49), et plus tard à la numérotation des factures, une fois la règle fiscale validée.
- **Test** : le processus de test ouvre une transaction et verrouille la ligne ; un **second processus PHP**, sur la connexion `mariadb_concurrent`, tente d'allouer un numéro et reste bloqué. Le premier valide après 1 s ; le second obtient le numéro suivant. Le test vérifie l'attente, l'absence de doublon et l'absence de trou. Un second cas vérifie l'expiration du verrou (`innodb_lock_wait_timeout` réduit).
- Le second processus est lancé par `Symfony\Process`, dans les tests seulement (sur le poste et en CI ; jamais sur le serveur, D14). Ces tests utilisent `DatabaseTruncation` au lieu de `RefreshDatabase`, pour que les deux connexions voient les mêmes données validées.
- Ce banc sera réutilisé tel quel pour le scénario 1 (réservations simultanées) en phase 4.

## 6. Tests prévus (Pest, sur MariaDB `aviator_test`)

| Domaine | Tests |
| --- | --- |
| Environnement de test | La connexion est `mariadb` et `SELECT VERSION()` contient « MariaDB » et « 10.11 » (le test échoue sinon, pour ne jamais tourner sur SQLite) |
| Comptes | Commande `create-super-admin` : cas normal, e-mail existant, mot de passe faible, 3e super admin refusé ; seeders sans compte |
| 2FA | Super admin et admin redirigés vers l'activation tant que la 2FA est inactive ; agence non concernée |
| Droits | Matrice §5.2 par profil ; `sync-permissions` idempotente ; utilisateur désactivé refusé |
| Portée | §5.3 |
| D13 | §5.4, dont l'assouplissement et la journalisation |
| Audit | Création et modification d'utilisateur, changement de rôle, octroi de permission : entrée avec avant et après |
| Mutualisé | Tâches planifiées sans sous-processus ; disque privé hors de `public/` ; en-têtes de sécurité ; HTTPS forcé hors local |
| Architecture (Pest arch) | Pas de `exec` ni de fonctions voisines dans `app/` ; `app/Domain` ne dépend pas de Filament ; pas de `float` dans `Money` ; pas d'`ExportAction` hors des classes autorisées |
| Briques | `Money` (millimes, arrondis, opérations) ; `SequenceAllocator`, dont le test de concurrence |
| `check-environment` | Chaque contrôle en OK et en échec (sondes injectables) |

**Avant le premier test**, je vérifie la base locale : `mysql -u root -e "SELECT VERSION();"` doit renvoyer `10.11.x-MariaDB`.

Commandes de clôture : `php artisan test`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `composer audit`, `npm run build`.

## 7. Livrables

- Application Laravel 13 dans le dépôt.
- `docs/modele_donnees.md`.
- `docs/decisions.md` : D14 à D19.
- `docs/installation_hebergement_oxahost.md` : commandes de première installation, dont `aviator:check-environment`, `aviator:sync-permissions` et `aviator:create-super-admin`.
- `docs/rapports/phase_2.md`.

## 8. Ce dont j'ai besoin de votre part

1. **Validation de ce plan**, dont les décisions D14 à D19.
2. **Correction de l'interclassement des deux bases locales** (vides, donc sans risque). Je l'exécute à la validation du plan, sauf avis contraire :

   ```sql
   ALTER DATABASE aviator_dev  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ALTER DATABASE aviator_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

   La connexion Laravel impose de toute façon `utf8mb4_unicode_ci` à chaque table, et `aviator:check-environment` contrôlera l'interclassement de la base.
3. **Identifiants locaux** : je crée `.env` et `.env.testing` à partir des modèles, sans mot de passe ; vous y saisissez vos identifiants MariaDB (je ne lis ni ne commite ces fichiers).
4. **Intégration continue** : où est hébergé le dépôt `origin` (GitHub ?) ? « CI au vert » suppose de pousser la branche ; je ne pousse rien sans votre accord.
5. **Cron** : si Oxahost confirme que `proc_open` est disponible, D15 reste valable (elle ne coûte rien) ; on pourra revenir à `Schedule::command()` plus tard.

## 9. Risques

| Risque | Parade |
| --- | --- |
| La 2FA de Filament ne s'impose pas par rôle | Middleware dédié (prévu dans L3) |
| ParaTest instable sous Windows | Suite lancée en séquentiel |
| `proc_open` désactivé aussi en ligne de commande sur le serveur, ce qui gênerait Composer | Signalé par `check-environment` ; Composer peut tourner sur le poste et `vendor/` être envoyé (à étudier si besoin) |
| Q27 / D13 non confirmée par la direction | Assouplissement par permission, sans code (D17) |
| Domaine inconnu (Q6) | Déploiement en recette reporté à la fin de la phase ; commandes prêtes dans le rapport |

---

## Avenant 1 — réponse d'Oxahost (17/11/2026)

Oxahost a confirmé PHP 8.3 (PHP Selector, CloudLinux), SSH avec Git, Composer, mysqldump et tar, JetBackup en Tunisie, et des sous-domaines hors de `public_html` avec SSL gratuit. Restent ouverts : cron toutes les minutes, SSH depuis l'extérieur, `exec`, `proc_open`, `symlink` et la liste des extensions restreintes. La phase 2 s'adapte **sans dépasser 19 j**.

| N° | Décision | Lot | Mise en œuvre |
| --- | --- | --- | --- |
| D20 | **Cron toléré toutes les 5 minutes** | L7 | Toutes les tâches planifiées sont idempotentes et calées sur des minutes multiples de 5 (`:00`, `:05`…), ce qu'un test vérifie. La file est traitée à chaque passage (`--stop-when-empty --max-time=50`, sous verrou). Réglage `notifications.send_urgent_in_request` : quand il est actif, les notifications urgentes (demandes d'approbation, décisions) sont envoyées **pendant la requête** au lieu de passer par la file. Désactivé par défaut ; `aviator:check-environment` conseille de l'activer si la cadence mesurée est de 5 minutes |
| D21 | **Aucune dépendance à `symlink`** : pas de `storage:link` | L7 | CIN, permis, contrats, photos et signatures sur le disque `private` uniquement, servis par un contrôleur soumis aux policies. Les rares fichiers vraiment publics (logo) sont servis par une route dédiée (`/fichiers/public/{chemin}`), en lecture seule, avec liste blanche et en-têtes de cache. Aucun fichier client sur un disque accessible par le web |
| D22 | **Variante manuelle du déploiement** (terminal cPanel, sans SSH depuis le poste) | L10 | Annexe de `docs/installation_hebergement_oxahost.md` : commandes à coller dans le terminal cPanel. Les assets compilés sont envoyés en archive par le gestionnaire de fichiers, puis extraits avec `tar` |

### Délais maximaux en mode dégradé (cron toutes les 5 minutes)

| Traitement | Cron toutes les minutes | Cron toutes les 5 minutes |
| --- | --- | --- |
| Notification urgente, réglage `send_urgent_in_request` actif | Immédiate (pendant la requête) | Immédiate (pendant la requête) |
| Notification urgente, réglage inactif ; notification ordinaire | ≤ 1 min + traitement | **≤ 5 min + traitement**, soit environ 6 min. Si une file pleine dépasse 50 s de traitement, le reste part au passage suivant (+5 min) |
| Expiration des réservations (C-09, RG-21) | ≤ 5 min (tâche toutes les 5 min) | ≤ 5 min |
| Alertes de retard de restitution | ≤ 15 min | ≤ 15 min (tâche aux minutes `:00`, `:15`, `:30`, `:45`) |
| Alertes d'échéance quotidiennes, agrégats nocturnes | À l'heure prévue | À l'heure prévue, décalage d'au plus 5 min |

### Compléments de `aviator:check-environment` (L9)

- **Cadence réelle du planificateur** : chaque passage écrit son horodatage (les deux derniers sont conservés). La commande affiche l'âge du dernier passage et l'intervalle mesuré (1 min attendu ; 5 min = mode dégradé, avec le conseil d'activer `send_urgent_in_request` ; plus de 10 min ou aucun passage = ÉCHEC).
- **Liste complète** des extensions chargées et des fonctions désactivées (`disable_functions`), en plus des contrôles déjà prévus (§5.5).
- **Clés (D23)** : `APP_KEY` et `AVIATOR_BLIND_INDEX_KEY` présentes, non vides et bien formées (ÉCHEC sinon), **sans jamais afficher leur valeur** ; les clés VAPID s'ajouteront en phase 9.

### Budget

| Lot | Charge prévue | Changement |
| --- | ---: | --- |
| L1 (réalisé) | 1,5 | Réalisé en environ 1 j : **−0,5** |
| L2 (en cours) | 3 | Prévu à 2,5 j : **−0,5** |
| L7 | 1,5 | D20 et D21 : **+1** (2,5 j) |
| L9 | 1 | Cadence et listes : **+0,25** (1,25 j) |
| L10 | 1 | Variante manuelle : **+0,25** (1,25 j) |
| Aléas | 1 | Inchangé : **1 j** restant |
| **Total** | **19** | **19 j, inchangé** |

---

## Point budgétaire au 17/11/2026, après le lot 5

**Correction** : le tableau de l'avenant 1 annonçait un total inchangé de 19 j. C'est une erreur de calcul : −1 j sur les lots 1 et 2, mais +1,5 j sur les lots 7, 9 et 10, soit un total réel de **19,5 j**, aléas compris.

### Réalisé (estimation du temps passé)

| Lot ou travail | Prévu (j) | Réalisé (j) | Écart |
| --- | ---: | ---: | ---: |
| L1. Installation et outillage | 1,5 | 1 | −0,5 |
| L2. Modèle de données et migrations | 3 | 2,5 | −0,5 |
| Compléments du 17/11 : gestion des clés (D23), empreintes, CI | — | 0,75 | +0,75 |
| L3. Authentification et utilisateurs | 3 | 2,5 | −0,5 |
| Compléments du 17/11 : accès de secours, coupure des sessions, 2FA des agences | — | 1 | +1 |
| L4. Agences et portée | 2,5 | 2 | −0,5 |
| L5. Données clients (D13), `aviator:permission`, remplacements à durée limitée, règle hors Eloquent | 1,5 | 2,5 | +1 |
| **Total réalisé** | **11,5** | **12,25** | **+0,75** |

### Reste à faire (plan actuel)

| Lot | Prévu (j) |
| --- | ---: |
| L6. Audit | 1 |
| L7. Mutualisé et sécurité (D20, D21) | 2,5 |
| L8. Money, séquences, concurrence | 1,5 |
| L9. `aviator:check-environment` | 1,25 |
| L10. Installation et déploiement (CI déjà faite) | 1,25 |
| L11. Rapport | 0,5 |
| **Total restant** | **8** |
| Aléas restants | **0** (le jour d'aléas est absorbé par les compléments) |

**Projection : 12,25 + 8 = 20,25 j, soit 1,25 j (+6,6 %) au-delà des 19 j.** C'est sous le seuil d'alerte de +20 % (22,8 j), mais au-delà du budget. Le lot 6 n'est pas commencé.

### Proposition de rééquilibrage, à valider

| Lot | Actuel (j) | Proposé (j) | Changement |
| --- | ---: | ---: | --- |
| L6. Audit | 1 | 1 | Inchangé |
| L7. Mutualisé et sécurité | 2,5 | 1,5 | Le contrôleur des fichiers privés (D21) passe en **phase 4**, avec les premiers documents de véhicule ; la route du logo passe en **phase 3**, avec la fiche société (A-01). En phase 2, D21 se limite à ne jamais utiliser `storage:link`, plus un test d'architecture |
| L8. Money, séquences, concurrence | 1,5 | 1,5 | Inchangé (premier test de concurrence) |
| L9. `aviator:check-environment` | 1,25 | 1 | Contrôles du plan et de l'avenant, sans option supplémentaire |
| L10. Installation et déploiement | 1,25 | 0,75 | La CI est faite (lot 1) ; restent les commandes de première installation, l'annexe manuelle (D22) et la relecture de `deploy.sh` |
| L11. Rapport | 0,5 | 0,5 | Inchangé |
| **Total restant** | **8** | **6,25** | |
| **Total de la phase** | **20,25** | **18,5** | **0,5 j d'aléas** |

À partir de maintenant, aucun ajout en phase 2 : toute nouvelle idée va dans le plan de la phase concernée (`docs/plans/phase_N.md`).

### Décision du 17/11/2026

Rééquilibrage **validé** (L7 −1 j, L9 −0,25 j, L10 −0,5 j). Reports chiffrés dans `docs/plans/phase_3.md`, `docs/plans/phase_4.md` et `docs/plans/phase_5.md` ; écart cumulé du lot 1 dans `docs/projet.md` §5.

**Règle pour la suite de la phase** : les aléas sont épuisés. Si un lot dépasse sa charge, aucun test n'est supprimé et aucun niveau de contrôle n'est abaissé : le dépassement est signalé. Plafond accepté par le responsable technique : **19,5 j**.

## Avancement au 18/11/2026, après le lot 10

| Lot ou travail | Prévu (j) | Réalisé (j) |
| --- | ---: | ---: |
| Réalisé jusqu'au lot 7 (point précédent) | — | 14,75 |
| `composer check` (contrôles identiques poste et CI) | — | 0,25 |
| L8. Money, séquences, concurrence (interblocage corrigé) | 1,5 | 1,5 |
| Règle des documents numérotés (demande du 18/11) | — | 0,25 |
| L9. `aviator:check-environment` | 1 | 1 |
| L10. Première installation de la recette (`docs/installation_recette.md`, annexe A, propositions pour `deploy.sh`) | 0,75 | 0,75 |
| **Total réalisé** | | **18,5** |
| L11. Rapport (après l'installation de la recette par le responsable technique) | 0,5 | — |
| **Projection de la phase** | | **19** |

Les aléas sont épuisés ; la phase tient dans les 19 j de référence, sous le plafond accepté de 19,5 j.
