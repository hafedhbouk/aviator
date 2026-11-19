#!/usr/bin/env bash
# Écrit .env.testing pour les workflows GitHub (CI et Release), à partir de
# .env.testing.example. Réservé à la CI : refuse de tourner ailleurs ou d'écraser un
# fichier existant.
#
# Les tests lisent leur configuration dans .env.testing : composer check passe par
# scripts/clean-env.sh, qui retire de l'environnement les variables de l'application ;
# des variables DB_* déclarées au niveau du job seraient donc ignorées.
#
# Aucun secret : base du conteneur MariaDB jetable (root, mot de passe vide), APP_KEY et
# AVIATOR_BLIND_INDEX_KEY tirées au hasard à chaque exécution et jamais affichées.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="$ROOT/.env.testing"

[ "${GITHUB_ACTIONS:-}" = "true" ] || { echo "ci-env-testing.sh : réservé aux workflows GitHub." >&2; exit 1; }
[ ! -e "$TARGET" ] || { echo "ci-env-testing.sh : $TARGET existe déjà." >&2; exit 1; }

app_key="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
blind_key="$(php -r 'echo bin2hex(random_bytes(32));')"

sed -E \
  -e 's/^DB_HOST=.*/DB_HOST=127.0.0.1/' \
  -e 's/^DB_DATABASE=.*/DB_DATABASE=aviator_test/' \
  -e 's/^DB_USERNAME=.*/DB_USERNAME=root/' \
  -e 's/^DB_PASSWORD=.*/DB_PASSWORD=/' \
  -e '/^APP_KEY=/d' \
  -e '/^AVIATOR_BLIND_INDEX_KEY=/d' \
  "$ROOT/.env.testing.example" > "$TARGET"

printf 'APP_KEY=%s\nAVIATOR_BLIND_INDEX_KEY=%s\n' "$app_key" "$blind_key" >> "$TARGET"
chmod 600 "$TARGET"

echo "ci-env-testing.sh : .env.testing écrit (base aviator_test, clés tirées au hasard)."
