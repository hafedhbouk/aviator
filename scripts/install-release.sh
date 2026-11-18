#!/usr/bin/env bash
# Installe une archive de version d'Aviator (D25), dans le terminal web de cPanel.
#
# Usage : bash install-release.sh <recette|prod> <chemin de aviator-<version>.tar.gz>
#   (toujours « bash install-release.sh … », jamais « source » : une erreur arrête le
#   script, pas le terminal)
#   Variable facultative : AVIATOR_PHP=/opt/alt/php83/usr/bin/php si « php » n'est pas PHP 8.3.
#
# Arborescence : ~/apps/<env>/
#   releases/<version>/   versions installées (les 3 dernières sont conservées)
#   shared/.env           configuration et secrets (jamais dans l'archive)
#   shared/storage/       fichiers, sessions, journaux (communs à toutes les versions)
#   current -> releases/<version>   lien vers la version en service ; racine web : current/public
#
# Outils utilisés : bash, tar, php, mysqldump (et les commandes de base : mkdir, ln, mv,
# rm, cp, readlink, date). Ni Composer, ni npm, ni Git sur le serveur.
#
# Pas de « set -e » global : chaque étape est vérifiée, avec un message clair, une
# action conseillée et un arrêt propre.

ENV_NAME="${1:-}"
ARCHIVE="${2:-}"
PHP_BIN="${AVIATOR_PHP:-php}"
KEEP_RELEASES=3

step() { printf '\n== %s\n' "$1"; }
info() { printf '   %s\n' "$1"; }

fail() {
  printf '\nERREUR : %s\n' "$1" >&2
  if [ -n "${2:-}" ]; then printf 'Action conseillée : %s\n' "$2" >&2; fi
  # Une version décompressée mais jamais mise en service est retirée : la même
  # commande peut être relancée après correction.
  if [ "${SWITCHED:-0}" -eq 0 ] && [ -n "${RELEASE_DIR:-}" ] && [ -d "$RELEASE_DIR" ]; then
    rm -rf "$RELEASE_DIR" && printf 'Version non mise en service retirée : %s\n' "$RELEASE_DIR" >&2
  fi
  printf 'Installation arrêtée. La version en service (si elle existe) est inchangée' >&2
  if [ "${MAINTENANCE_ON:-0}" -eq 1 ]; then
    printf ', mais en MAINTENANCE.\nPour la remettre en ligne si la base est compatible : %s %s/current/artisan up\n' "$PHP_BIN" "$APP_DIR" >&2
  else
    printf '.\n' >&2
  fi
  exit 1
}

env_value() {
  # Lit une valeur de shared/.env sans grep ni sed.
  local key="$1" line name value
  while IFS= read -r line || [ -n "$line" ]; do
    name="${line%%=*}"
    if [ "$name" = "$key" ]; then
      value="${line#*=}"
      value="${value%\"}"
      value="${value#\"}"
      printf '%s' "$value"
      return 0
    fi
  done < "$APP_DIR/shared/.env"
  return 1
}

sha256_of() {
  if command -v sha256sum > /dev/null 2>&1; then
    local out
    out="$(sha256sum "$1")" || return 1
    printf '%s' "${out%% *}"
  else
    "$PHP_BIN" -r 'echo hash_file("sha256", $argv[1]);' "$1"
  fi
}

artisan() {
  # artisan <dossier de version> <commande…>
  local release="$1"
  shift
  "$PHP_BIN" "$release/artisan" "$@"
}

# --- 0. Paramètres --------------------------------------------------------------

case "$ENV_NAME" in
  recette | prod) ;;
  *) fail "Environnement inconnu : « $ENV_NAME »." "bash install-release.sh <recette|prod> <archive.tar.gz>" ;;
esac

[ -n "$ARCHIVE" ] && [ -f "$ARCHIVE" ] || fail "Archive introuvable : « $ARCHIVE »." "Envoyer aviator-<version>.tar.gz et son .sha256 dans ~/ (FTPS ou gestionnaire de fichiers)."

