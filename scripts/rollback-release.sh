#!/usr/bin/env bash
# Revient à la version précédente d'Aviator (D25), dans le terminal web de cPanel.
#
# Usage : bash rollback-release.sh <recette|prod>
#   Variable facultative : AVIATOR_PHP=/opt/alt/php83/usr/bin/php si « php » n'est pas PHP 8.3.
#
# ATTENTION : les migrations de base ne sont PAS annulées. Si la version qui a été
# installée a modifié la base d'une façon que la version précédente ne comprend pas,
# restaurer aussi la sauvegarde prise juste avant l'installation (~/backups/).
#
# Pas de « set -e » global : chaque étape est vérifiée, avec un arrêt propre.

ENV_NAME="${1:-}"
PHP_BIN="${AVIATOR_PHP:-php}"

step() { printf '\n== %s\n' "$1"; }
info() { printf '   %s\n' "$1"; }

fail() {
  printf '\nERREUR : %s\n' "$1" >&2
  if [ -n "${2:-}" ]; then printf 'Action conseillée : %s\n' "$2" >&2; fi
  printf 'Retour arrière arrêté.\n' >&2
  exit 1
}

case "$ENV_NAME" in
  recette | prod) ;;
  *) fail "Environnement inconnu : « $ENV_NAME »." "bash rollback-release.sh <recette|prod>" ;;
esac

APP_DIR="$HOME/apps/$ENV_NAME"
HISTORY="$APP_DIR/releases/.history"

step "1/5 Versions installées"
[ -L "$APP_DIR/current" ] || fail "Aucune version en service ($APP_DIR/current absent)."
CURRENT_TARGET="$(readlink "$APP_DIR/current")"
CURRENT_VERSION="${CURRENT_TARGET#releases/}"
info "En service : $CURRENT_VERSION"

# Version précédente : la dernière de l'historique, différente de la version en service,
# dont le dossier existe encore.
PREVIOUS_VERSION=""
if [ -f "$HISTORY" ]; then
  while read -r _ version; do
    if [ -n "$version" ] && [ "$version" != "$CURRENT_VERSION" ] && [ -d "$APP_DIR/releases/$version" ]; then
      PREVIOUS_VERSION="$version"
    fi
  done < "$HISTORY"
fi
[ -n "$PREVIOUS_VERSION" ] || fail "Aucune version précédente disponible." "Installer une archive antérieure avec install-release.sh."
info "Version précédente : $PREVIOUS_VERSION"

printf '\n   Les migrations de base ne sont PAS annulées par ce retour arrière.\n'
if [ "$ENV_NAME" = "prod" ]; then
  printf '\nRevenir à %s en PRODUCTION ? Tapez « OUI %s » pour confirmer : ' "$PREVIOUS_VERSION" "$PREVIOUS_VERSION"
else
  printf '\nRevenir à %s sur la recette ? Tapez « OUI » pour confirmer : ' "$PREVIOUS_VERSION"
fi
read -r CONFIRM
if [ "$ENV_NAME" = "prod" ]; then
  [ "$CONFIRM" = "OUI $PREVIOUS_VERSION" ] || fail "Confirmation refusée : rien n'a été modifié."
else
  [ "$CONFIRM" = "OUI" ] || fail "Confirmation refusée : rien n'a été modifié."
fi

step "2/5 Mise en maintenance"
"$PHP_BIN" "$APP_DIR/current/artisan" down --retry=60 || fail "Mise en maintenance impossible."

step "3/5 Bascule vers $PREVIOUS_VERSION"
ln -sfn "releases/$PREVIOUS_VERSION" "$APP_DIR/current.new" || fail "Lien current.new impossible."
mv -Tf "$APP_DIR/current.new" "$APP_DIR/current" || fail "Bascule impossible." "Remettre en ligne : $PHP_BIN $APP_DIR/current/artisan up"
printf '%s %s (retour arrière depuis %s)\n' "$(date +%Y-%m-%dT%H:%M:%S)" "$PREVIOUS_VERSION" "$CURRENT_VERSION" >> "$HISTORY"

step "4/5 Optimisation et remise en ligne"
"$PHP_BIN" "$APP_DIR/current/artisan" optimize || fail "Optimisation impossible." "Remettre en ligne : $PHP_BIN $APP_DIR/current/artisan up"
"$PHP_BIN" "$APP_DIR/current/artisan" up || fail "Remise en ligne impossible." "Relancer : $PHP_BIN $APP_DIR/current/artisan up"

step "5/5 Contrôle de l'environnement"
"$PHP_BIN" "$APP_DIR/current/artisan" aviator:check-environment

printf '\nRetour à %s terminé sur %s.\n' "$PREVIOUS_VERSION" "$ENV_NAME"
printf 'RAPPEL : les migrations de %s ne sont pas annulées. Si la base ne convient plus à %s,\n' "$CURRENT_VERSION" "$PREVIOUS_VERSION"
printf 'restaurer la sauvegarde ~/backups/%s_*_avant_%s.sql.tar.gz (cPanel ou mysql).\n' "$ENV_NAME" "$CURRENT_VERSION"
