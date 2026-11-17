# Installation du poste de développement (Windows 11 + Laragon)

Poste du développeur : **Windows 11**, **Laragon** avec **PHP 8.3.16** et **MariaDB 10.11**, comme la production Oxahost (MariaDB 10.11.19).
Ce document complète Laragon pour le projet Aviator :
- extensions PHP nécessaires à Laravel 13, Filament et aux notifications push ;
- MariaDB 10.11 et deux bases : `aviator_dev` (développement) et `aviator_test` (tests automatisés) ;
- Node.js pour compiler les assets ;
- Git pour Windows (Git Bash), nécessaire à Claude Code et au script de déploiement ;
- Claude Code installé nativement sous Windows.

Durée : environ 1 heure.

---

## 1. PHP 8.3 dans Laragon

1. Menu Laragon → **PHP** → **Version** : sélectionnez `php-8.3.16`.
2. Menu Laragon → **PHP** → **Extensions** : cochez au minimum `bcmath`, `fileinfo`, `gd`, `gmp`, `intl`, `mbstring`, `openssl`, `pdo_mysql`, `sodium`, `zip`.
3. Menu Laragon → **PHP** → **php.ini** : `memory_limit = 512M`, `upload_max_filesize = 16M`, `post_max_size = 20M`.
4. Menu Laragon → **Tools** → **Path** → **Add Laragon to Path**, pour que `php`, `composer` et `mysql` (le client de MariaDB) soient disponibles dans tous les terminaux (PowerShell, Git Bash, Claude Code).
5. Redémarrez Laragon (**Stop**, puis **Start All**).

Vérifiez dans un **nouveau** terminal :

```bash
php -v                         # PHP 8.3.16
php -m | grep -iE "bcmath|gmp|intl|pdo_mysql|zip|gd|sodium"
composer -V
```

## 2. MariaDB 10.11 et bases de données

La production Oxahost est en **MariaDB 10.11.19**. Le poste utilise la même version majeure, pour que le développement et les tests portent sur le même moteur. MariaDB utilise le même pilote PHP que MySQL (`pdo_mysql`) ; côté Laravel, la connexion est `mariadb` (`DB_CONNECTION=mariadb`).

### 2.1 Installer MariaDB 10.11 dans Laragon

