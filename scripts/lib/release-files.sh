#!/usr/bin/env bash
# Fonctions communes de l'archive de version (D25), utilisées par build-release.sh
# et par les tests (ReleaseScriptsTest). À charger avec « source ».
#
# Les fonctions travaillent dans le dossier donné, par chemins relatifs : sous Windows,
# GNU tar prendrait le « C: » d'un chemin absolu pour un nom de machine distante.

# Fichiers et dossiers de développement retirés de l'archive.
RELEASE_EXCLUDED_PATHS=(
  .git .github .qodo node_modules tests docs
  .env.testing .env.testing.example .editorconfig .npmrc
  phpunit.xml phpstan.neon pint.json package.json package-lock.json vite.config.js
  CLAUDE.md AGENTS.md README.md LISEZ-MOI.md PROMPT_MAITRE_AVIATOR_LARAVEL.md
  resources/js resources/css
)

# Scripts conservés dans l'archive (exécutés sur le serveur).
RELEASE_KEPT_SCRIPTS=(install-release.sh rollback-release.sh)

# Retire de l'arborescence tout ce qui ne doit pas partir sur le serveur.
prune_release_tree() {
  local dir="$1"
  (
    cd "$dir" || exit 1

    local path
    for path in "${RELEASE_EXCLUDED_PATHS[@]}"; do
      rm -rf -- "./$path"
    done

    # Aucun fichier d'environnement, sauf le modèle .env.example (sans secret).
    find . -maxdepth 1 -name '.env*' ! -name '.env.example' -exec rm -rf -- {} +

    # Scripts : seuls ceux du serveur restent.
    if [ -d scripts ]; then
      local script keep kept
      for script in scripts/* scripts/.[!.]*; do
        [ -e "$script" ] || continue
        keep=0
        for kept in "${RELEASE_KEPT_SCRIPTS[@]}"; do
          [ "$script" = "scripts/$kept" ] && keep=1
        done
        [ "$keep" -eq 1 ] || rm -rf -- "$script"
      done
    fi

    # storage/ : la structure vide seulement (dossiers et .gitignore).
    if [ -d storage ]; then
      find storage -type f ! -name '.gitignore' -exec rm -f -- {} +
    fi
  )
}

# Vérifie l'arborescence avant l'archivage. Affiche chaque problème, renvoie 1 s'il y en a.
verify_release_tree() {
  local dir="$1"
  (
    cd "$dir" || exit 1
    local problems=0 path

    for path in .env .git node_modules tests .github; do
      if [ -e "$path" ]; then
        echo "Interdit dans l'archive : $path" >&2
        problems=1
      fi
    done

    if [ -d storage ] && [ -n "$(find storage -type f ! -name '.gitignore' -print -quit)" ]; then
      echo "storage/ doit être vide (structure seulement)" >&2
      problems=1
    fi

    for path in artisan vendor/autoload.php public/index.php public/build/manifest.json RELEASE .env.example \
      scripts/install-release.sh scripts/rollback-release.sh; do
      if [ ! -e "$path" ]; then
        echo "Manquant dans l'archive : $path" >&2
        problems=1
      fi
    done

    # Aucune dépendance de développement (composer install --no-dev).
    for path in vendor/phpunit vendor/pestphp vendor/larastan vendor/laravel/pint; do
      if [ -e "$path" ]; then
        echo "Dépendance de développement dans l'archive : $path" >&2
        problems=1
      fi
    done

    exit "$problems"
  )
}

# Vérifie le contenu d'une archive déjà construite. Renvoie 1 si une entrée est interdite.
# $1 : dossier de l'archive ; $2 : nom du fichier (relatif à ce dossier).
verify_release_archive() {
  local dir="$1" name="$2"
  (
    cd "$dir" || exit 1
    local listing
    listing="$(tar -tzf "$name")" || { echo "Archive illisible : $name" >&2; exit 1; }

    local problems=0 entry
    while IFS= read -r entry; do
      entry="${entry#./}"
      case "$entry" in
        .env | .git | .git/* | tests | tests/* | */tests/* | node_modules | node_modules/* | */node_modules/*)
          # Les dossiers « tests » internes aux paquets vendor sont tolérés : seul tests/ racine est interdit.
          case "$entry" in
            vendor/*) ;;
            *) echo "Entrée interdite dans l'archive : $entry" >&2; problems=1 ;;
          esac
          ;;
        storage/*)
          case "$entry" in
            */ | */.gitignore) ;;
            *) echo "Fichier dans storage/ : $entry" >&2; problems=1 ;;
          esac
          ;;
      esac
    done <<< "$listing"

    exit "$problems"
  )
}
