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
require_cmd msgmerge
require_cmd msgunfmt
require_cmd zip
require_cmd node   # for grunt
require_cmd wp     # for make-pot

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

git fetch origin --quiet

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

# ── Update POT and PO files ──────────────────────────────────────────────────

POT_FILE="$PLUGIN_DIR/languages/axellcore.pot"

info "generating axellcore.pot via wp i18n make-pot"
wp i18n make-pot "$PLUGIN_DIR" "$POT_FILE" \
  --domain=axellcore \
  --exclude=lib,vendor,node_modules,tests \
  --quiet

if [[ -d "$LANG_SRC" ]]; then
  MISSING_ALL=""

  for PO in "$LANG_SRC"/axellcore-*.po; do
    [[ -f "$PO" ]] || continue
    LOCALE=$(basename "$PO" .po | sed 's/^axellcore-//')

    info "merging $POT_FILE into $PO ($LOCALE)"
    msgmerge --update --backup=none --quiet "$PO" "$POT_FILE"

    # Check for untranslated non-header strings.
    # Skips: file header (empty msgid), plugin metadata comments
    # (Plugin Name, Description, Author, Author URI).
    # A fuzzy or empty msgstr on any remaining string aborts the release.
    MISSING=$(python3 - "$PO" <<'PYEOF'
import sys, re

SKIP_COMMENTS = {
    "Plugin Name of the plugin",
    "Description of the plugin",
    "Author of the plugin",
    "Author URI of the plugin",
    "Plugin URI of the plugin",
}

path = sys.argv[1]
blocks = open(path).read().strip().split("\n\n")
missing = []
for block in blocks:
    lines = block.strip().splitlines()
    # Skip file header (empty msgid)
    if any(l == 'msgid ""' for l in lines):
        continue
    # Skip plugin metadata strings (extracted-comments)
    comments = [l.lstrip('#. ').strip() for l in lines if l.startswith('#.')]
    if any(c in SKIP_COMMENTS for c in comments):
        continue
    is_fuzzy   = any(l.strip() == '#, fuzzy' for l in lines)
    msgid_val  = ' '.join(re.findall(r'^msgid\s+"(.*)"', '\n'.join(lines), re.M))
    msgstr_val = ' '.join(re.findall(r'^msgstr\s+"(.*)"', '\n'.join(lines), re.M))
    if msgid_val and (is_fuzzy or not msgstr_val.strip()):
        missing.append(msgid_val)
for m in missing:
    print(m)
PYEOF
)

    if [[ -n "$MISSING" ]]; then
      MISSING_ALL+="\n[$LOCALE]\n$MISSING"
    fi
  done

  if [[ -n "$MISSING_ALL" ]]; then
    echo ""
    echo "error: untranslated strings found — translate before releasing:" >&2
    echo -e "$MISSING_ALL" >&2
    echo "" >&2
    exit 1
  fi
fi

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

info "staging all changes"
git add -A

info "committing version bump"
git commit --quiet -m "chore: release ${NEW_VERSION}"

info "tagging ${NEW_VERSION}"
git tag "${NEW_VERSION}"

info "pushing main and tag"
BRANCH=$(git rev-parse --abbrev-ref HEAD)
if ! git push origin "$BRANCH" --quiet 2>/dev/null; then
  info "push rejected — retrying with --force"
  git push origin "$BRANCH" --force --quiet
fi
git push origin "${NEW_VERSION}" --quiet

echo ""
echo "Released ${NEW_VERSION}."
[[ -n "$LANG_COMMIT_SHA" ]] && echo "Language branch $LANG_BRANCH pushed — release workflow will upload packs."
echo "https://github.com/$(git remote get-url origin | sed 's/.*github\.com[:/]//' | sed 's/\.git$//')/releases/tag/${NEW_VERSION}"
