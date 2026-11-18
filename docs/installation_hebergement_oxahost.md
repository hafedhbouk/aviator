# Installation sur l'hébergement Oxahost Ultimate (cPanel)

Un seul compte Oxahost Ultimate (Tunis) héberge la **recette** et la **production**, chacune dans son sous-domaine, avec sa base et son dossier. Les commandes sont exécutées par le responsable technique ; Claude Code n'a pas accès au serveur.

| Élément | Recette | Production |
| --- | --- | --- |
| Sous-domaine | `recette.<domaine>` | `gestion.<domaine>` (espace client au lot 2 : à définir) |
| Dossier | `~/apps/recette` | `~/apps/prod` |
| Racine web (document root) | `~/apps/recette/current/public` | `~/apps/prod/current/public` |
| Base MariaDB (10.11.19) | `<cpaneluser>_recette` | `<cpaneluser>_prod` |
| Accès | Protégé par mot de passe, non indexé | Public (connexion obligatoire) |

## Ce que permet l'hébergement (réponse d'Oxahost du 18/11/2026)

| Point | Réponse | Conséquence |
| --- | --- | --- |
| Accès en ligne de commande | **Uniquement le terminal web de cPanel**, sans connexion SSH depuis le poste ni clé | Déploiement par archive (D25) |
| `exec`, `proc_open`, `shell_exec`, `system`, `passthru` | **Désactivées** | Confirme D14 et D15 ; **aucun `composer install` sur le serveur** (Composer utilise `proc_open`) |
| `symlink` | Activé | D21 maintenue par choix de sécurité (pas de `storage:link`) |
| Cron | Toutes les minutes autorisé | D20 (toutes les 5 minutes) reste en secours |
| Extensions PHP | Toutes disponibles | Contrôlées par `aviator:check-environment` |

## Déploiement par archive (D25)

```text
Poste (Git Bash)                         Serveur (terminal web de cPanel)
scripts/build-release.sh <réf>           bash install-release.sh <env> <archive>
  composer check (contrôles de la CI)      empreinte SHA-256, sauvegarde de la base,
  composer install --no-dev (PHP 8.3)      maintenance, releases/<version>,
  npm run build                            liens .env et storage/ vers shared/,
  dist/aviator-<version>.tar.gz            migrate --force, sync-permissions, optimize,
  + .sha256                                bascule de current, remise en ligne,
        │                                  check-environment, 3 versions conservées
        └── FTPS ou gestionnaire de fichiers ──┘
```

- L'archive contient les dépendances PHP (`vendor/`) et les assets compilés : le serveur n'utilise que `bash`, `tar`, `php` et `mysqldump`. Ni Composer, ni npm, ni Git.
- L'archive ne contient ni `.env`, ni tests, ni `node_modules`, ni `.git`, et seulement la structure vide de `storage/` (vérifié par `build-release.sh` et par les tests).
- La configuration (`shared/.env`) et les fichiers (`shared/storage/`) sont communs à toutes les versions.

---

## 1. Réglages cPanel (une fois)

1. **Double authentification** du compte cPanel (*Sécurité → Two-Factor Authentication*) : obligatoire, le terminal web donne un accès complet au compte.
2. **Sélection de la version de PHP** : PHP **8.3** ; extensions et options : `docs/installation_recette.md`, étape 1.
3. **Bases de données MySQL** (nom du menu cPanel ; le serveur est en MariaDB 10.11.19) : créez `recette` et `prod`, un utilisateur par base avec un mot de passe fort, tous privilèges sur sa seule base. Interclassement des bases : `utf8mb4_unicode_ci`.
4. **Compte FTP** limité à `~/deploiement` si l'archive est envoyée par FTPS : « FTP explicite sur TLS » obligatoire, transfert binaire ; jamais de FTP non chiffré.
5. **Sous-domaines** : créés **après** la première installation de chaque environnement, avec les racines web du tableau ci-dessus.
6. **SSL/TLS** : certificat AutoSSL ou Let's Encrypt sur les deux sous-domaines.
7. **Confidentialité des répertoires** : protégez `apps/recette` par mot de passe (dossier parent, conservé d'une version à l'autre).

## 2. Première installation d'un environnement

