#!/usr/bin/env bash
# bin/release.sh — bump version, build language branch, tag and push release.
#
# Usage:
#   bin/release.sh patch            # 0.2.1 → 0.2.2
#   bin/release.sh minor            # 0.2.1 → 0.3.0
#   bin/release.sh major            # 0.2.1 → 1.0.0
#   bin/release.sh 1.2.3            # explicit version
#
# Language pack source (optional):
#   LANG_SRC=/path/to/languages/plugins   bin/release.sh patch
#
# Defaults to wp-content/languages/plugins/ two levels up from the plugin dir
# (i.e. the Studio site that hosts the development copy of the plugin).

set -euo pipefail

# ── Paths ────────────────────────────────────────────────────────────────────

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PLUGIN_FILE="$PLUGIN_DIR/axellcore.php"
README_TXT="$PLUGIN_DIR/readme.txt"

# Default language source: ../../languages/plugins relative to plugin root
# e.g. ~/Studio/axell/wp-content/languages/plugins
DEFAULT_LANG_SRC="$(cd "$PLUGIN_DIR/../../languages/plugins" 2>/dev/null && pwd || true)"
LANG_SRC="${LANG_SRC:-$DEFAULT_LANG_SRC}"

# ── Helpers ──────────────────────────────────────────────────────────────────

die()  { echo "error: $*" >&2; exit 1; }
info() { echo "  → $*"; }

require_cmd() { command -v "$1" &>/dev/null || die "$1 is required"; }
require_cmd git
require_cmd msgfmt
require_cmd zip
require_cmd node   # for grunt

# ── Parse version argument ───────────────────────────────────────────────────

[[ $# -ge 1 ]] || die "usage: bin/release.sh patch|minor|major|<version>"

BUMP="$1"

CURRENT=$(grep -m1 "Version:" "$PLUGIN_FILE" | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
[[ "$CURRENT" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "could not parse current version from plugin header: $CURRENT"

IFS='.' read -r MAJ MIN PAT <<< "$CURRENT"

case "$BUMP" in
  major)   NEW_VERSION="$((MAJ+1)).0.0" ;;
  minor)   NEW_VERSION="${MAJ}.$((MIN+1)).0" ;;
  patch)   NEW_VERSION="${MAJ}.${MIN}.$((PAT+1))" ;;
  [0-9]*.[0-9]*.[0-9]*)
           NEW_VERSION="$BUMP"
           [[ "$NEW_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "invalid explicit version: $NEW_VERSION"
           ;;
  *)       die "first argument must be patch, minor, major, or explicit semver (got: $BUMP)" ;;
esac

echo ""
echo "axellcore $CURRENT → $NEW_VERSION"
echo ""

# ── Sanity checks ────────────────────────────────────────────────────────────

cd "$PLUGIN_DIR"

[[ -z "$(git status --porcelain)" ]] || die "working tree is dirty — commit or stash changes first"

git fetch origin --quiet

AHEAD=$(git rev-list "origin/$(git rev-parse --abbrev-ref HEAD)..HEAD" --count 2>/dev/null || echo 0)
[[ "$AHEAD" -eq 0 ]] || die "local branch is ahead of origin — push first"

git ls-remote --exit-code origin "refs/tags/${NEW_VERSION}" &>/dev/null \
  && die "tag ${NEW_VERSION} already exists on remote"

# ── Bump version in source files ─────────────────────────────────────────────

info "bumping version in axellcore.php and readme.txt"

# Plugin header
sed -i '' "s/ \* Version:.*/ * Version:           ${NEW_VERSION}/" "$PLUGIN_FILE"
# AXELLCORE_VERSION constant
sed -i '' "s/define( 'AXELLCORE_VERSION', '[^']*' )/define( 'AXELLCORE_VERSION', '${NEW_VERSION}' )/" "$PLUGIN_FILE"
# readme.txt stable tag
sed -i '' "s/^Stable tag:.*/Stable tag: ${NEW_VERSION}/" "$README_TXT"

# Prepend changelog entry (only if not already present)
if ! grep -q "^= ${NEW_VERSION} =" "$README_TXT"; then
  TODAY=$(date -u +%Y-%m-%d)
  sed -i '' "s/^== Changelog ==$/== Changelog ==\n\n= ${NEW_VERSION} =\n* Release ${NEW_VERSION} (${TODAY})./" "$README_TXT"
fi

# ── Regenerate README.md ──────────────────────────────────────────────────────

info "regenerating README.md via grunt"
npm run readme --silent 2>/dev/null

# ── Build language branch ─────────────────────────────────────────────────────

LANG_BRANCH="language/${NEW_VERSION}"
LANG_COMMIT_SHA=""

if [[ -d "$LANG_SRC" ]]; then
  PO_FILES=( "$LANG_SRC"/axellcore-*.po )
  if [[ -f "${PO_FILES[0]}" ]]; then
    info "building language branch $LANG_BRANCH from $LANG_SRC"

    LANG_WORK=$(mktemp -d)
    trap 'rm -rf "$LANG_WORK"' EXIT

    for PO in "${PO_FILES[@]}"; do
      [[ -f "$PO" ]] || continue
      LOCALE=$(basename "$PO" .po | sed 's/^axellcore-//')
      cp "$PO" "$LANG_WORK/axellcore-${LOCALE}.po"
      msgfmt "$PO" -o "$LANG_WORK/axellcore-${LOCALE}.mo"
      info "compiled $LOCALE"
    done

    # Build orphan branch in an isolated worktree clone
    LANG_REPO=$(mktemp -d)
    trap 'rm -rf "$LANG_WORK" "$LANG_REPO"' EXIT

    git -C "$LANG_REPO" init --quiet
    git -C "$LANG_REPO" remote add origin "$(git remote get-url origin)"

    # Start orphan branch
    git -C "$LANG_REPO" checkout --orphan "$LANG_BRANCH" --quiet
    git -C "$LANG_REPO" rm -rf . --quiet 2>/dev/null || true

    cp "$LANG_WORK"/axellcore-*.po "$LANG_WORK"/axellcore-*.mo "$LANG_REPO/"

    git -C "$LANG_REPO" add .
    git -C "$LANG_REPO" \
      -c user.name="$(git config user.name)" \
      -c user.email="$(git config user.email)" \
      commit --quiet -m "i18n: language packs for ${NEW_VERSION}"

    info "pushing $LANG_BRANCH"
    git -C "$LANG_REPO" push origin "$LANG_BRANCH" --force --quiet

    LANG_COMMIT_SHA=$(git -C "$LANG_REPO" rev-parse HEAD)
    info "language branch pushed: $LANG_COMMIT_SHA"
  else
    info "no .po files found in $LANG_SRC — skipping language branch"
  fi
else
  info "LANG_SRC not found ($LANG_SRC) — skipping language branch"
fi

# ── Commit, tag, push ─────────────────────────────────────────────────────────

info "committing version bump"
git add axellcore.php readme.txt README.md
git commit --quiet -m "chore: release ${NEW_VERSION}"

info "tagging ${NEW_VERSION}"
git tag "${NEW_VERSION}"

info "pushing main and tag"
git push origin main --quiet
git push origin "${NEW_VERSION}" --quiet

echo ""
echo "Released ${NEW_VERSION}."
[[ -n "$LANG_COMMIT_SHA" ]] && echo "Language branch $LANG_BRANCH pushed — release workflow will upload packs."
echo "https://github.com/$(git remote get-url origin | sed 's/.*github\.com[:/]//' | sed 's/\.git$//')/releases/tag/${NEW_VERSION}"