APP_DIR="$HOME/apps/$ENV_NAME"
MAINTENANCE_ON=0
SWITCHED=0
RELEASE_DIR=""

step "0/9 Vérifications préalables ($ENV_NAME)"
PHP_VERSION="$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2> /dev/null)"
[ "$PHP_VERSION" = "8.3" ] || fail "« $PHP_BIN » n'est pas PHP 8.3 (version : ${PHP_VERSION:-introuvable})." "Relancer avec AVIATOR_PHP=/opt/alt/php83/usr/bin/php bash install-release.sh …"
info "PHP $PHP_VERSION"
mkdir -p "$APP_DIR/releases" "$APP_DIR/shared" "$HOME/backups" || fail "Impossible de créer $APP_DIR." "Vérifier l'espace disque et les droits du compte."

# --- 1. Empreinte ---------------------------------------------------------------

step "1/9 Empreinte SHA-256"
SUM_FILE="$ARCHIVE.sha256"
[ -f "$SUM_FILE" ] || fail "Fichier d'empreinte introuvable : $SUM_FILE." "Envoyer aussi le fichier .sha256 produit par build-release.sh, à côté de l'archive."
read -r EXPECTED _ < "$SUM_FILE"
ACTUAL="$(sha256_of "$ARCHIVE")" || fail "Calcul de l'empreinte impossible."
[ -n "$EXPECTED" ] && [ "$EXPECTED" = "$ACTUAL" ] || fail "Empreinte différente : l'archive est incomplète ou modifiée." "Renvoyer l'archive (en mode binaire si FTPS), puis relancer."
info "Conforme : $ACTUAL"

# --- 2. Version -----------------------------------------------------------------

step "2/9 Version"
RELEASE_META="$(tar -xzf "$ARCHIVE" -O ./RELEASE 2> /dev/null)" || fail "Fichier RELEASE absent de l'archive." "Reconstruire l'archive avec scripts/build-release.sh."
VERSION=""
while IFS= read -r line; do
  case "$line" in version=*) VERSION="${line#version=}" ;; esac
done <<< "$RELEASE_META"
[[ "$VERSION" =~ ^[A-Za-z0-9._-]+$ ]] || fail "Version illisible dans RELEASE : « $VERSION »."
info "$VERSION"

