# Installation du poste de développement (Windows 11 + Laragon)

Poste du développeur : **Windows 11**, **Laragon** avec **PHP 8.3.16** et **MySQL 8.4.3**.
Ce document complète Laragon pour le projet Aviator :
- extensions PHP nécessaires à Laravel 13, Filament et aux notifications push ;
- deux bases MySQL : `aviator_dev` (développement) et `aviator_test` (tests automatisés) ;
- Node.js pour compiler les assets ;
- Git pour Windows (Git Bash), nécessaire à Claude Code et au script de déploiement ;
- Claude Code installé nativement sous Windows.

Durée : environ 45 minutes.

> **Écart de versions à surveiller** : la production Oxahost propose PHP 8.3 (comme vous) et « MySQL 8 » sans préciser la version. Votre MySQL 8.4.3 est plus récent : n'utilisez aucune fonctionnalité propre à MySQL 8.4 et faites confirmer la version exacte par Oxahost (voir `docs/projet.md`).

---

## 1. PHP 8.3 dans Laragon

1. Menu Laragon → **PHP** → **Version** : sélectionnez `php-8.3.16`.
2. Menu Laragon → **PHP** → **Extensions** : cochez au minimum `bcmath`, `fileinfo`, `gd`, `gmp`, `intl`, `mbstring`, `openssl`, `pdo_mysql`, `sodium`, `zip`.
3. Menu Laragon → **PHP** → **php.ini** : `memory_limit = 512M`, `upload_max_filesize = 16M`, `post_max_size = 20M`.
4. Menu Laragon → **Tools** → **Path** → **Add Laragon to Path**, pour que `php`, `composer` et `mysql` soient disponibles dans tous les terminaux (PowerShell, Git Bash, Claude Code).
5. Redémarrez Laragon (**Stop**, puis **Start All**).

Vérifiez dans un **nouveau** terminal :

```bash
php -v                         # PHP 8.3.16
php -m | grep -iE "bcmath|gmp|intl|pdo_mysql|zip|gd|sodium"
composer -V
```

## 2. Bases MySQL

Laragon fournit MySQL 8.4.3 avec l'utilisateur `root` sans mot de passe. Créez un utilisateur dédié (terminal Laragon ou HeidiSQL) :

```sql
CREATE DATABASE aviator_dev  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE aviator_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'aviator'@'localhost' IDENTIFIED BY 'changez-moi';
GRANT ALL PRIVILEGES ON aviator_dev.*  TO 'aviator'@'localhost';
GRANT ALL PRIVILEGES ON aviator_test.* TO 'aviator'@'localhost';
FLUSH PRIVILEGES;
```

Les tests utilisent MySQL, pas SQLite : les règles anti-double réservation reposent sur des verrous de ligne que SQLite ne reproduit pas. Le mot de passe va dans `.env` et `.env.testing`, jamais dans Git.

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
| `could not find driver` | Activer `pdo_mysql` dans Laragon → PHP → Extensions |
| Erreur `gmp` ou `sodium` lors de l'installation d'un paquet | Activer l'extension, redémarrer Laragon |
| `aviator.test` ne répond pas | Laragon → Reload ; vérifier que le dossier `public/` existe (après la phase 2) |
| Notifications push muettes | HTTPS local activé, navigateur autorisé ; pour l'iPhone, tester sur la recette |
| Script en `\r` ou `bad interpreter` | `git config core.autocrlf input`, puis recloner ou `git add --renormalize .` |
