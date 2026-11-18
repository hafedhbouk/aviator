# Installation sur l'hébergement Oxahost Ultimate (cPanel)

Un seul compte Oxahost Ultimate (Tunis) héberge la **recette** et la **production**, chacune dans son sous-domaine, avec sa base et son dossier. Les commandes sont exécutées par le responsable technique ; Claude Code n'a pas accès au serveur.

| Élément | Recette | Production |
| --- | --- | --- |
| Sous-domaine | `recette.<domaine>` | `gestion.<domaine>` (espace client au lot 2 : à définir) |
| Dossier | `~/apps/recette` | `~/apps/prod` |
| Racine web (document root) | `~/apps/recette/public` | `~/apps/prod/public` |
| Base MariaDB (10.11.19) | `<cpaneluser>_recette` | `<cpaneluser>_prod` |
| Accès | Protégé par mot de passe, non indexé | Public (connexion obligatoire) |

---

## 0. Avant de commander

Faites confirmer par écrit par Oxahost (voir `docs/projet.md`, section 7) :
- calendrier de PHP 8.4 ;
- cron toutes les minutes et Composer utilisable en SSH ;
- sauvegardes stockées en Tunisie ;
- prix annuel exact.

La garantie de 30 jours permet de vérifier le reste en installant le socle (phase 2).

## 1. Réglages cPanel

1. **Sélection de la version de PHP** : PHP **8.3** ; extensions : `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `gd`, `gmp`, `intl`, `mbstring`, `openssl`, `pdo_mysql`, `tokenizer`, `xml`, `zip`. Options : `memory_limit` 256M, `max_execution_time` 120, `upload_max_filesize` 16M.
2. **Bases de données MySQL** (nom du menu cPanel ; le serveur est en MariaDB 10.11.19) : créez `recette` et `prod`, un utilisateur par base avec un mot de passe fort, tous privilèges sur sa seule base. Interclassement des bases : `utf8mb4_unicode_ci`.
3. **Sous-domaines** : créez `recette` et `gestion` avec les racines web du tableau ci-dessus.
4. **SSL/TLS** : certificat AutoSSL ou Let's Encrypt sur les deux sous-domaines.
5. **Confidentialité des répertoires** : protégez `~/apps/recette/public` par mot de passe.
6. **Accès SSH** : importez votre clé publique et autorisez-la.

## 2. Connexion SSH et outils

```bash
ssh <cpaneluser>@<serveur>
php -v            # doit afficher 8.3 ; sinon noter le chemin, par ex. /opt/alt/php83/usr/bin/php
which php
composer -V       # si absent :
mkdir -p ~/bin && cd ~/bin && php -r "copy('https://getcomposer.org/installer','i.php');" && php i.php && mv composer.phar composer && rm i.php
```

Notez le chemin exact de PHP 8.3 : il sert au cron et au script de déploiement (`AVIATOR_PHP`).

## 3. Accès au dépôt Git (lecture seule)

```bash
ssh-keygen -t ed25519 -f ~/.ssh/aviator_deploy -C "deploy-aviator" -N ""
cat ~/.ssh/aviator_deploy.pub
```

Ajoutez cette clé dans le dépôt (GitHub : *Settings → Deploy keys*, sans écriture), puis :

```bash
cat >> ~/.ssh/config <<'EOF'
Host github-aviator
  HostName github.com
  User git
  IdentityFile ~/.ssh/aviator_deploy
EOF
mkdir -p ~/apps
git clone git@github-aviator:<organisation>/aviator.git ~/apps/recette
git clone git@github-aviator:<organisation>/aviator.git ~/apps/prod
```

## 4. Configuration de l'application (pour chaque environnement)

**Première installation : suivre la procédure pas à pas `docs/installation_recette.md`** (ordre exact des étapes, deux variantes SSH ou terminal cPanel, contrôle final). Cette section en résume les principes.

Les clés suivent `docs/gestion_des_cles.md` (D23). **`php artisan key:generate` ne se lance qu'ici, une seule fois par environnement.** Ensuite, il est interdit en recette et en production.

```bash
cd ~/apps/recette                      # puis ~/apps/prod
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate               # UNE SEULE FOIS, à l'installation
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"   # valeur de AVIATOR_BLIND_INDEX_KEY, à coller dans .env
```

Reportez aussitôt `APP_KEY` et `AVIATOR_BLIND_INDEX_KEY` dans le gestionnaire de mots de passe d'Aviator et sur la copie papier (environnement, date).

Dans `.env` :

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://recette.<domaine>
DB_CONNECTION=mariadb
DB_DATABASE=<cpaneluser>_recette
DB_USERNAME=<utilisateur>
DB_PASSWORD=<mot de passe>
QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
MAIL_MAILER=smtp            # compte e-mail cPanel : sécurité des comptes uniquement
AVIATOR_BLIND_INDEX_KEY=<64 caractères hexadécimaux, générés ci-dessus>
```

Puis :

```bash
php artisan aviator:check-environment  # aucune clé absente ou vide, extensions, base, droits d'écriture
php artisan migrate --force
# Pas de « storage:link » : l'application ne dépend pas des liens symboliques (D21).
php artisan optimize
```