if [ "$ENV_NAME" = "prod" ]; then
  [[ "$VERSION" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail "En production, seule une version étiquetée vX.Y.Z est acceptée (reçu : $VERSION)."
  printf '\nInstaller %s en PRODUCTION ? Tapez « OUI %s » pour confirmer : ' "$VERSION" "$VERSION"
  read -r CONFIRM
  [ "$CONFIRM" = "OUI $VERSION" ] || fail "Confirmation refusée : rien n'a été modifié."
fi

[ ! -e "$APP_DIR/releases/$VERSION" ] || fail "La version $VERSION est déjà installée." "Pour y revenir : bash rollback-release.sh $ENV_NAME. Pour la réinstaller : reconstruire une nouvelle version."

# --- 3. Décompression -----------------------------------------------------------

step "3/9 Décompression dans releases/$VERSION"
TMP_DIR="$APP_DIR/releases/.tmp-$VERSION"
rm -rf "$TMP_DIR"
mkdir -p "$TMP_DIR" || fail "Impossible de créer $TMP_DIR."
tar -xzf "$ARCHIVE" -C "$TMP_DIR" || { rm -rf "$TMP_DIR"; fail "Décompression impossible." "Vérifier l'espace disque (cPanel → Utilisation du disque)."; }
[ -f "$TMP_DIR/artisan" ] && [ -f "$TMP_DIR/vendor/autoload.php" ] || { rm -rf "$TMP_DIR"; fail "Archive incomplète (artisan ou vendor/ absent)."; }

# --- 4. Fichiers partagés (.env, storage) --------------------------------------

step "4/9 Fichiers partagés (.env, storage)"
if [ ! -d "$APP_DIR/shared/storage" ]; then
  cp -R "$TMP_DIR/storage" "$APP_DIR/shared/storage" || fail "Impossible de créer shared/storage."
  info "shared/storage créé (structure vide)"
fi
mkdir -p "$APP_DIR/shared/storage/app/private" "$APP_DIR/shared/storage/app/public" \
  "$APP_DIR/shared/storage/framework/cache/data" "$APP_DIR/shared/storage/framework/sessions" \
  "$APP_DIR/shared/storage/framework/views" "$APP_DIR/shared/storage/logs" || fail "Impossible de compléter shared/storage."

if [ ! -f "$APP_DIR/shared/.env" ]; then
  cp "$TMP_DIR/.env.example" "$APP_DIR/shared/.env" && chmod 600 "$APP_DIR/shared/.env" || fail "Impossible de créer shared/.env."
  rm -rf "$TMP_DIR"
  printf '\nPREMIÈRE INSTALLATION : %s/shared/.env a été créé à partir du modèle.\n' "$APP_DIR"
  printf 'Complétez-le (base, URL, e-mail, clés : docs/installation_recette.md, étape 5),\n'
  printf 'créez ~/.my.%s.cnf (étape 6),\n' "$ENV_NAME"
  printf 'puis relancez exactement la même commande. Rien d'"'"'autre n'"'"'a été modifié.\n'
  exit 0
fi

# Identifiants de la sauvegarde : vérifiés avant toute mise en place.
CNF="$HOME/.my.$ENV_NAME.cnf"
[ -f "$CNF" ] || { rm -rf "$TMP_DIR"; fail "$CNF introuvable." "Créer ce fichier (docs/installation_recette.md, étape 6), puis relancer."; }
DB_NAME="$(env_value DB_DATABASE)" && [ -n "$DB_NAME" ] || { rm -rf "$TMP_DIR"; fail "DB_DATABASE absent ou vide dans shared/.env." "Compléter $APP_DIR/shared/.env, puis relancer."; }

rm -rf "$TMP_DIR/storage"
ln -s "$APP_DIR/shared/storage" "$TMP_DIR/storage" || { rm -rf "$TMP_DIR"; fail "Lien storage impossible."; }
ln -s "$APP_DIR/shared/.env" "$TMP_DIR/.env" || { rm -rf "$TMP_DIR"; fail "Lien .env impossible."; }
RELEASE_DIR="$APP_DIR/releases/$VERSION"
mv "$TMP_DIR" "$RELEASE_DIR" || { rm -rf "$TMP_DIR"; RELEASE_DIR=""; fail "Impossible de placer la version dans releases/$VERSION."; }
info "Liens .env et storage/ vers shared/ créés"

# --- 5. Sauvegarde de la base ---------------------------------------------------

step "5/9 Sauvegarde de la base"
STAMP="$(date +%Y%m%d_%H%M%S)"
DUMP="$HOME/backups/${ENV_NAME}_${STAMP}_avant_${VERSION}.sql"
mysqldump --defaults-extra-file="$CNF" --single-transaction --no-tablespaces "$DB_NAME" > "$DUMP" 2> "$DUMP.err" \
  || fail "Sauvegarde impossible : $(cat "$DUMP.err")" "Vérifier $CNF (utilisateur, mot de passe) et la base $DB_NAME."
[ -s "$DUMP" ] || fail "Sauvegarde vide : $DUMP." "Vérifier les droits de l'utilisateur sur la base."
rm -f "$DUMP.err"
tar -czf "$DUMP.tar.gz" -C "$HOME/backups" "$(basename "$DUMP")" && rm -f "$DUMP" || fail "Compression de la sauvegarde impossible."
info "$DUMP.tar.gz"

# --- 6. Maintenance -------------------------------------------------------------

step "6/9 Mise en maintenance"
PREVIOUS=""
if [ -L "$APP_DIR/current" ]; then
  PREVIOUS="$(readlink "$APP_DIR/current")"
  artisan "$APP_DIR/current" down --retry=60 || fail "Mise en maintenance impossible." "Vérifier la version en service : $PHP_BIN $APP_DIR/current/artisan about"
  MAINTENANCE_ON=1
  info "Version en service mise en maintenance : $PREVIOUS"
elif [ -e "$APP_DIR/current" ]; then
  fail "$APP_DIR/current existe mais n'est pas un lien." "Le renommer (mv current current.ancien), puis relancer."
else
  info "Première version : rien à mettre en maintenance"
fi

# --- 7. Base de données et optimisation ----------------------------------------

step "7/9 Migrations, permissions, optimisation"
artisan "$RELEASE_DIR" migrate --force \
  || fail "Les migrations ont échoué." "Lire l'erreur ci-dessus. Si la base a été partiellement modifiée, la restaurer depuis $DUMP.tar.gz avant de remettre en ligne."
artisan "$RELEASE_DIR" aviator:sync-permissions || fail "Synchronisation des permissions impossible."
if [ -z "$PREVIOUS" ]; then
  artisan "$RELEASE_DIR" db:seed --force || fail "Données de référence (agences) impossibles à créer."
fi
artisan "$RELEASE_DIR" optimize || fail "Optimisation impossible (config, routes, vues)." "Vérifier shared/.env (syntaxe), puis relancer."

# --- 8. Bascule et remise en ligne ---------------------------------------------

step "8/9 Bascule vers $VERSION et remise en ligne"
ln -sfn "releases/$VERSION" "$APP_DIR/current.new" || fail "Lien current.new impossible."
mv -Tf "$APP_DIR/current.new" "$APP_DIR/current" || fail "Bascule du lien current impossible."
SWITCHED=1
printf '%s %s\n' "$(date +%Y-%m-%dT%H:%M:%S)" "$VERSION" >> "$APP_DIR/releases/.history"
artisan "$APP_DIR/current" up || fail "Remise en ligne impossible." "Relancer : $PHP_BIN $APP_DIR/current/artisan up"
MAINTENANCE_ON=0
info "En service : $VERSION (racine web : $APP_DIR/current/public)"

# --- 9. Contrôle et ménage ------------------------------------------------------

step "9/9 Contrôle de l'environnement et ménage"
artisan "$APP_DIR/current" aviator:check-environment
CHECK_STATUS=$?

# Garder les KEEP_RELEASES versions les plus récemment installées, d'après l'historique
# (ordre exact des installations), et toujours celle en service.
CURRENT_TARGET="$(readlink "$APP_DIR/current")"
HISTORY_VERSIONS=()
while read -r _ version _; do
  [ -n "$version" ] && HISTORY_VERSIONS+=("$version")
done < "$APP_DIR/releases/.history"

KEPT=()
for ((i = ${#HISTORY_VERSIONS[@]} - 1; i >= 0 && ${#KEPT[@]} < KEEP_RELEASES; i--)); do
  version="${HISTORY_VERSIONS[$i]}"
  already=0
  for kept in "${KEPT[@]}"; do [ "$kept" = "$version" ] && already=1; done
  [ "$already" -eq 0 ] && [ -d "$APP_DIR/releases/$version" ] && KEPT+=("$version")
done

for dir in "$APP_DIR"/releases/*/; do
  [ -d "$dir" ] || continue
  version="$(basename "$dir")"
  keep=0
  for kept in "${KEPT[@]}"; do [ "$kept" = "$version" ] && keep=1; done
  [ "releases/$version" = "$CURRENT_TARGET" ] && keep=1
  if [ "$keep" -eq 0 ]; then
    rm -rf "${dir%/}" && info "Ancienne version supprimée : $version"
  fi
done

printf '\nInstallation de %s terminée sur %s.\n' "$VERSION" "$ENV_NAME"
if [ "$CHECK_STATUS" -ne 0 ]; then
  printf 'ATTENTION : aviator:check-environment signale au moins une erreur (voir le tableau ci-dessus).\n'
fi
printf 'Retour arrière possible : bash rollback-release.sh %s (les migrations ne sont pas annulées).\n' "$ENV_NAME"
