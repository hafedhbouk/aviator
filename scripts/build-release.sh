#!/usr/bin/env bash
# Construit l'archive de version à déployer (D25), sur le poste, dans Git Bash.
#
# Usage : scripts/build-release.sh <vX.Y.Z | branche>
#   - production : une étiquette vX.Y.Z ;
#   - recette    : une branche (exemple : phase/03-parametrage).
#
# Variable facultative : AVIATOR_BUILD_DIR, dossier de la copie de travail et de
# l'archive (par défaut /d/aviator-builds, à exclure de l'antivirus du poste). Le
# dossier temporaire du système n'est pas utilisé : il n'est pas exclu de l'antivirus.
#
# Étapes : copie de travail Git de la référence, composer check (contrôles de la CI),
# dépendances de production (composer install --no-dev, plateforme PHP 8.3),
# npm run build, nettoyage, archive aviator-<version>.tar.gz et son empreinte
# SHA-256 dans AVIATOR_BUILD_DIR. Composer et npm passent par leur cache. La durée de
# chaque étape est affichée. Le dossier de travail du dépôt n'est pas modifié.
#
# Aucune connexion au serveur : l'archive est ensuite envoyée par FTPS ou par le
# gestionnaire de fichiers de cPanel, puis installée par install-release.sh.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=scripts/lib/release-files.sh
source "$ROOT/scripts/lib/release-files.sh"

die() {
  printf '\nERREUR : %s\n' "$1" >&2
  exit 1
}

# Chronométrage : chaque étape affiche sa durée, le bilan est repris à la fin.
TIMINGS=()
STEP_LABEL=""
STEP_STARTED=0

begin_step() {
  STEP_LABEL="$1"
  STEP_STARTED=$SECONDS
  echo "== $STEP_LABEL"
}

end_step() {
  local elapsed=$((SECONDS - STEP_STARTED))
  TIMINGS+=("$(printf '%5d s  %s' "$elapsed" "$STEP_LABEL")")
  echo "   durée : ${elapsed} s"
}

REF="${1:-}"
[ -n "$REF" ] || die "Usage : scripts/build-release.sh <vX.Y.Z | branche>"

cd "$ROOT"
git rev-parse --verify --quiet "${REF}^{commit}" > /dev/null || die "Référence Git inconnue : $REF"