Les clés VAPID des notifications push seront générées en phase 9 (commande fournie par Claude Code) et ajoutées au `.env`.

## 5. Tâche planifiée (cron cPanel)

Une ligne par environnement, toutes les minutes :

```text
* * * * * cd /home/<cpaneluser>/apps/prod && <chemin_php_8.3> artisan schedule:run >> /dev/null 2>&1
```

Le planificateur de l'application traite aussi la file d'attente (`queue:work --stop-when-empty`) : aucun processus permanent n'est nécessaire.

## 6. Déployer une version

Depuis **Git Bash** sur votre poste Windows, à la racine du projet (`C:\laragon\www\aviator`) :

```bash
export AVIATOR_SSH_HOST=<cpaneluser>@<serveur>
export AVIATOR_PHP=<chemin_php_8.3>          # facultatif si "php" est déjà la 8.3
export AVIATOR_COMPOSER='$HOME/bin/composer'  # guillemets simples : chemin du serveur ; facultatif si "composer" est dans le PATH
scripts/deploy.sh recette phase/04-parc-leasing-reservations
scripts/deploy.sh prod v1.0.0                 # production : uniquement une version étiquetée
```

Le script lance les tests, compile les assets, sauvegarde la base, met l'application en maintenance, récupère la version, installe les dépendances, envoie les assets compilés (archive `tar` par SSH), applique les migrations et remet l'application en ligne.

**Production** : jamais sans tests verts, sauvegarde vérifiée et accord explicite.

## 7. Sauvegardes

- Oxahost : sauvegardes quotidiennes JetBackup conservées jusqu'à 30 jours, restauration depuis cPanel. **N'activez pas l'option Acronis Cloud Europe** (données hors de Tunisie).
- Avant chaque déploiement : sauvegarde de la base par le script, dans `~/backups/`.
- Chaque mois : téléchargez une copie de la base et du dossier `storage/app` sur un support d'Aviator, en Tunisie.
- Chaque trimestre : restaurez la sauvegarde de production sur la recette et vérifiez l'application.

## 8. Mot de passe MySQL pour les sauvegardes du script

Pour que `mysqldump` ne demande pas de mot de passe, créez un fichier par environnement (lisible par vous seul) :

```bash
cat > ~/.my.recette.cnf <<'EOF'
[client]
user=<utilisateur_recette>
password=<mot_de_passe>
EOF
chmod 600 ~/.my.recette.cnf          # idem ~/.my.prod.cnf
```

---

## Annexe A — Déployer une mise à jour par le terminal cPanel (D22)

À utiliser si Oxahost ne permet pas le SSH depuis le poste (Q8) : `scripts/deploy.sh` ne peut alors pas être lancé. Les commandes sont collées dans *cPanel → Avancé → Terminal*. Remplacez `<env>` par `recette` ou `prod`, `<ref>` par une branche (recette) ou une étiquette `vX.Y.Z` (production, **uniquement** une étiquette et après accord explicite), et `<php83>` par le chemin de PHP 8.3.

**1. Sur le poste (Git Bash)** :

```bash
cd /d/aviator
git checkout <ref> && git pull
npm ci
composer check                                   # tout doit être au vert
tar -czf /d/aviator-build.tar.gz -C public build
```

**2. Dans le gestionnaire de fichiers cPanel** : envoyer `/d/aviator-build.tar.gz` dans `apps/<env>/`.

**3. Dans le terminal cPanel**, en collant les commandes **une par une** et en vérifiant le résultat de chacune avant de passer à la suivante (pas de `set -e` : dans un terminal interactif, il fermerait la session au premier incident) :

```bash
cd ~/apps/<env>
mkdir -p ~/backups
DB_NAME=$(grep -E '^DB_DATABASE=' .env | cut -d= -f2-)
mysqldump --defaults-extra-file="$HOME/.my.<env>.cnf" --single-transaction --no-tablespaces "$DB_NAME" \
  | gzip > ~/backups/<env>_$(date +%Y%m%d_%H%M%S).sql.gz
ls -lh ~/backups | tail -1                       # vérifier que la sauvegarde n'est pas vide
<php83> artisan down --retry=60
git fetch origin --tags --force --prune
git checkout --force <ref>                       # branche : git checkout --force -B <ref> origin/<ref>
composer install --no-dev --optimize-autoloader --no-interaction
rm -rf public/build && tar -xzf aviator-build.tar.gz -C public && rm aviator-build.tar.gz
<php83> artisan migrate --force
<php83> artisan aviator:sync-permissions
<php83> artisan optimize
<php83> artisan up
<php83> artisan aviator:check-environment
```

**En cas d'échec** avant `artisan up` : l'application reste en maintenance. Corriger, ou revenir à la version précédente (`git checkout --force <ref précédente>`, puis `composer install`, `migrate` si besoin, `optimize`, `up`) ; la base se restaure depuis `~/backups` ou JetBackup. Ne jamais lancer `key:generate`.
