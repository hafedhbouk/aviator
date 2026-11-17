# Gestion des clés — Aviator (D23)

Version du 18/11/2026. Ce document s'adresse au responsable technique et à la direction d'Aviator, qui détient les copies de sauvegarde des clés.

## 1. Règles communes

1. **Une clé par environnement** : le poste, la recette et la production ont chacun leurs propres clés. Elles sont générées **une seule fois**, à l'installation de l'environnement.
2. **Jamais dans Git ni dans la CI** : les clés ne figurent que dans le fichier `.env` du serveur, qui n'est pas versionné. La CI génère des clés jetables à chaque exécution.
3. **Copie hors du serveur, en Tunisie** : la valeur de chaque clé de production et de recette est conservée par Aviator dans un **gestionnaire de mots de passe**, plus une **copie papier** sous enveloppe scellée, rangée au siège à Djerba. Aucune copie à l'étranger : pas de service en nuage étranger, pas d'e-mail (loi 2004-63, art. 52).
4. **`php artisan key:generate` est interdit en recette et en production une fois l'installation faite** : il remplacerait `APP_KEY` sans procédure et rendrait illisibles toutes les données chiffrées.
5. **`aviator:check-environment`** signale toute clé absente, vide ou mal formée (ÉCHEC), **sans jamais afficher sa valeur**.
6. Tout changement de clé suit la procédure du §3, application en maintenance et après une sauvegarde vérifiée. Il est noté dans `docs/projet.md` §6 (date, raison, auteur), sans la valeur de la clé.

## 2. Les clés

| Clé | Rôle | Si elle disparaît | Génération (une seule fois) |
| --- | --- | --- | --- |
| `APP_KEY` | Chiffre les données sensibles en base : numéros de pièce d'identité et de permis, secrets et codes de récupération de la 2FA. Signe aussi les sessions, les cookies et les liens signés | **Perte définitive** des numéros de pièce et de permis chiffrés, et des secrets 2FA : chaque utilisateur doit réactiver sa 2FA, et les pièces doivent être ressaisies. Toutes les sessions sont fermées | `php artisan key:generate` **à l'installation seulement** |
| `AVIATOR_BLIND_INDEX_KEY` | Clé HMAC des empreintes de recherche exacte (D8) : recherche par pièce ou permis, doublons (K-05), **contrôle de la liste noire** (RG-15) | Les empreintes ne correspondent plus : la recherche exacte et les contrôles de liste noire ne retrouvent plus les clients. **Réparable** : nouvelle clé, puis recalcul (§3.2), tant que `APP_KEY` est intacte | `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"` (64 caractères hexadécimaux) |
| `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` (phase 9) | Signent les notifications push envoyées aux navigateurs | Les abonnements existants deviennent invalides : chaque appareil doit se réabonner (nouvelle autorisation de notification) | Commande fournie en phase 9 |

Les autres secrets du `.env` (mot de passe de la base, du compte e-mail) sont des **identifiants**, pas des clés de chiffrement : ils peuvent être changés à tout moment depuis cPanel, puis reportés dans `.env`.

## 3. Procédures de changement

À appliquer en cas de fuite supposée, de départ d'une personne qui connaissait la clé, ou de rotation planifiée. Toujours dans cet ordre : **sauvegarde vérifiée**, mode maintenance, changement, contrôle, remise en ligne.

### 3.1 `APP_KEY`

```bash
php artisan down
# 1. Sauvegarde de la base (deploy.sh ou mysqldump) et de .env.
# 2. Dans .env :
#    APP_PREVIOUS_KEYS=<ancienne APP_KEY>     (permet de lire les données encore chiffrées avec l'ancienne)
#    APP_KEY=<nouvelle clé>                    (générée sur le poste : php artisan key:generate --show)
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
php artisan down
# 1. Sauvegarde de la base et de .env.
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

Ces étapes s'ajoutent à `docs/installation_hebergement_oxahost.md` §4 :

1. `cp .env.example .env`, puis compléter la base, l'URL et l'e-mail.
2. `php artisan key:generate` : **une seule fois**, à cette étape.
3. Générer `AVIATOR_BLIND_INDEX_KEY` (64 caractères hexadécimaux) et la placer dans `.env`.
4. Reporter les deux clés dans le gestionnaire de mots de passe d'Aviator et sur la copie papier, avec le nom de l'environnement et la date.
5. `php artisan aviator:check-environment` : aucune clé absente ou vide.
6. Seulement ensuite : `php artisan migrate --force`, puis la suite de la procédure.