1. Sur [mariadb.org/download](https://mariadb.org/download/), téléchargez la **dernière version 10.11** pour Windows x86_64, au format **ZIP** (et non MSI).
2. Arrêtez Laragon (**Stop**).
3. Décompressez l'archive dans `C:\laragon\bin\mysql\`. Vous obtenez par exemple `C:\laragon\bin\mysql\mariadb-10.11.x-winx64\`, qui contient directement un dossier `bin\`.
4. Menu Laragon → **MySQL** → **Version** : sélectionnez `mariadb-10.11.x-winx64`.
5. **Start All**. Au premier démarrage, Laragon initialise un nouveau dossier de données. **Ne réutilisez pas les données de MySQL 8.4** : leur format est incompatible avec MariaDB. Les bases `aviator_dev` et `aviator_test` sont recréées ci-dessous ; si `aviator_dev` contenait des données utiles, exportez-les en SQL avant de changer de version.

Vérifiez dans un **nouveau** terminal :

```bash
mysql --version                       # doit mentionner MariaDB et 10.11
mysql -u root -e "SELECT VERSION();"  # 10.11.x-MariaDB
```

Gardez l'ancienne version de MySQL dans `C:\laragon\bin\mysql\` le temps de vérifier que tout fonctionne, puis supprimez-la.

### 2.2 Créer les bases

Laragon démarre MariaDB avec l'utilisateur `root` sans mot de passe. Créez un utilisateur dédié (terminal Laragon ou HeidiSQL) :

```sql
CREATE DATABASE aviator_dev  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE aviator_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'aviator'@'localhost' IDENTIFIED BY 'changez-moi';
GRANT ALL PRIVILEGES ON aviator_dev.*  TO 'aviator'@'localhost';
GRANT ALL PRIVILEGES ON aviator_test.* TO 'aviator'@'localhost';
FLUSH PRIVILEGES;
```

Interclassement imposé : `utf8mb4_unicode_ci`, comme dans la connexion `mariadb` de Laravel. Les interclassements `utf8mb4_0900_*` de MySQL 8 n'existent pas dans MariaDB 10.11.

Dans `.env` **et** dans `.env.testing` (base `aviator_test`) :

```ini
DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=aviator_dev       # aviator_test dans .env.testing
DB_USERNAME=aviator
DB_PASSWORD=<mot de passe>
```

Les tests utilisent MariaDB, pas SQLite : les règles anti-double réservation reposent sur des verrous de ligne que SQLite ne reproduit pas. Le mot de passe va dans `.env` et `.env.testing`, jamais dans Git.

## 3. Node.js

Vite (compilation des assets de Laravel 13) demande une version récente de Node.js.

```bash
node -v      # 22.x recommandé (au minimum 20.19)
npm -v
```

Si la version de Node fournie par Laragon est plus ancienne : Menu Laragon → **Tools** → **Quick add** → une version 22 de Node.js, ou installez Node.js 22 LTS depuis nodejs.org.

## 4. Git pour Windows

Installez **Git for Windows** (git-scm.com), même si Laragon contient déjà Git : Claude Code utilise **Git Bash** (`C:\Program Files\Git\bin\bash.exe`) pour exécuter les commandes, et le script `scripts/deploy.sh` s'exécute dans Git Bash.

Réglages recommandés, une seule fois :

```bash
git config --global core.autocrlf input     # fins de ligne LF dans le dépôt (scripts et serveur Linux)
git config --global init.defaultBranch main
git config --global user.name  "Votre nom"
git config --global user.email "vous@exemple.tn"
```

Générez une clé SSH (pour GitHub et pour l'hébergement Oxahost) :

```bash
ssh-keygen -t ed25519 -C "aviator"
cat ~/.ssh/id_ed25519.pub
```

## 5. Dépôt du projet dans Laragon

Laragon crée automatiquement un domaine local pour chaque dossier de `C:\laragon\www`.

```bash
cd /c/laragon/www
git clone <URL_DU_DEPOT> aviator          # ou : mkdir aviator && cd aviator && git init
cd aviator
# copier le contenu du kit (CLAUDE.md, PROMPT_MAITRE…, docs/, scripts/) à la racine
git add -A
git commit -m "docs: cahier des charges, prompt maître et kit de démarrage"
git push -u origin main
```

Le cahier des charges est fourni dans `docs/reference/cahier_des_charges_aviator.md`.

L'application Laravel sera installée par Claude Code en **phase 2**, dans ce dossier. Ensuite :
- Menu Laragon → **Reload** : le site répond sur `http://aviator.test` (Laragon pointe sur `public/`) ;
- pour tester les notifications push et l'application installable, activez le HTTPS local : Menu Laragon → **Apache** (ou **Nginx**) → **SSL** → **Enabled**, puis `https://aviator.test`.

## 6. Claude Code (Windows natif)

Dans **PowerShell** (sans droits administrateur) :

```powershell
irm https://claude.ai/install.ps1 | iex
```

Ouvrez un nouveau terminal :

```powershell
claude --version
claude doctor
```

Si Claude Code ne trouve pas Git Bash, ajoutez dans `C:\Users\<vous>\.claude\settings.json` :

```json
{
  "env": {
    "CLAUDE_CODE_GIT_BASH_PATH": "C:\\Program Files\\Git\\bin\\bash.exe"
  }
}
```

Lancez toujours Claude Code depuis le dossier du projet, pour que `CLAUDE.md` soit chargé :

```powershell
cd C:\laragon\www\aviator
claude
```

### Skill du projet

Dans cette session Claude Code :

```text
Crée une skill aviator-laravel à partir de PROMPT_MAITRE_AVIATOR_LARAVEL.md,
dans C:\Users\<vous>\.claude\skills\aviator-laravel, découpée en fichiers de référence.
```

L'ancienne skill `tunisia-car-rental-erpnext` (dans `C:\Users\<vous>\.claude\skills\`) ne concerne plus ce projet : supprimez-la ou déplacez-la hors de ce dossier, pour qu'elle ne se déclenche pas à tort.

## 7. Routine quotidienne

| Action | Commande (Git Bash ou terminal Laragon, dans `C:\laragon\www\aviator`) |
| --- | --- |
| Lancer Claude Code | `claude` |
| Ouvrir l'application | `https://aviator.test/admin` (Laragon démarré) |
| Assets en direct | `npm run dev` |
| Tâches planifiées et files | `php artisan schedule:work` |
| Tests | `php artisan test` (ou `--parallel` si ParaTest fonctionne sur le poste) |
| Qualité | `vendor/bin/pint --test && vendor/bin/phpstan analyse && composer audit` |
| Base de dev à zéro | `php artisan migrate:fresh --seed` (jamais en recette ni en production) |
| Déployer | `scripts/deploy.sh recette <branche>` dans Git Bash |

## 8. Problèmes fréquents

| Symptôme | Solution |
| --- | --- |
| `php` ou `composer` introuvable dans Git Bash ou Claude Code | Laragon → Tools → Path → Add Laragon to Path, puis nouveau terminal |
| `could not find driver` | Activer `pdo_mysql` dans Laragon → PHP → Extensions (pilote utilisé aussi par MariaDB) |
| MariaDB ne démarre pas après le changement de version | Dossier de données de MySQL 8.4 réutilisé : laisser Laragon en créer un nouveau, puis recréer les bases (§2.2) |
| `Unknown collation: 'utf8mb4_0900_ai_ci'` | Script ou dump issu de MySQL 8 : remplacer par `utf8mb4_unicode_ci` |
| Les tests passent sur SQLite au lieu de MariaDB | Retirer `DB_CONNECTION` et `DB_DATABASE` de `phpunit.xml` : ces variables priment sur `.env.testing` |
| Erreur `gmp` ou `sodium` lors de l'installation d'un paquet | Activer l'extension, redémarrer Laragon |
| `aviator.test` ne répond pas | Laragon → Reload ; vérifier que le dossier `public/` existe (après la phase 2) |
| Notifications push muettes | HTTPS local activé, navigateur autorisé ; pour l'iPhone, tester sur la recette |
| Script en `\r` ou `bad interpreter` | `git config core.autocrlf input`, puis recloner ou `git add --renormalize .` |
