#!/usr/bin/env bash
# Construit l'archive de version à déployer (D25), sur le poste, dans Git Bash.
#
# Usage : scripts/build-release.sh <vX.Y.Z | branche>
#   - production : une étiquette vX.Y.Z ;
#   - recette    : une branche (exemple : phase/02-architecture-socle).
#
# Étapes : copie de travail Git temporaire de la référence, composer check (contrôles
# de la CI), dépendances de production (composer install --no-dev, plateforme PHP 8.3),
# npm run build, nettoyage, archive dist/aviator-<version>.tar.gz et son empreinte
# SHA-256. Le dossier de travail du poste n'est pas modifié.
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

WORK="$(mktemp -d)"
cleanup() {
  git -C "$ROOT" worktree remove --force "$WORK/src" > /dev/null 2>&1 || true
  rm -rf "$WORK"
}
trap cleanup EXIT

OUT="$ROOT/dist"
ARCHIVE="aviator-$VERSION.tar.gz"
mkdir -p "$OUT"
[ ! -e "$OUT/$ARCHIVE" ] || die "dist/$ARCHIVE existe déjà : supprimez-le pour reconstruire cette version."

echo "== 1/5 Copie de travail de $REF ($VERSION)"
git worktree add --detach "$WORK/src" "$REF" > /dev/null
cp "$ROOT/.env.testing" "$WORK/src/.env.testing"

echo "== 2/5 Contrôles de la CI (composer check)"
(
  cd "$WORK/src"
  composer install --no-interaction --no-progress
  npm ci --no-audit --no-fund
  composer check
)

echo "== 3/5 Dépendances de production et assets"
mkdir -p "$WORK/stage"
git archive "$REF" | tar -x -C "$WORK/stage"
(
  cd "$WORK/stage"
  composer install --no-dev --optimize-autoloader --no-interaction --no-progress
)
cp -R "$WORK/src/public/build" "$WORK/stage/public/build"
printf 'version=%s\ncommit=%s\nbuilt_at=%s\n' "$VERSION" "$COMMIT" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$WORK/stage/RELEASE"

echo "== 4/5 Nettoyage et vérification"
prune_release_tree "$WORK/stage"
verify_release_tree "$WORK/stage" || die "L'arborescence de l'archive n'est pas conforme (voir ci-dessus)."

echo "== 5/5 Archive et empreinte"
(cd "$WORK/stage" && tar -czf "$WORK/$ARCHIVE" .)
verify_release_archive "$WORK" "$ARCHIVE" || die "L'archive contient des fichiers interdits (voir ci-dessus)."
mv "$WORK/$ARCHIVE" "$OUT/$ARCHIVE"
(cd "$OUT" && sha256sum "$ARCHIVE" > "$ARCHIVE.sha256")

echo
echo "Archive prête : dist/$ARCHIVE ($(du -h "$OUT/$ARCHIVE" | cut -f1))"
echo "Empreinte     : dist/$ARCHIVE.sha256"
cat "$OUT/$ARCHIVE.sha256"
echo
echo "Suite : envoyer les deux fichiers dans ~/ sur le serveur (FTPS ou gestionnaire de fichiers),"
echo "puis, dans le terminal web de cPanel : voir docs/installation_hebergement_oxahost.md, annexe A."
