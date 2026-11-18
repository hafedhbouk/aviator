# Première installation de la recette — procédure pas à pas

Phase 2, lot 10. À exécuter par le responsable technique (Claude Code n'a aucun accès au serveur). Durée estimée : 1 h 30 à 2 h.

**Résultat attendu** : la recette répond sur `https://recette.<domaine>/admin`, protégée par mot de passe, avec un compte super admin dont la 2FA est active. `php artisan aviator:check-environment` ne signale **aucune erreur**, et sa sortie est jointe au rapport de la phase 2.

Variante choisie selon la réponse d'Oxahost sur SSH (Q8) :

- **Variante A** : SSH depuis le poste (clé publique importée dans cPanel). Les commandes sont tapées dans un terminal SSH.
- **Variante B** : terminal cPanel seulement (D22). Les mêmes commandes sont collées dans *cPanel → Avancé → Terminal* ; les assets compilés sont envoyés par le gestionnaire de fichiers. Les différences sont signalées par « **B** ».

Notations : `<domaine>` (Q6), `<cpaneluser>` (identifiant cPanel), `<php83>` (chemin de PHP 8.3, noté à l'étape 2).

---

## Étape 0 — Préparer le poste (Windows, Git Bash)

```bash
cd /d/aviator
git checkout phase/02-architecture-socle && git pull
composer check                     # tout doit être au vert
npm ci && npm run build            # produit public/build
tar -czf /d/aviator-build.tar.gz -C public build   # archive des assets, pour la variante B
```

Préparez aussi, dans le gestionnaire de mots de passe d'Aviator, une fiche « Recette » qui recevra : le mot de passe de la base, celui du compte e-mail, `APP_KEY` et `AVIATOR_BLIND_INDEX_KEY` (étape 6).

## Étape 1 — cPanel

1. **Sélection de la version de PHP** : PHP **8.3**. Extensions à cocher : `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `gd`, `gmp`, `iconv`, `intl`, `mbstring`, `openssl`, `pdo_mysql`, `simplexml`, `sodium`, `tokenizer`, `xml`, `xmlreader`, `xmlwriter`, `zip`, `exif`. Options : `memory_limit` 256M, `max_execution_time` 120, `upload_max_filesize` 16M, `post_max_size` 20M.
2. **Bases de données MySQL** (le serveur est MariaDB 10.11.19) : créer la base `<cpaneluser>_recette`, l'utilisateur `<cpaneluser>_recette` (mot de passe fort, noté dans la fiche), puis lui donner **tous les privilèges** sur cette seule base.
3. **Domaines** : créer le sous-domaine `recette.<domaine>`, avec la racine web **`/home/<cpaneluser>/apps/recette/public`** (hors de `public_html`, confirmé par Oxahost).
4. **SSL/TLS Status** : lancer AutoSSL pour `recette.<domaine>` ; vérifier le cadenas avant l'étape 9.
5. **Comptes de messagerie** : créer `noreply@<domaine>` (mot de passe dans la fiche). Noter l'hôte SMTP et le port SSL indiqués par « Connect Devices » (en général `mail.<domaine>`, port 465).
6. **Accès SSH** (**A** seulement) : importer et autoriser votre clé publique.

## Étape 2 — Outils sur le serveur

```bash
php -v                    # doit afficher 8.3 ; sinon, noter le chemin exact de PHP 8.3 :
ls /opt/alt/php83/usr/bin/php 2>/dev/null && echo "<php83> = /opt/alt/php83/usr/bin/php"
composer -V               # si absent :
mkdir -p ~/bin && cd ~/bin && php -r "copy('https://getcomposer.org/installer','i.php');" && php i.php && mv composer.phar composer && rm i.php && cd ~
which mysqldump git tar   # tous trois confirmés par Oxahost
```

Dans la suite, remplacez `php` par `<php83>` si `php -v` n'affiche pas 8.3.

## Étape 3 — Récupérer le code (lecture seule sur GitHub)

```bash
ssh-keygen -t ed25519 -f ~/.ssh/aviator_deploy -C "deploy-aviator-recette" -N ""
cat ~/.ssh/aviator_deploy.pub
```

Ajouter cette clé au dépôt GitHub privé : *Settings → Deploy keys → Add deploy key*, **sans** l'option « Allow write access ». Puis :

```bash
cat >> ~/.ssh/config <<'EOF'
Host github-aviator
  HostName github.com
  User git
  IdentityFile ~/.ssh/aviator_deploy
EOF
chmod 600 ~/.ssh/config
mkdir -p ~/apps
git clone --branch phase/02-architecture-socle git@github-aviator:hafedhbouk/aviator.git ~/apps/recette
cd ~/apps/recette
```

## Étape 4 — Fichier `.env` (avant Composer)

```bash
cp .env.example .env
nano .env        # ou l'éditeur du gestionnaire de fichiers cPanel
```

Valeurs à modifier :

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://recette.<domaine>
LOG_LEVEL=warning

DB_CONNECTION=mariadb
DB_HOST=localhost
DB_DATABASE=<cpaneluser>_recette
DB_USERNAME=<cpaneluser>_recette
DB_PASSWORD=<mot de passe de la base>

SESSION_SECURE_COOKIE=true

MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST=mail.<domaine>
MAIL_PORT=465
MAIL_USERNAME=noreply@<domaine>
MAIL_PASSWORD=<mot de passe du compte e-mail>
MAIL_FROM_ADDRESS="noreply@<domaine>"
```

`APP_KEY` et `AVIATOR_BLIND_INDEX_KEY` restent vides pour l'instant (étape 6). `QUEUE_CONNECTION`, `CACHE_STORE` et `SESSION_DRIVER` restent à `database`.

```bash
chmod 600 .env
```

## Étape 5 — Dépendances

```bash
composer install --no-dev --optimize-autoloader --no-interaction
```

Composer republie aussi les fichiers CSS et JS de Filament (`filament:upgrade`, sans lien symbolique).

**Assets compilés** (`public/build`, non versionnés) :

- **A** : depuis le poste, dans Git Bash :
  `tar -czf - -C public build | ssh <cpaneluser>@<serveur> "tar -xzf - -C ~/apps/recette/public"`
- **B** : envoyer `/d/aviator-build.tar.gz` dans `~/apps/recette/public` par le gestionnaire de fichiers cPanel, puis dans le terminal :
  `cd ~/apps/recette/public && tar -xzf aviator-build.tar.gz && rm aviator-build.tar.gz && cd ~/apps/recette`

## Étape 6 — Clés (une seule fois, D23)

```bash
php artisan key:generate --force                                  # APP_KEY : UNE SEULE FOIS, ici
php -r "echo 'AVIATOR_BLIND_INDEX_KEY=', bin2hex(random_bytes(32)), PHP_EOL;"
```

Copier la ligne `AVIATOR_BLIND_INDEX_KEY=…` affichée dans `.env`. **Aussitôt**, reporter `APP_KEY` et `AVIATOR_BLIND_INDEX_KEY` dans la fiche « Recette » du gestionnaire de mots de passe et sur la copie papier (`docs/gestion_des_cles.md` §1). Ne plus jamais lancer `key:generate` sur ce serveur.

```bash
php artisan config:clear
```

## Étape 7 — Base, profils et données de référence

```bash
php artisan migrate --force              # tables, réglages (sécurité, notifications)
php artisan aviator:sync-permissions     # 3 profils et leurs permissions (D16)
php artisan db:seed --force              # les 4 agences ; aucun compte n'est créé
php artisan optimize
```

**Identifiants des sauvegardes** (utilisés par `deploy.sh` et l'annexe A avant chaque mise à jour) :

```bash
cat > ~/.my.recette.cnf <<'EOF'
[client]
user=<cpaneluser>_recette
password=<mot de passe de la base>
EOF
chmod 600 ~/.my.recette.cnf
mysqldump --defaults-extra-file="$HOME/.my.recette.cnf" --single-transaction --no-tablespaces <cpaneluser>_recette | head -c 200; echo
```

La dernière commande doit afficher le début d'un export SQL (et non une erreur d'accès).

## Étape 8 — Tâche cron (cPanel → Tâches Cron)

Réglage « Une fois par minute » (`* * * * *`), commande :

```text
cd /home/<cpaneluser>/apps/recette && <php83> artisan schedule:run >> /dev/null 2>&1
```

Si cPanel n'accepte pas « chaque minute », choisir « toutes les 5 minutes » : l'application le supporte (D20). `aviator:check-environment` le signalera et conseillera d'activer l'envoi des notifications urgentes pendant la requête.

## Étape 9 — Protection de la recette

*cPanel → Confidentialité des répertoires* : protéger `apps/recette/public` par un nom d'utilisateur et un mot de passe, communiqués aux seuls testeurs (recette non publique, non indexée).

## Étape 10 — Premier compte super admin

```bash
php artisan aviator:create-super-admin
```

La commande demande le nom, l'e-mail et le mot de passe (12 caractères au moins, avec majuscule, minuscule, chiffre et symbole, sans le nom ni l'e-mail). Puis, dans le navigateur :

1. Ouvrir `https://recette.<domaine>/admin` (mot de passe de protection de l'étape 9, puis l'e-mail et le mot de passe du super admin).
2. **Activer la 2FA**, imposée à la première connexion : scanner le QR code avec une application d'authentification, saisir le code.
3. **Imprimer les codes de récupération**, affichés une seule fois, et les ranger avec la copie papier des clés (`docs/gestion_des_cles.md` §6.1).
4. Tester « Mot de passe oublié ? » avec l'adresse du super admin : l'e-mail doit arriver (contrôle du compte `noreply@<domaine>`).

## Étape 11 — Contrôle de l'environnement (à joindre au rapport)

Attendre **au moins 2 minutes** après la création du cron (2 passages du planificateur), puis :

```bash
cd ~/apps/recette
php artisan aviator:check-environment | tee ~/check-environment-recette.txt
```

- **Aucune ligne ERREUR** n'est attendue. En cas d'erreur, appliquer l'« Action conseillée » de la ligne, puis relancer la commande.
- Les **AVERTISSEMENTS** sont à examiner : la cadence du cron, les connexions simultanées sous 10 et `mysqldump` absent du PATH de PHP sont à signaler.
- Envoyer `~/check-environment-recette.txt` pour le rapport de la phase 2. Le fichier ne contient aucune valeur de clé ni aucun mot de passe.

Points d'Oxahost encore ouverts que ce contrôle tranche : cron chaque minute (ligne « Planificateur »), `exec`, `proc_open` et `symlink` (lignes « Fonction … »), extensions restreintes (« Extensions »).

## Étape 12 — Vérifications dans le navigateur

| Vérification | Attendu |
| --- | --- |
| `http://recette.<domaine>/admin` | Redirection vers `https://` |
| `https://recette.<domaine>/storage/x` | 404 (aucun service automatique de fichiers, D21) |
| Connexion d'un compte désactivé | Refusée |
| *Administration → Journal d'audit* | Connexion du super admin et création de son compte visibles |

---

## Mises à jour suivantes

- **Variante A** : `scripts/deploy.sh recette <branche>` depuis Git Bash (`docs/installation_hebergement_oxahost.md` §6).
- **Variante B** : `docs/installation_hebergement_oxahost.md`, annexe A.

## Propositions pour `scripts/deploy.sh` (non appliquées, à valider)

`scripts/deploy.sh` n'a pas été modifié. Après relecture, quatre changements sont proposés :

| # | Changement | Raison |
| --- | --- | --- |
| 1 | Remplacer `php artisan test` (étape 1/5) par `composer check` | Mêmes contrôles qu'en CI, sans cache (Pint, Larastan, Pest, audit, build) |
| 2 | Ajouter `$PHP_BIN artisan aviator:sync-permissions` après `migrate --force` (étape 5/5) | D16 : rôles et permissions rejoués à chaque déploiement |
| 3 | Ajouter `$PHP_BIN artisan aviator:check-environment \|\| true` en fin de script | Diagnostic affiché à chaque déploiement, sans bloquer la remise en ligne |
| 4 | Si la proposition 1 est retenue : garder `npm ci` **avant** `composer check`, et retirer la ligne `npm run build` | `composer check` exécute déjà `npm run build` |
