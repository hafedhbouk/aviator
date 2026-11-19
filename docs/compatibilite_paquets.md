# Compatibilité des paquets — Laravel 13 / PHP 8.3 / Filament v5

Vérification du 17/11/2026 (phase 1). À refaire avant chaque ajout de paquet et à chaque montée de version.

## 1. Méthode

1. Métadonnées de chaque paquet lues sur Packagist (`repo.packagist.org/p2/<paquet>.json`) : contraintes `php`, `illuminate/*` et `filament/*` des dernières versions.
2. **Résolution réelle par Composer 2.8.9** d'un `composer.json` de test regroupant tous les paquets prévus, avec la plateforme forcée à `php: 8.3.16` (`config.platform`), dans un dossier temporaire hors du dépôt. Résultat : **solution trouvée, 199 paquets**.
3. `composer audit --locked` sur le lock obtenu : **aucune vulnérabilité connue**.
4. Inventaire des licences et des extensions PHP exigées, à partir du lock.

Aucun code applicatif n'a été créé dans le dépôt.

## 2. Résultat par paquet

| Paquet | Usage | Dernière version | Version retenue (PHP 8.3) | Contrainte proposée | Statut |
| --- | --- | --- | --- | --- | --- |
| `laravel/framework` | Framework | 13.42.0 (PHP ^8.3) | 13.42.0 | `^13.0` | ✅ |
| `filament/filament` | Back-office (Livewire 4.4.8) | 5.11.4 (PHP ^8.2) | 5.11.4 | `^5.11` | ✅ |
| `spatie/laravel-permission` | Rôles et permissions | 8.5.0 (PHP ^8.3) | 8.5.0 | `^8.5` | ✅ |
| `spatie/laravel-activitylog` | Journal d'audit | **5.1.5 exige PHP 8.4** | 4.12.3 (Laravel ^13 accepté) | `^4.12` | ⚠️ version majeure précédente |
| `laravel-notification-channels/webpush` | Notifications push | 13.0.1 (Laravel ^13.13) | 13.0.1 | `^13.0` | ✅ |
| `minishlink/web-push` | Bibliothèque Web Push (dépendance) | 11.0.0 (PHP >=8.2) | 11.0.0 | via webpush | ✅ (repli inutile) |
| `barryvdh/laravel-dompdf` + `dompdf/dompdf` | PDF (français) | 3.1.2 + 3.1.6 | identiques | `^3.1` | ✅ licence LGPL-2.1 |
| `mpdf/mpdf` | PDF en arabe (si Q5 = oui) | 8.3.1 (PHP ≤ 8.5) | 8.3.1 | `^8.3` | ✅ technique ; ⚠️ **licence GPL-2.0** (Q75) |
| `brick/math` | Calculs monétaires | 1.0.0 (Laravel accepte ^1.0) | 1.0.0 | `^1.0` | ✅ |
| `spatie/laravel-settings` + `filament/spatie-laravel-settings-plugin` | Table `settings` éditable | 3.10.2 + 5.11.4 | identiques | `^3.10`, `^5.11` | ✅ |
| Authentification multifacteur | 2FA (J-03) | intégrée à Filament v5 | `pragmarx/google2fa` 9.1.0 et `google2fa-qrcode` 4.0.0, tirés par Filament | aucun paquet à ajouter | ✅ |
| `guava/calendar` | Planning (frise par véhicule) | 3.2.2 (Filament ^5, MIT, basé sur `vkurko/calendar`) | 3.2.2 | **`3.2.2` (figée, D35)** | ✅ installé le 19/11/2026 (phase 4, lot L0) ; vues *resource timeline* semaine et mois confirmées par le prototype ; JavaScript livré avec le paquet, aucun CDN. **Version figée** : trois contournements (sous-lignes converties côté serveur, blocs « Occupé » non cliquables, fuseau), chacun gardé par un test (lot L12) qui échoue si une mise à jour change le comportement du paquet. Toute montée de version : relancer ces tests, relire `docs/rapports/tickets_guava_calendar.md` (tickets chez le mainteneur), retirer un contournement seulement quand son test montre le correctif. D35 validée avec conditions le 19/11/2026 |
| `saade/filament-fullcalendar` | Planning (alternative) | 5.0.0 (Filament ^5) | — | — | ❌ non retenu : la vue « timeline par ressource » de FullCalendar est **payante** |
| `saade/filament-autograph` | Signature sur tablette | 4.3.2 (Filament ^5, MIT) | 4.3.2 | `^4.3` | ✅ |
| `intervention/image-laravel` + `intervention/image` | Compression des photos | 4.1.1 + 4.4.1 (PHP ^8.3) | identiques | `^4.1` | ✅ pilote GD |
| `maatwebsite/excel` | Exports Excel (module H, statistiques) | 4.0.6 (PHP ^8.3, Laravel ^13) | 4.0.6 | `^4.0` | ✅ facultatif : les imports et exports intégrés à Filament (OpenSpout) suffisent peut-être ; choix en phase 8 |
| `openspout/openspout` | Imports et exports de Filament (dépendance) | **5.14.1 exige PHP 8.4** | 4.32.0 (Filament exige `^4.23`) | via Filament | ⚠️ voir §3 |
| `spatie/laravel-backup` | Sauvegarde déclenchable (J-08) | **10.4.0 exige PHP 8.4** | 10.3.3 | `~10.3.0` | ⚠️ à épingler ; dépend aussi de `exec` et de `mysqldump` (Q71) |
| `bezhansalleh/filament-shield` | Éditeur de rôles | 4.3.1 (Filament ^5) | — | — | Compatible, **non retenu** (décision D3) |
| **Développement** | | | | | |
| `pestphp/pest` + `pest-plugin-laravel` | Tests | **5.3.7 exige PHP 8.4** | 4.7.8 + 4.1.0 (PHPUnit 12.5.33) | `^4.7`, `^4.1` | ⚠️ version majeure précédente |
| `brianium/paratest` | Tests parallèles | 7.20.0 | 7.20.0 | via Pest | ✅ fonctionnement sous Windows à vérifier en phase 2 |
| `larastan/larastan` | Analyse statique (PHPStan 2.3.6) | 3.15.0 | 3.15.0 | `^3.15` | ✅ |
| `laravel/pint` | Formatage | 1.32.3 (PHP ^8.3) | 1.32.3 | `^1.32` | ✅ |
| PWA | Manifeste + service worker | — | écrits à la main | aucun paquet | ✅ |

