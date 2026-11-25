# Première installation de la recette — procédure pas à pas

Phase 2, lot 10, décision **D25** (déploiement par archive). À exécuter par le responsable technique : Claude Code n'a aucun accès au serveur. Durée estimée : 1 h 30 à 2 h.

**Résultat attendu** : la recette répond sur `https://recette.<domaine>/admin`, protégée par mot de passe, avec un compte super admin dont la 2FA est active. `aviator:check-environment` ne signale **aucune erreur**, et sa sortie est jointe au rapport de la phase 2.

**Principe (D25)** : l'archive est construite et contrôlée par le workflow GitHub « Release » (`scripts/build-release.sh` ; sur le poste en secours), puis téléchargée depuis GitHub. Elle contient déjà les dépendances PHP (`vendor/`) et les assets compilés (`public/build`). Elle est envoyée sur le serveur par **FTPS** ou par le **gestionnaire de fichiers** de cPanel, puis installée dans le **terminal web de cPanel** par `install-release.sh`. Aucune connexion SSH depuis le poste, aucun `composer install`, `npm` ni `git` sur le serveur (`proc_open` et `exec` y sont désactivées).

Notations : `<domaine>` (Q6), `<cpaneluser>` (identifiant cPanel), `<version>` (nom de l'archive, par exemple `phase-02-architecture-socle-5788747`).

**Pour Aviator : `<domaine>` = `aviator.tn`** (Q6, 25/11/2026). La recette répondra donc sur `https://recette.aviator.tn/admin`, et l'adresse d'envoi sera `noreply@aviator.tn`.

**Prérequis avant l'étape 1** :

1. L'hébergement Oxahost Ultimate est commandé et le compte cPanel est ouvert.
2. Le domaine `aviator.tn` est rattaché à ce compte : soit ses serveurs DNS sont ceux d'Oxahost, soit un enregistrement `A` de `recette.aviator.tn` pointe vers l'adresse IP du serveur (indiquée dans cPanel). Sans cela, le certificat (étape 8, AutoSSL) ne peut pas être émis. Vérification depuis le poste : `nslookup recette.aviator.tn` doit renvoyer l'adresse du serveur.
3. Branche à installer pour la phase 4 : `phase/04-parc-leasing-reservations` (paramétrage de la phase 3 et parc). Le prototype du planning n'est jamais accessible en recette.

Arborescence créée sur le serveur :

```text
~/apps/recette/
  releases/<version>/     versions installées (les 3 dernières sont conservées)
  shared/.env             configuration et secrets (jamais dans l'archive)
  shared/storage/         fichiers, sessions, journaux (communs à toutes les versions)
  current -> releases/<version>    version en service ; racine web : current/public
~/backups/                sauvegardes de la base prises avant chaque installation
```

---

## Étape 0 — Construire l'archive (GitHub Actions)

L'archive est construite par le workflow GitHub **« Release »** (`.github/workflows/release.yml`), qui exécute `scripts/build-release.sh` sous Ubuntu, avec PHP 8.3 et un service MariaDB 10.11 pour `composer check`. Le workflow n'utilise aucun secret.

1. Sur GitHub : *Actions → Release → Run workflow*. Choisir la branche dans « Use workflow from » (recette : par exemple `phase/03-parametrage`), ou saisir une branche ou une étiquette `vX.Y.Z` dans le champ « ref ». Une étiquette `vX.Y.Z` poussée sur GitHub (production) lance aussi le workflow, sans action manuelle.
2. Attendre la fin de l'exécution : 1 min 40 s mesurées le 19/11/2026, davantage si le cache Composer de GitHub est vide. Le téléchargement de l'artefact (15 Mo) a pris 7 minutes depuis le poste. Si un contrôle échoue, aucune archive n'est publiée.
3. Dans la page de l'exécution, section *Artifacts* : télécharger l'artefact `aviator-<version>` (fichier `.zip`). Il est **conservé 30 jours** ; au-delà, relancer le workflow.
4. Décompresser le `.zip` sur le poste : il contient les deux fichiers à envoyer, `aviator-<version>.tar.gz` (sans `.env`, sans tests, sans `node_modules`, sans `.git`, avec la structure vide de `storage/`) et `aviator-<version>.tar.gz.sha256`.
5. Vérifier l'empreinte dans Git Bash, dans le dossier décompressé : `sha256sum --check aviator-<version>.tar.gz.sha256` doit répondre `OK`. `install-release.sh` la contrôle de nouveau sur le serveur.

Version : `vX.Y.Z` pour une étiquette, `<branche>-<commit court>` pour une branche (les `/` deviennent des `-`, par exemple `phase-03-parametrage-1a2b3c4`).

**En secours** (GitHub indisponible), construire sur le poste, dans Git Bash :

```bash
cd /d/aviator
git checkout <branche> && git pull
scripts/build-release.sh <branche ou vX.Y.Z>
```

Le script se relance sous `scripts/clean-env.sh` (aucune variable de `.env` héritée du terminal), prend une copie de travail de la référence, lance `composer check`, installe les dépendances de production (`composer install --no-dev`, plateforme PHP 8.3), compile les assets et produit les deux mêmes fichiers dans `D:\aviator-builds` (variable `AVIATOR_BUILD_DIR` ; dossier à exclure de l'antivirus du poste). Le dossier de travail du dépôt n'est pas modifié.

Préparez aussi, dans le gestionnaire de mots de passe d'Aviator, une fiche « Recette » qui recevra : le mot de passe de la base, celui du compte e-mail, celui du compte FTP, `APP_KEY` et `AVIATOR_BLIND_INDEX_KEY` (étape 5).

## Étape 1 — cPanel

1. **Double authentification du compte cPanel** : *Sécurité → Two-Factor Authentication* → configurer avec une application d'authentification. Ranger les informations de secours avec la copie papier des clés (`docs/gestion_des_cles.md` §6.1). Le terminal web donne un accès complet au compte : la 2FA le protège.
2. **Sélection de la version de PHP** : PHP **8.3**. Extensions à cocher (toutes disponibles chez Oxahost) : `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `gd`, `gmp`, `iconv`, `intl`, `mbstring`, `openssl`, `pdo_mysql`, `simplexml`, `sodium`, `tokenizer`, `xml`, `xmlreader`, `xmlwriter`, `zip`, `exif`. Options : `memory_limit` 256M, `max_execution_time` 120, `upload_max_filesize` 16M, `post_max_size` 20M.
3. **Bases de données MySQL** (le serveur est MariaDB 10.11.19) : créer la base `<cpaneluser>_recette`, l'utilisateur `<cpaneluser>_recette` (mot de passe fort, noté dans la fiche), puis lui donner **tous les privilèges** sur cette seule base.
4. **Comptes de messagerie** : créer `noreply@<domaine>` (mot de passe dans la fiche). Noter l'hôte SMTP et le port SSL indiqués par « Connect Devices » (en général `mail.<domaine>`, port 465).
5. **Comptes FTP** (si l'envoi se fait par FTPS) : créer un compte limité au dossier `/home/<cpaneluser>/deploiement` (mot de passe dans la fiche). Dans le client FTP (FileZilla par exemple) : **« FTP explicite sur TLS »** obligatoire, transfert en **binaire**. Ne jamais utiliser le FTP non chiffré.

Le sous-domaine est créé à l'étape 8, une fois la première version en service : cPanel créerait sinon un vrai dossier `current/public` à la place du lien.

## Étape 2 — Premiers essais dans le terminal web

*cPanel → Avancé → Terminal*. Coller les commandes **une par une** :

```bash
php -v                                                   # doit afficher PHP 8.3.x
php -r 'var_dump(function_exists("proc_open"));'         # attendu : bool(false), désactivée chez Oxahost
composer -V                                              # pour information seulement : Composer n'est jamais utilisé sur le serveur (D25)
command -v mysqldump tar sha256sum                       # les trois doivent répondre
```

Si `php -v` n'affiche pas 8.3, noter le chemin de PHP 8.3 (par exemple `/opt/alt/php83/usr/bin/php`) et préfixer chaque lancement des scripts par `AVIATOR_PHP=<ce chemin>`. Noter ces résultats : ils vont dans le rapport de la phase 2.

## Étape 3 — Envoyer l'archive

Envoyer **les deux fichiers** décompressés à l'étape 0, `aviator-<version>.tar.gz` et `aviator-<version>.tar.gz.sha256` (jamais le `.zip` de GitHub), dans `/home/<cpaneluser>/deploiement/` :

- par **FTPS**, de préférence : compte de l'étape 1.5, « FTP explicite sur TLS », transfert en **binaire** ;
- ou par le **gestionnaire de fichiers** de cPanel (*Charger*), si le client FTP n'est pas disponible.

## Étape 4 — Premier lancement : création de `shared/.env`

Dans le terminal web :

```bash
mkdir -p ~/deploiement && cd ~/deploiement
tar -xzf aviator-<version>.tar.gz ./scripts/install-release.sh ./scripts/rollback-release.sh
bash scripts/install-release.sh recette ~/deploiement/aviator-<version>.tar.gz
```

Le script vérifie l'empreinte et PHP 8.3, crée `~/apps/recette/shared/` (structure de `storage/` et `.env` copié du modèle, droits 600), puis **s'arrête** avec le message « PREMIÈRE INSTALLATION ». Rien d'autre n'est modifié.

Toujours `bash install-release.sh …`, jamais `source` : une erreur arrête le script, pas le terminal.

## Étape 5 — Compléter `shared/.env` et générer les clés (une seule fois, D23)

Générer les deux clés **sans** `artisan key:generate` (aucune version n'est encore en service) :

```bash
php -r 'echo "APP_KEY=base64:", base64_encode(random_bytes(32)), PHP_EOL;'
php -r 'echo "AVIATOR_BLIND_INDEX_KEY=", bin2hex(random_bytes(32)), PHP_EOL;'
```

**Aussitôt**, reporter les deux lignes dans la fiche « Recette » du gestionnaire de mots de passe et sur la copie papier (`docs/gestion_des_cles.md` §1). Ces clés ne seront **plus jamais** régénérées sur ce serveur.

Éditer `~/apps/recette/shared/.env` (*gestionnaire de fichiers → Modifier*, ou `nano`). Valeurs à modifier :

```ini
APP_ENV=production
APP_KEY=base64:<ligne générée ci-dessus>
APP_DEBUG=false
APP_URL=https://recette.<domaine>
LOG_LEVEL=warning

DB_CONNECTION=mariadb
DB_HOST=localhost
DB_DATABASE=<cpaneluser>_recette
DB_USERNAME=<cpaneluser>_recette
DB_PASSWORD=<mot de passe de la base>

AVIATOR_BLIND_INDEX_KEY=<64 caractères hexadécimaux générés ci-dessus>

SESSION_SECURE_COOKIE=true

MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST=mail.<domaine>
MAIL_PORT=465
MAIL_USERNAME=noreply@<domaine>
MAIL_PASSWORD=<mot de passe du compte e-mail>
MAIL_FROM_ADDRESS="noreply@<domaine>"
```

`QUEUE_CONNECTION`, `CACHE_STORE` et `SESSION_DRIVER` restent à `database`. Vérifier les droits : `chmod 600 ~/apps/recette/shared/.env`.

## Étape 6 — Identifiants des sauvegardes

`install-release.sh` sauvegarde la base avant chaque installation, avec ce fichier (lisible par vous seul) :

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

## Étape 7 — Deuxième lancement : installation

Exactement la même commande qu'à l'étape 4 :

```bash
cd ~/deploiement
bash scripts/install-release.sh recette ~/deploiement/aviator-<version>.tar.gz
```

Étapes affichées : empreinte, version, décompression dans `releases/<version>`, liens `.env` et `storage/` vers `shared/`, sauvegarde de la base dans `~/backups/`, migrations (`migrate --force`), profils et permissions (`aviator:sync-permissions`), **première version seulement** : les 4 agences (`db:seed --force`, aucun compte n'est créé), `optimize`, bascule du lien `current`, remise en ligne, puis le tableau d'`aviator:check-environment`.

À ce stade, la ligne « Planificateur (cron) » est en ERREUR (le cron n'existe pas encore) : c'est normal, elle est traitée à l'étape 9.

En cas d'erreur, le script affiche la cause, l'action conseillée et l'état de l'application (en service, ou en maintenance), retire la version non mise en service, puis s'arrête sans fermer le terminal. Après correction, relancer la même commande.

## Étape 8 — Sous-domaine et certificat

1. *cPanel → Domaines → Créer un domaine* : `recette.<domaine>`, racine web **`/home/<cpaneluser>/apps/recette/current/public`** (hors de `public_html`, confirmé par Oxahost). Si cPanel signale que le dossier existe déjà, c'est le lien créé à l'étape 7 : accepter.
2. *SSL/TLS Status* : lancer AutoSSL pour `recette.<domaine>` ; vérifier le cadenas avant l'étape 11.

## Étape 9 — Tâche cron (cPanel → Tâches Cron)

Réglage « Une fois par minute » (`* * * * *`, autorisé par Oxahost), commande :

```text
cd /home/<cpaneluser>/apps/recette/current && php artisan schedule:run >> /dev/null 2>&1
```

(remplacer `php` par le chemin de PHP 8.3 noté à l'étape 2 si besoin). Le cron passe par le lien `current` : il suit automatiquement chaque nouvelle version. Le mode toutes les 5 minutes (D20) reste disponible en secours.

## Étape 10 — Protection de la recette

*cPanel → Confidentialité des répertoires* : protéger le dossier **`apps/recette`** (et non `current/public`, remplacé à chaque version) par un nom d'utilisateur et un mot de passe, communiqués aux seuls testeurs (recette non publique, non indexée). La protection s'applique aux sous-dossiers ; elle est vérifiée à l'étape 13.

## Étape 11 — Premier compte super admin

```bash
cd ~/apps/recette/current
php artisan aviator:create-super-admin
```

La commande demande le nom, l'e-mail et le mot de passe (12 caractères au moins, avec majuscule, minuscule, chiffre et symbole, sans le nom ni l'e-mail). Puis, dans le navigateur :

1. Ouvrir `https://recette.<domaine>/admin` (mot de passe de protection de l'étape 10, puis l'e-mail et le mot de passe du super admin).
2. **Activer la 2FA**, imposée à la première connexion : scanner le QR code avec une application d'authentification, saisir le code.
3. **Imprimer les codes de récupération**, affichés une seule fois, et les ranger avec la copie papier des clés (`docs/gestion_des_cles.md` §6.1).
4. Tester « Mot de passe oublié ? » avec l'adresse du super admin : l'e-mail doit arriver (contrôle du compte `noreply@<domaine>`).

## Étape 12 — Contrôle de l'environnement (à joindre au rapport)

Attendre **au moins 2 minutes** après la création du cron (2 passages du planificateur), puis :

```bash
cd ~/apps/recette/current
php artisan aviator:check-environment | tee ~/check-environment-recette.txt
```

- **Aucune ligne ERREUR** n'est attendue. En cas d'erreur, appliquer l'« Action conseillée » de la ligne, puis relancer la commande.
- Lignes « Fonction exec / proc_open / shell_exec / system / passthru » : « Désactivée », statut OK, « Attendu chez Oxahost » (D14). La ligne « Fonction symlink » indique « Disponible » ; l'application ne crée aucun lien (D21).
- Les **AVERTISSEMENTS** sont à examiner : la cadence du cron, les connexions simultanées sous 10 et `mysqldump` absent du PATH de PHP sont à signaler.
- Envoyer `~/check-environment-recette.txt`, avec les résultats de l'étape 2, pour le rapport de la phase 2. Le fichier ne contient aucune valeur de clé ni aucun mot de passe.

## Étape 13 — Vérifications dans le navigateur

| Vérification | Attendu |
| --- | --- |
| `http://recette.<domaine>/admin` | Redirection vers `https://` |
| `https://recette.<domaine>/` sans le mot de passe de protection | Demande d'identification (étape 10) |
| `https://recette.<domaine>/storage/x` | 404 (aucun service automatique de fichiers, D21) |
| `https://recette.<domaine>/.env` | 404 ou 403 (le `.env` est hors de la racine web) |
| Connexion d'un compte désactivé | Refusée |
| *Administration → Journal d'audit* | Connexion du super admin et création de son compte visibles |

---

## Mises à jour suivantes

`docs/installation_hebergement_oxahost.md`, **annexe A** : construire l'archive, l'envoyer, lancer `install-release.sh`. Retour arrière : `bash ~/apps/recette/current/scripts/rollback-release.sh recette` (les migrations ne sont pas annulées).
