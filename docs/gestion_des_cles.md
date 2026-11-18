# Gestion des clés — Aviator (D23)

Version du 18/11/2026. Ce document s'adresse au responsable technique et à la direction d'Aviator, qui détient les copies de sauvegarde des clés.

## 1. Règles communes

1. **Une clé par environnement** : le poste, la recette et la production ont chacun leurs propres clés. Elles sont générées **une seule fois**, à l'installation de l'environnement.
2. **Jamais dans Git ni dans la CI** : les clés ne figurent que dans le fichier `shared/.env` du serveur (`~/apps/<env>/shared/.env`), qui n'est ni versionné ni présent dans l'archive de version (D25). La CI génère des clés jetables à chaque exécution.
3. **Copie hors du serveur, en Tunisie** : la valeur de chaque clé de production et de recette est conservée par Aviator dans un **gestionnaire de mots de passe**, plus une **copie papier** sous enveloppe scellée, rangée au siège à Djerba. Aucune copie à l'étranger : pas de service en nuage étranger, pas d'e-mail (loi 2004-63, art. 52).
4. **`php artisan key:generate` n'est jamais lancé en recette ni en production** : les clés y sont générées avec `php -r` à la première installation (§5, D25), et `key:generate` remplacerait ensuite `APP_KEY` sans procédure, rendant illisibles toutes les données chiffrées.
5. **`aviator:check-environment`** signale toute clé absente, vide ou mal formée (ÉCHEC), **sans jamais afficher sa valeur**.
6. Tout changement de clé suit la procédure du §3, application en maintenance et après une sauvegarde vérifiée. Il est noté dans `docs/projet.md` §6 (date, raison, auteur), sans la valeur de la clé.

## 2. Les clés

| Clé | Rôle | Si elle disparaît | Génération (une seule fois) |
| --- | --- | --- | --- |
| `APP_KEY` | Chiffre les données sensibles en base : numéros de pièce d'identité et de permis, secrets et codes de récupération de la 2FA. Signe aussi les sessions, les cookies et les liens signés | **Perte définitive** des numéros de pièce et de permis chiffrés, et des secrets 2FA : chaque utilisateur doit réactiver sa 2FA, et les pièces doivent être ressaisies. Toutes les sessions sont fermées | `php -r 'echo "base64:", base64_encode(random_bytes(32)), PHP_EOL;'` **à l'installation seulement** (§5) |
| `AVIATOR_BLIND_INDEX_KEY` | Clé HMAC des empreintes de recherche exacte (D8) : recherche par pièce ou permis, doublons (K-05), **contrôle de la liste noire** (RG-15) | Les empreintes ne correspondent plus : la recherche exacte et les contrôles de liste noire ne retrouvent plus les clients. **Réparable** : nouvelle clé, puis recalcul (§3.2), tant que `APP_KEY` est intacte | `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"` (64 caractères hexadécimaux) |
| `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` (phase 9) | Signent les notifications push envoyées aux navigateurs | Les abonnements existants deviennent invalides : chaque appareil doit se réabonner (nouvelle autorisation de notification) | Commande fournie en phase 9 |

Les autres secrets du `.env` (mot de passe de la base, du compte e-mail) sont des **identifiants**, pas des clés de chiffrement : ils peuvent être changés à tout moment depuis cPanel, puis reportés dans `.env`.

## 3. Procédures de changement

À appliquer en cas de fuite supposée, de départ d'une personne qui connaissait la clé, ou de rotation planifiée. Toujours dans cet ordre : **sauvegarde vérifiée**, mode maintenance, changement, contrôle, remise en ligne.

### 3.1 `APP_KEY`

```bash
cd ~/apps/<env>/current
php artisan down
# 1. Sauvegarde de la base (mysqldump avec ~/.my.<env>.cnf) et de ~/apps/<env>/shared/.env.
# 2. Dans .env :
#    APP_PREVIOUS_KEYS=<ancienne APP_KEY>     (permet de lire les données encore chiffrées avec l'ancienne)
#    APP_KEY=<nouvelle clé>                    (générée par : php -r 'echo "base64:", base64_encode(random_bytes(32)), PHP_EOL;')
php artisan config:clear
php artisan aviator:reencrypt-secrets --force   # rechiffre toutes les colonnes chiffrées avec la nouvelle clé
php artisan aviator:check-environment
# 3. Une fois la commande réussie : retirer APP_PREVIOUS_KEYS du .env.
php artisan optimize
php artisan up
```

- Les sessions sont fermées : les utilisateurs se reconnectent.
- `aviator:reencrypt-secrets` s'arrête si une donnée n'est lisible avec aucune clé (ancienne clé oubliée dans `APP_PREVIOUS_KEYS`). Elle peut être relancée sans risque.
- La nouvelle clé est reportée dans le gestionnaire de mots de passe et sur une nouvelle copie papier ; l'ancienne copie est détruite après la remise en ligne.

### 3.2 `AVIATOR_BLIND_INDEX_KEY`

```bash
cd ~/apps/<env>/current
php artisan down
# 1. Sauvegarde de la base (mysqldump avec ~/.my.<env>.cnf) et de ~/apps/<env>/shared/.env.
# 2. Dans .env : AVIATOR_BLIND_INDEX_KEY=<nouvelle clé de 64 caractères hexadécimaux>
php artisan config:clear
php artisan aviator:rehash-blind-index --force   # recalcule toutes les empreintes avec la nouvelle clé
php artisan aviator:check-environment
php artisan optimize
php artisan up
```

