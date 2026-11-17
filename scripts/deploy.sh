#!/usr/bin/env bash
# Déploiement d'Aviator sur l'hébergement mutualisé Oxahost (cPanel).
# Usage : scripts/deploy.sh <recette|prod> <branche-ou-etiquette>
# Variables : AVIATOR_SSH_HOST (obligatoire), AVIATOR_PHP, AVIATOR_COMPOSER (facultatives).
# À lancer dans Git Bash (Windows) depuis la racine du projet ; utilise ssh et tar (pas de rsync).
# Exécuté par le responsable technique, jamais par Claude Code.
set -euo pipefail

ENV_NAME="${1:?Usage : scripts/deploy.sh <recette|prod> <branche-ou-etiquette>}"
REF="${2:?Indiquez une branche (recette) ou une étiquette vX.Y.Z (production)}"
SSH_HOST="${AVIATOR_SSH_HOST:?Définissez AVIATOR_SSH_HOST=utilisateur@serveur}"
PHP_BIN="${AVIATOR_PHP:-php}"
COMPOSER_BIN="${AVIATOR_COMPOSER:-composer}"

case "$ENV_NAME" in
  recette) APP_DIR="apps/recette" ;;
  prod)
    APP_DIR="apps/prod"
    if [[ ! "$REF" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
      echo "Production : seule une étiquette vX.Y.Z est acceptée." >&2; exit 1
    fi
    read -r -p "Déployer $REF en PRODUCTION ? Tapez OUI : " CONFIRM
    [[ "$CONFIRM" == "OUI" ]] || { echo "Annulé."; exit 1; }
    ;;
  *) echo "Environnement inconnu : $ENV_NAME" >&2; exit 1 ;;
esac

echo "== 1/5 Tests et compilation des assets en local"
php artisan test
npm ci
npm run build

echo "== 2/5 Sauvegarde de la base et mise en maintenance"
ssh "$SSH_HOST" bash -s <<EOF
set -euo pipefail
cd ~/$APP_DIR
mkdir -p ~/backups
DB_NAME=\$(grep -E '^DB_DATABASE=' .env | cut -d= -f2-)
mysqldump --defaults-extra-file="\$HOME/.my.${ENV_NAME}.cnf" --single-transaction --no-tablespaces "\$DB_NAME" \
  | gzip > ~/backups/${ENV_NAME}_\$(date +%Y%m%d_%H%M%S).sql.gz
$PHP_BIN artisan down --retry=60 || true
EOF

echo "== 3/5 Récupération de $REF et dépendances"
ssh "$SSH_HOST" bash -s <<EOF
set -euo pipefail
cd ~/$APP_DIR
git fetch origin --tags --force --prune
if git show-ref --verify --quiet "refs/remotes/origin/$REF"; then
  git checkout --force -B "$REF" "origin/$REF"
else
  git checkout --force "$REF"
fi
$COMPOSER_BIN install --no-dev --optimize-autoloader --no-interaction
EOF

echo "== 4/5 Envoi des assets compilés"
tar -czf - -C public build | ssh "$SSH_HOST" "rm -rf ~/$APP_DIR/public/build && tar -xzf - -C ~/$APP_DIR/public"

echo "== 5/5 Migrations, optimisation et remise en ligne"
ssh "$SSH_HOST" bash -s <<EOF
set -euo pipefail
cd ~/$APP_DIR
$PHP_BIN artisan migrate --force
$PHP_BIN artisan optimize
$PHP_BIN artisan up
EOF

echo "Déploiement de $REF sur $ENV_NAME terminé."