Suivre **pas à pas** `docs/installation_recette.md` (recette). Pour la production, mêmes étapes avec `prod` à la place de `recette`, le sous-domaine `gestion.<domaine>`, la base `<cpaneluser>_prod`, sans protection par mot de passe, et une **étiquette** `vX.Y.Z` (seule version acceptée par `install-release.sh prod`, après confirmation « OUI vX.Y.Z »).

Les clés suivent `docs/gestion_des_cles.md` (D23) : générées **une seule fois** par environnement, avec `php -r` (étape 5 de la procédure), puis copiées chez Aviator. `php artisan key:generate` n'est jamais lancé sur le serveur.

Les clés VAPID des notifications push seront générées en phase 9 (commande fournie par Claude Code) et ajoutées à `shared/.env`.

## 3. Tâche planifiée (cron cPanel)

Une ligne par environnement, toutes les minutes :

```text
* * * * * cd /home/<cpaneluser>/apps/prod/current && php artisan schedule:run >> /dev/null 2>&1
```

Le planificateur de l'application traite aussi la file d'attente (`queue:work --stop-when-empty`, dans le processus, D15) : aucun processus permanent n'est nécessaire. Le chemin passe par `current` : il suit chaque nouvelle version.

## 4. Sauvegardes

- Oxahost : sauvegardes quotidiennes JetBackup conservées jusqu'à 30 jours, en Tunisie, restauration depuis cPanel. **N'activez pas l'option Acronis Cloud Europe** (données hors de Tunisie).
- Avant chaque installation : sauvegarde de la base par `install-release.sh`, dans `~/backups/<env>_<date>_avant_<version>.sql.tar.gz`. Identifiants : `~/.my.<env>.cnf` (droits 600, `docs/installation_recette.md`, étape 6).
- Chaque mois : téléchargez une copie de la base et du dossier `shared/storage/app` sur un support d'Aviator, en Tunisie.
- Chaque trimestre : restaurez la sauvegarde de production sur la recette et vérifiez l'application.

---

## Annexe A — Installer une mise à jour (D25)

Remplacez `<env>` par `recette` ou `prod`. **Production** : uniquement une étiquette `vX.Y.Z`, après accord explicite, avec une CI verte.

**1. Sur le poste (Git Bash)** :

```bash
cd /d/aviator
scripts/build-release.sh <branche>      # recette, par exemple phase/04-parc-leasing-reservations
scripts/build-release.sh vX.Y.Z         # production : étiquette existante uniquement
```

Le script s'arrête si `composer check` échoue : aucune archive n'est alors produite.

**2. Envoyer l'archive** : `dist/aviator-<version>.tar.gz` **et** `dist/aviator-<version>.tar.gz.sha256` dans `~/deploiement/`, par FTPS (binaire) ou par le gestionnaire de fichiers de cPanel.

**3. Dans le terminal web de cPanel** :

```bash
cd ~/deploiement
tar -xzf aviator-<version>.tar.gz ./scripts/install-release.sh ./scripts/rollback-release.sh
bash scripts/install-release.sh <env> ~/deploiement/aviator-<version>.tar.gz
```

Les scripts sont extraits de l'archive à chaque fois : la version du script est toujours celle de l'archive installée. Le script vérifie l'empreinte, sauvegarde la base, met en maintenance, installe, migre, synchronise les permissions, optimise, bascule `current`, remet en ligne, affiche `aviator:check-environment` et ne garde que les 3 dernières versions. En production, il demande de taper « OUI vX.Y.Z ».

**En cas d'échec**, le script affiche la cause, l'action conseillée et l'état de l'application : la version en service reste inchangée (éventuellement en maintenance, avec la commande pour la remettre en ligne) et la version non mise en service est retirée. Si les migrations ont échoué en cours de route, restaurer la base depuis la sauvegarde indiquée avant la remise en ligne.

**4. Retour à la version précédente** :

```bash
bash ~/apps/<env>/current/scripts/rollback-release.sh <env>
```

Il demande une confirmation (`OUI`, ou `OUI vX.Y.Z` en production), rebascule `current` vers la version précédente encore présente, optimise et remet en ligne. **Les migrations ne sont pas annulées** : si la version annulée a modifié la base d'une façon que la précédente ne comprend pas, restaurer aussi la sauvegarde `~/backups/<env>_*_avant_<version annulée>.sql.tar.gz`.

**5. Ménage** : supprimer l'archive de `~/deploiement/` après une installation réussie. Ne jamais lancer `key:generate`.
