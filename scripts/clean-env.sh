#!/usr/bin/env bash
# Lance une commande dans un environnement débarrassé des variables de l'application.
#
# Usage : scripts/clean-env.sh <commande> [arguments…]
#   exemples : scripts/clean-env.sh php artisan test --filter=Agency
#              scripts/clean-env.sh composer run-script check-steps
#
# Retire de l'environnement toute variable dont le nom figure dans .env.example ou dans
# .env (à la racine du dépôt qui contient ce script), puis exécute la commande. Seuls
# les noms sont lus : aucune valeur n'est lue, stockée ni affichée.
#
# Raison (incident du 19/11/2026) : une variable d'environnement prime sur les fichiers
# .env.testing et phpunit.xml. Un outil qui charge .env dans l'environnement (Claude Code,
# un terminal où l'on a fait « source .env »…) ferait tourner les tests sur la base de
# développement. Le garde-fou de tests/TestCase.php reste en place derrière ce script.
#
# La commande lancée reçoit AVIATOR_CLEAN_ENV=1 (contrôlé par composer check-steps).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [ "$#" -eq 0 ]; then
  echo "Usage : scripts/clean-env.sh <commande> [arguments…]" >&2
  exit 64
fi

# Variables du système, jamais retirées même si un fichier les nommait.
PROTECTED=" PATH HOME SHELL USER USERNAME TMPDIR TMP TEMP SYSTEMROOT SYSTEMDRIVE COMSPEC PATHEXT WINDIR USERPROFILE APPDATA LOCALAPPDATA PROGRAMDATA "

# Noms seulement : la partie après « = » n'est jamais capturée.
env_names() {
  local file
  for file in "$@"; do
    [ -f "$file" ] || continue
    sed -nE 's/^[[:space:]]*(export[[:space:]]+)?([A-Za-z_][A-Za-z0-9_]*)[[:space:]]*=.*/\2/p' "$file"
  done | sort -u
}

removed=0
while IFS= read -r name; do
  [ -n "$name" ] || continue
  case "$PROTECTED" in *" $name "*) continue ;; esac
  if [ -n "${!name+x}" ]; then
    unset -v "$name"
    removed=$((removed + 1))
  fi
done < <(env_names "$ROOT/.env.example" "$ROOT/.env")

echo "clean-env : ${removed} variable(s) de .env.example ou .env retirée(s) de l'environnement." >&2

export AVIATOR_CLEAN_ENV=1
exec "$@"
