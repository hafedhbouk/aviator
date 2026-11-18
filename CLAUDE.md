# CLAUDE.md — Aviator (Laravel)

Application de location de voitures pour Aviator (4 agences, 6 utilisateurs, ~1 000 clients), en Laravel 13 / PHP 8.3 / MariaDB 10.11 / Filament v5.
Le détail complet est dans la skill **`aviator-laravel`** : charge-la pour toute tâche sur ce dépôt. Source fonctionnelle : `docs/reference/cahier_des_charges_aviator.md`.

## Avant toute tâche

1. Lis `docs/projet.md` : phase en cours, validations, décisions.
2. Ne travaille que sur la phase en cours, sur sa branche.
3. Lis dans la skill uniquement les références utiles à la tâche.
4. Il me manque une information de `docs/projet.md` ? Demande-la avant d'agir.

## Règles non négociables

1. **Plan avant code**, validé quand la phase le demande.
2. **Rien n'est « fait » sans test exécuté** : cite la commande et son résultat.
3. **Aucune règle fiscale inventée** (TVA, timbre, mentions, numérotation) : seuls les documents de `docs/reference/regles_fiscales_validees/` font foi. Une règle fiscale est validée si elle y figure avec une **confirmation écrite du cabinet** ou une **attestation écrite de la direction d'Aviator**.
   **Référentiel fiscal versionné** : la version validée dans `regles_fiscales_validees/` ne se modifie jamais. Toute nouvelle version du référentiel exige une nouvelle confirmation signée du cabinet ; elle s'ajoute à côté de l'ancienne, avec sa propre confirmation.
4. **Signaler plutôt que deviner** : question dans `docs/questions_ouvertes.md` + valeur par défaut paramétrable.
5. **Budget** : lot 1 = 245 j, lot 2 = 45 j ; phase en dépassement de plus de 20 % → arrête-toi et signale-le.
6. **Fin de phase** : rapport `docs/rapports/phase_N.md`, puis attente d'une validation explicite.
7. **Hors périmètre** : comptabilité générale, paiement en ligne, SMS, application native.

## Poste de développement

- Windows 11 + Laragon : PHP 8.3.16, MariaDB 10.11 (comme en production), dépôt dans `D:\aviator`, exposé à Laragon par la jonction `C:\laragon\www\aviator` → `D:\aviator` ; site local `https://aviator.test`.
- Commandes exécutées dans Git Bash depuis `/d/aviator` (chemins au format `/d/aviator/...`), fins de ligne LF. Git : `D:/aviator` en `safe.directory` global.
- Production en MariaDB 10.11.19 : `DB_CONNECTION=mariadb` ; n'utilise aucune fonctionnalité propre à MySQL 8 (index fonctionnels, index JSON, `joinLateral`, interclassements `utf8mb4_0900_*`).

## Contraintes techniques

- **PHP 8.3 maximum**, **MariaDB 10.11**, hébergement **mutualisé** : pas de Redis, pas de Supervisor, pas de processus permanent ; files en `database` traitées par le planificateur (cron chaque minute) ; assets compilés avant déploiement.
- **Déploiement par archive (D25)** : `scripts/build-release.sh` sur le poste, `install-release.sh` dans le terminal web de cPanel ; aucune connexion SSH depuis le poste ; ni Composer, ni npm, ni Git sur le serveur (`exec` et `proc_open` y sont désactivées).
- Montants en `DECIMAL(15,3)`, calculs avec `brick/math`, jamais de `float`.
- Règles métier dans `app/Domain/*`, appelées par Filament et par l'espace client ; jamais dans les ressources ou les vues.
- Droits contrôlés côté serveur (policies) ; `AgencyScope` pour les utilisateurs d'agence.
- Aucune lecture de données d'agence par `DB::table()` ou par du SQL brut, sauf dans `App\Domain\Statistics`, qui applique la portée explicitement (test d'architecture : `DB::table`, `DB::select` et `DB::raw` interdits dans `app/` hors de ce dossier).
- **Carnet d'adresses complet et exports clients : super admin uniquement** ; vérifier aussi recherche globale, sélecteurs, exports de tableaux.
- Notifications : **push uniquement** (Web Push + `database`), sans donnée sensible dans le texte ; e-mail seulement pour la sécurité des comptes.
- Code en anglais ; interface, messages et documentation en français.

## Interdits

- Toute action sur les serveurs de recette ou de production : prépare les commandes, le responsable technique les exécute.
- Toute donnée copiée hors de Tunisie (sauvegardes ou services étrangers).
- Désactiver une validation, une policy ou un test pour faire passer une fonctionnalité.
- SQL avec valeurs concaténées ; secrets dans le code, les seeders ou Git.
- `git add` sur un dossier entier (`git add docs`, `git add .`, `git add -A`) : ajoute les fichiers un par un, après les avoir lus.
- Paquet payant sans accord écrit ; paquet non compatible PHP 8.3.

## Commandes

```bash
composer check   # contrôles de la CI, sans aucun cache : Pint (test), Larastan, Pest sur MariaDB, composer audit, npm run build
```

- `composer check` est **la** commande de contrôle : la CI GitHub l'exécute telle quelle, le poste et la CI ne peuvent donc pas diverger. Elle supprime le cache de Pint, vide celui de Larastan et celui de la configuration avant de lancer les contrôles.
- Lance `composer check` **avant chaque commit** et cite son résultat (nombre de tests, statut de chaque outil) dans le compte rendu.
- Un contrôle isolé reste possible pendant le développement (`php artisan test --filter=…`), mais ne vaut jamais validation.

Branches : `phase/NN-nom` ; commits `feat:` / `fix:` / `test:` / `docs:` / `chore:`.