**Exclus d'office** (processus permanent ou Redis, impossibles en mutualisé) : Horizon, Octane, Reverb, Pulse avec Redis, et tout pilote de file autre que `database`.

## 3. Incompatibilités et risques

1. **Quatre paquets ont déjà une version majeure qui exige PHP 8.4** : Pest 5, laravel-activitylog 5, laravel-backup 10.4 et OpenSpout 5. Il existe une version compatible PHP 8.3 pour chacun, mais le projet restera **une version majeure en retard** tant qu'Oxahost ne proposera pas PHP 8.4.
2. **Le `composer.json` doit fixer `"config": { "platform": { "php": "8.3.16" } }`** (phase 2). Sans cela, un `composer update` lancé sur une machine en PHP 8.4 (poste, intégration continue) produirait un lock inutilisable sur Oxahost. L'intégration continue doit tourner en PHP 8.3.
3. **Filament dépendra probablement d'OpenSpout 5**, et donc de PHP 8.4, dans une future version 5.x. Il faudra alors épingler Filament sur la dernière version compatible PHP 8.3 jusqu'à la migration.
4. **Les correctifs de sécurité de PHP 8.3 s'arrêtent le 31/12/2027**, alors que Laravel 13 est maintenu jusqu'au 17/03/2028. La date de PHP 8.4 chez Oxahost (Q8) conditionne la maintenance.
5. **Licences** : 190 paquets sur 199 sont sous MIT ou BSD. dompdf est sous LGPL (sans contrainte pour un usage interne). **mPDF est sous GPL-2.0-only** : sans contrainte tant que l'application n'est pas distribuée à des tiers, mais à accepter explicitement avant de l'ajouter (Q75). Aucun paquet payant.
6. **`spatie/laravel-backup`** suppose `exec` / `proc_open` et un binaire de sauvegarde (`mariadb-dump` ou `mysqldump` selon la version de `spatie/db-dumper` ; à vérifier en phase 9), souvent désactivés en mutualisé (Q71). Repli : export SQL en PHP pur, plus JetBackup d'Oxahost. Dans tous les cas, les sauvegardes restent **en Tunisie** : aucun disque de destination à l'étranger.

7. **Base MariaDB 10.11** (production Oxahost en 10.11.19, D12) : Laravel 13 la prend en charge par sa connexion `mariadb`. La version 10.11 est une version à support long, maintenue jusqu'en février 2028, soit à peu près jusqu'à la fin du support de Laravel 13 (03/2028). Aucun des paquets retenus n'exige MySQL 8. Le poste et les tests utilisent MariaDB 10.11 ; la compatibilité réelle sera vérifiée en phase 2, au premier `php artisan migrate` et au premier `php artisan test`.

## 4. Extensions PHP nécessaires

Extensions exigées par les paquets résolus : `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `gd`, `hash`, `iconv`, `intl`, `json`, `libxml`, `mbstring`, `openssl`, `pcre`, `session`, `simplexml`, `tokenizer`, `xml`, `xmlreader`, `xmlwriter`, `zip`, `zlib`, plus `pdo_mysql` (Laravel ; pilote utilisé aussi pour MariaDB) et `bcmath` (recommandée). `exif` est recommandée pour l'orientation des photos.

- **Poste local (Laragon PHP 8.3.16)** : toutes présentes (`php -m`, vérifié le 17/11/2026).
- **Oxahost** : la liste de `docs/installation_hebergement_oxahost.md` (§1) ne mentionne pas `iconv`, `simplexml`, `xmlreader`, `xmlwriter` ni `exif`. Elles sont souvent actives par défaut, mais il faut le vérifier en phase 2 (Q71) et compléter le document.