if [[ "$REF" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  git show-ref --verify --quiet "refs/tags/$REF" || die "L'étiquette $REF n'existe pas (production : étiquette obligatoire)."
  VERSION="$REF"
else
  VERSION="${REF//\//-}-$(git rev-parse --short "$REF")"
fi

COMMIT="$(git rev-parse "$REF")"
[ -f "$ROOT/.env.testing" ] || die ".env.testing est nécessaire à composer check (base aviator_test)."

BUILD_DIR="${AVIATOR_BUILD_DIR:-/d/aviator-builds}"
mkdir -p "$BUILD_DIR" || die "Impossible de créer $BUILD_DIR (variable AVIATOR_BUILD_DIR)."
BUILD_DIR="$(cd "$BUILD_DIR" && pwd)"
case "$BUILD_DIR/" in
  "$ROOT"/*) die "AVIATOR_BUILD_DIR ne doit pas être dans le dépôt ($BUILD_DIR)." ;;
esac

ARCHIVE="aviator-$VERSION.tar.gz"
[ ! -e "$BUILD_DIR/$ARCHIVE" ] || die "$BUILD_DIR/$ARCHIVE existe déjà : supprimez-le pour reconstruire cette version."

WORK="$(mktemp -d "$BUILD_DIR/work.XXXXXX")"
cleanup() {
  git -C "$ROOT" worktree remove --force "$WORK/src" > /dev/null 2>&1 || true
  rm -rf "$WORK"
}
trap cleanup EXIT

TOTAL_STARTED=$SECONDS
echo "Construction de $VERSION dans $BUILD_DIR"
echo "Cache Composer : $(composer config cache-dir 2> /dev/null || echo inconnu)"
echo "Cache npm      : $(npm config get cache 2> /dev/null || echo inconnu)"

begin_step "1/8 Copie de travail de $REF"
git worktree add --detach "$WORK/src" "$REF" > /dev/null
cp "$ROOT/.env.testing" "$WORK/src/.env.testing"
end_step

begin_step "2/8 Dépendances de développement (composer install)"
(cd "$WORK/src" && composer install --no-interaction --no-progress 2>&1) | tee "$WORK/composer-dev.log" | tail -n 3
echo "   paquets téléchargés (absents du cache) : $(grep -c -- '- Downloading ' "$WORK/composer-dev.log" || true)"
end_step

begin_step "3/8 Dépendances front (npm ci, cache d'abord)"
(cd "$WORK/src" && npm ci --prefer-offline --no-audit --no-fund) | tail -n 2
end_step

begin_step "4/8 Contrôles de la CI (composer check)"
# Les tests utilisent .env.testing (base aviator_test). Une variable exportée par le
# shell (par exemple après avoir chargé .env dans le terminal) prime sur ce fichier :
# APP_ENV et DB_* feraient tourner les tests sur une autre base, SESSION_PATH est
# déformé par Git Bash. Toutes les variables nommées dans .env.example sont donc
# retirées pour cette étape (incident du 19/11/2026).
UNSET_ARGS=()
while IFS= read -r key; do
  UNSET_ARGS+=(-u "$key")
done < <(grep -oE '^[A-Z][A-Z0-9_]*' "$WORK/src/.env.example" | sort -u)
(cd "$WORK/src" && env "${UNSET_ARGS[@]}" composer check)
end_step

begin_step "5/8 Export de la référence (git archive)"
mkdir -p "$WORK/stage"
git archive "$REF" | tar -x -C "$WORK/stage"
end_step

begin_step "6/8 Dépendances de production (composer install --no-dev)"
(cd "$WORK/stage" && composer install --no-dev --optimize-autoloader --no-interaction --no-progress 2>&1) | tee "$WORK/composer-prod.log" | tail -n 3
echo "   paquets téléchargés (absents du cache) : $(grep -c -- '- Downloading ' "$WORK/composer-prod.log" || true)"
cp -R "$WORK/src/public/build" "$WORK/stage/public/build"
printf 'version=%s\ncommit=%s\nbuilt_at=%s\n' "$VERSION" "$COMMIT" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$WORK/stage/RELEASE"
end_step

begin_step "7/8 Nettoyage et vérification"
prune_release_tree "$WORK/stage"
verify_release_tree "$WORK/stage" || die "L'arborescence de l'archive n'est pas conforme (voir ci-dessus)."
end_step

begin_step "8/8 Archive et empreinte"
(cd "$WORK/stage" && tar -czf "$WORK/$ARCHIVE" .)
verify_release_archive "$WORK" "$ARCHIVE" || die "L'archive contient des fichiers interdits (voir ci-dessus)."
mv "$WORK/$ARCHIVE" "$BUILD_DIR/$ARCHIVE"
(cd "$BUILD_DIR" && sha256sum "$ARCHIVE" > "$ARCHIVE.sha256")
end_step

echo
echo "Durée par étape :"
printf '%s\n' "${TIMINGS[@]}"
echo "Total : $((SECONDS - TOTAL_STARTED)) s"
echo
echo "Archive prête : $BUILD_DIR/$ARCHIVE ($(du -h "$BUILD_DIR/$ARCHIVE" | cut -f1))"
echo "Empreinte     : $BUILD_DIR/$ARCHIVE.sha256"
cat "$BUILD_DIR/$ARCHIVE.sha256"
echo
echo "Suite : envoyer les deux fichiers dans ~/deploiement sur le serveur (FTPS ou gestionnaire"
echo "de fichiers), puis, dans le terminal web de cPanel : voir docs/installation_hebergement_oxahost.md, annexe A."