Tant que le recalcul n'est pas terminé, la recherche exacte et les contrôles de liste noire sont faussés : l'application doit rester en maintenance.

### 3.3 Clés VAPID (phase 9)

La procédure sera ajoutée en phase 9. Principe : nouvelles clés, puis les appareils se réabonnent à leur prochaine connexion.

## 4. Restauration trimestrielle de la production sur la recette

Le cahier des charges (§6.5) prévoit de restaurer chaque trimestre la sauvegarde de production sur la recette. Conséquences :

| Sujet | Règle |
| --- | --- |
| Clés | Les données restaurées sont chiffrées avec l'`APP_KEY` de production et indexées avec sa clé d'empreinte. Pour les lire, la recette doit **temporairement** utiliser les deux clés de production, ou les placer en `APP_PREVIOUS_KEYS` et lancer `aviator:reencrypt-secrets`, puis `aviator:rehash-blind-index` avec ses propres clés. **Méthode retenue** : la recette garde ses propres clés ; on place l'`APP_KEY` de production en `APP_PREVIOUS_KEYS`, on lance les deux commandes, puis on retire la clé de production du `.env` de recette |
| Données réelles | La recette contient alors les **données personnelles réelles** des clients : même protection que la production (accès protégé par mot de passe, comptes nominatifs, même hébergeur en Tunisie). Seules les personnes qui ont accès à la production y accèdent |
| Envois | **Aucun envoi réel depuis la recette** : avant la restauration, désactiver les notifications push et l'e-mail (`MAIL_MAILER=log`, pas de clés VAPID de production) et suspendre le cron de recette, pour qu'aucun client ou utilisateur ne reçoive de message issu de la recette |
| Après le test | Le test réussi est noté dans le rapport. La base de recette est ensuite remplacée par des données de test, ou conservée seulement le temps de la vérification (durée à fixer avec la question Q20) |

## 5. Première installation d'un environnement

Ces étapes font partie de `docs/installation_recette.md` (étapes 4 à 7), valables aussi pour la production :

1. Premier lancement de `install-release.sh` : il crée `~/apps/<env>/shared/.env` à partir du modèle, puis s'arrête.
2. Générer les deux clés dans le terminal web de cPanel, **une seule fois** :
   `php -r 'echo "APP_KEY=base64:", base64_encode(random_bytes(32)), PHP_EOL;'`
   `php -r 'echo "AVIATOR_BLIND_INDEX_KEY=", bin2hex(random_bytes(32)), PHP_EOL;'`
3. Reporter les deux clés dans le gestionnaire de mots de passe d'Aviator et sur la copie papier, avec le nom de l'environnement et la date.
4. Les placer dans `shared/.env`, avec la base, l'URL et l'e-mail.
5. Deuxième lancement de `install-release.sh` : migrations, puis `aviator:check-environment`, qui ne doit signaler aucune clé absente ou vide.

## 6. Accès de secours

### 6.1 Codes de récupération de la 2FA

La 2FA du back-office (application d'authentification, TOTP) fournit des **codes de récupération** :

- ils s'affichent **une seule fois**, à la dernière étape de l'activation de la 2FA, avec la consigne de les conserver et des boutons pour les copier ou les télécharger ;
- chaque code ne sert qu'**une fois**, à la place du code de l'application ; il est stocké haché (puis chiffré), jamais en clair ;
- le titulaire peut en générer de nouveaux depuis sa page de profil, ce qui annule les anciens.

**Consigne pour le super admin et l'admin** : imprimer les codes de récupération et les ranger avec la copie papier des clés (§1, règle 3), sous enveloppe scellée à son nom. Ne jamais les conserver sur le téléphone qui porte l'application d'authentification.

### 6.2 Réinitialisation de la 2FA : `aviator:reset-2fa`

À utiliser seulement quand le titulaire a perdu **à la fois** son application d'authentification et ses codes de récupération, après vérification de son identité de vive voix. La commande est **réservée au terminal** du serveur : aucun écran de l'application ne permet de désactiver la 2FA d'un autre compte.

```bash
php artisan aviator:reset-2fa direction@<domaine>            # recette
php artisan aviator:reset-2fa direction@<domaine> --force    # production (refusée sans --force)
```

La commande :

1. affiche le nom et le profil du compte, et **demande une confirmation** ;
2. efface le secret de la 2FA et les codes de récupération ;
3. **coupe toutes les sessions** du compte et ses jetons « se souvenir de moi » ;
4. inscrit l'opération dans le **journal de sécurité** (`activity_log`, journal `security`, événement `two_factor_reset`) ;
5. impose une **nouvelle activation** de la 2FA à la prochaine connexion (super admin et admin ; utilisateur d'agence si le réglage `security.require_2fa_for_agency_users` est actif, Q76).

Le titulaire se reconnecte avec son mot de passe, active la 2FA sur son nouveau téléphone et conserve ses **nouveaux** codes de récupération. L'opération est notée dans `docs/projet.md` §6 (date, compte, raison, auteur).

### 6.3 Mot de passe oublié

Le titulaire utilise « Mot de passe oublié ? » sur la page de connexion, ou le super admin lui envoie un lien de réinitialisation depuis la gestion des utilisateurs. Le lien arrive par e-mail : c'est l'un des seuls usages de l'e-mail dans l'application (sécurité des comptes). Pour un super admin qui n'a plus accès à sa boîte e-mail, le **compte de secours** (second super admin, Q2) lui envoie le lien après mise à jour de son adresse, ou le responsable technique procède en ligne de commande.
