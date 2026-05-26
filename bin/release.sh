#!/usr/bin/env bash
# bin/release.sh — bump version or publish language branch.
#
# Usage:
#   bin/release.sh patch            # 0.2.1 → 0.2.2  bump + pot + commit + tag + push
#   bin/release.sh minor            # 0.2.1 → 0.3.0
#   bin/release.sh major            # 0.2.1 → 1.0.0
#   bin/release.sh 1.2.3            # explicit version
#
#   bin/release.sh language         # build language/<current-version> branch from LANG_SRC
#                                   # run this after translating, independently of the release
#
# Language pack source (language subcommand):
#   LANG_SRC=/path/to/languages/plugins   bin/release.sh language
#
# Defaults to wp-content/languages/plugins/ two levels up from the plugin dir
# (i.e. the Studio site that hosts the development copy of the plugin).

set -euo pipefail

# ── Paths ────────────────────────────────────────────────────────────────────

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PLUGIN_FILE="$PLUGIN_DIR/axellcore.php"
README_TXT="$PLUGIN_DIR/readme.txt"
POT_FILE="$PLUGIN_DIR/languages/axellcore.pot"

DEFAULT_LANG_SRC="$(cd "$PLUGIN_DIR/../../languages/plugins" 2>/dev/null && pwd || true)"
LANG_SRC="${LANG_SRC:-$DEFAULT_LANG_SRC}"

# ── Helpers ──────────────────────────────────────────────────────────────────

die()  { echo "error: $*" >&2; exit 1; }
info() { echo "  → $*"; }

require_cmd() { command -v "$1" &>/dev/null || die "$1 is required"; }

current_version() {
	grep -m1 "Version:" "$PLUGIN_FILE" \
		| sed 's/.*Version:[[:space:]]*//' \
		| tr -d '[:space:]'
}

# ── Dispatch ─────────────────────────────────────────────────────────────────

[[ $# -ge 1 ]] || die "usage: bin/release.sh patch|minor|major|<version>|language"

CMD="$1"

# ─────────────────────────────────────────────────────────────────────────────
# SUBCOMMAND: language
# Build an orphan language/<version> branch from LANG_SRC and push it.
# The CI workflow compiles .po → .mo and uploads the zip to the GitHub Release.
# ─────────────────────────────────────────────────────────────────────────────
if [[ "$CMD" == "language" ]]; then
	require_cmd git
	require_cmd msgfmt
	require_cmd msgmerge

	CURRENT=$(current_version)
	[[ "$CURRENT" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] \
		|| die "could not parse current version: $CURRENT"

	LANG_BRANCH="language/${CURRENT}"

	echo ""
	echo "Building language branch $LANG_BRANCH"
	echo ""

	[[ -d "$LANG_SRC" ]] \
		|| die "LANG_SRC not found: $LANG_SRC — set LANG_SRC= to override"

	PO_FILES=( "$LANG_SRC"/axellcore-*.po )
	[[ -f "${PO_FILES[0]}" ]] \
		|| die "no axellcore-*.po files found in $LANG_SRC"

	# ── Merge POT into each PO and validate ──────────────────────────────────

	MISSING_ALL=""

	for PO in "${PO_FILES[@]}"; do
		[[ -f "$PO" ]] || continue
		LOCALE=$(basename "$PO" .po | sed 's/^axellcore-//')

		info "merging pot into $LOCALE"
		msgmerge --update --backup=none --quiet "$PO" "$POT_FILE"

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
    if any(l == 'msgid ""' for l in lines):
        continue
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
		[[ -n "$MISSING" ]] && MISSING_ALL+="\n[$LOCALE]\n$MISSING"
	done

	if [[ -n "$MISSING_ALL" ]]; then
		echo "" >&2
		echo "error: untranslated strings — translate before publishing:" >&2
		echo -e "$MISSING_ALL" >&2
		echo "" >&2
		exit 1
	fi

	# ── Build orphan branch ───────────────────────────────────────────────────

	LANG_WORK=$(mktemp -d)
	LANG_REPO=$(mktemp -d)
	trap 'rm -rf "$LANG_WORK" "$LANG_REPO"' EXIT

	for PO in "${PO_FILES[@]}"; do
		[[ -f "$PO" ]] || continue
		LOCALE=$(basename "$PO" .po | sed 's/^axellcore-//')
		DEST_PO="$LANG_WORK/axellcore-${LOCALE}.po"
		cp "$PO" "$DEST_PO"
		sed -i '' "s/^\"Project-Id-Version:.*\$/\"Project-Id-Version: axellcore ${CURRENT}\\\\n\"/" "$DEST_PO"
		TODAY_ISO=$(date -u +"%Y-%m-%dT%H:%M:%S+00:00")
		sed -i '' "s/^\"PO-Revision-Date:.*\$/\"PO-Revision-Date: ${TODAY_ISO}\\\\n\"/" "$DEST_PO"
		info "prepared $LOCALE"
	done

	git -C "$LANG_REPO" init --quiet
	git -C "$LANG_REPO" remote add origin "$(git -C "$PLUGIN_DIR" remote get-url origin)"
	git -C "$LANG_REPO" checkout --orphan "$LANG_BRANCH" --quiet
	git -C "$LANG_REPO" rm -rf . --quiet 2>/dev/null || true

	cp "$LANG_WORK"/axellcore-*.po "$LANG_REPO/"

	git -C "$LANG_REPO" add .
	git -C "$LANG_REPO" \
		-c user.name="$(git -C "$PLUGIN_DIR" config user.name)" \
		-c user.email="$(git -C "$PLUGIN_DIR" config user.email)" \
		commit --quiet -m "i18n: pt_BR language pack for ${CURRENT}"

	info "pushing $LANG_BRANCH"
	git -C "$LANG_REPO" push origin "$LANG_BRANCH" --force --quiet

	LANG_SHA=$(git -C "$LANG_REPO" rev-parse HEAD)
	echo ""
	echo "Language branch $LANG_BRANCH pushed ($LANG_SHA)."

	# Dispatch the Language workflow directly — branch push from an isolated
	# repo does not reliably trigger GitHub Actions, so we trigger explicitly.
	if command -v gh &>/dev/null; then
		info "dispatching Language workflow for ${CURRENT}"
		gh workflow run language.yml \
			--repo "$(git -C "$PLUGIN_DIR" remote get-url origin | sed 's/.*github\.com[:\/]//' | sed 's/\.git$//')" \
			--field version="${CURRENT}"
		echo "Language workflow dispatched."
		REPO=$(git -C "$PLUGIN_DIR" remote get-url origin | sed 's/.*github\.com[:\/]//' | sed 's/\.git$//')
		echo "https://github.com/${REPO}/actions/workflows/language.yml"
	else
		echo "warning: gh CLI not found — trigger the Language workflow manually with version=${CURRENT}" >&2
	fi
	exit 0
fi

# ─────────────────────────────────────────────────────────────────────────────
# SUBCOMMAND: release (patch / minor / major / explicit version)
# Bump version, update POT, regenerate README, commit, tag, push.
# ─────────────────────────────────────────────────────────────────────────────

require_cmd git
require_cmd node
require_cmd wp

BUMP="$CMD"

CURRENT=$(current_version)
[[ "$CURRENT" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] \
	|| die "could not parse current version from plugin header: $CURRENT"

IFS='.' read -r MAJ MIN PAT <<< "$CURRENT"

case "$BUMP" in
	major)             NEW_VERSION="$((MAJ+1)).0.0" ;;
	minor)             NEW_VERSION="${MAJ}.$((MIN+1)).0" ;;
	patch)             NEW_VERSION="${MAJ}.${MIN}.$((PAT+1))" ;;
	[0-9]*.[0-9]*.[0-9]*)
		NEW_VERSION="$BUMP"
		[[ "$NEW_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] \
			|| die "invalid explicit version: $NEW_VERSION"
		;;
	*) die "first argument must be patch, minor, major, explicit semver, or 'language' (got: $BUMP)" ;;
esac

echo ""
echo "axellcore $CURRENT → $NEW_VERSION"
echo ""

cd "$PLUGIN_DIR"

git fetch origin --quiet

git ls-remote --exit-code origin "refs/tags/${NEW_VERSION}" &>/dev/null \
	&& die "tag ${NEW_VERSION} already exists on remote"

# ── Bump version ─────────────────────────────────────────────────────────────

info "bumping version in axellcore.php and readme.txt"

sed -i '' "s/ \* Version:.*/ * Version:           ${NEW_VERSION}/" "$PLUGIN_FILE"
sed -i '' "s/define( 'AXELLCORE_VERSION', '[^']*' )/define( 'AXELLCORE_VERSION', '${NEW_VERSION}' )/" "$PLUGIN_FILE"
sed -i '' "s/^Stable tag:.*/Stable tag: ${NEW_VERSION}/" "$README_TXT"

if ! grep -q "^= ${NEW_VERSION} =" "$README_TXT"; then
	TODAY=$(date -u +%Y-%m-%d)
	sed -i '' "s/^== Changelog ==$/== Changelog ==\n\n= ${NEW_VERSION} =\n* Release ${NEW_VERSION} (${TODAY})./" "$README_TXT"
fi

# ── Validate changelog entry ─────────────────────────────────────────────────

# Extract the bullet lines for this version (lines between "= X.Y.Z =" and the
# next heading or end-of-file) and check that at least one meaningful entry exists.
CHANGELOG_ENTRY=$(
	awk "/^= ${NEW_VERSION} =/{found=1; next} found && /^= /{exit} found{print}" "$README_TXT" \
		| grep -v '^[[:space:]]*$'
)

if [[ -z "$CHANGELOG_ENTRY" ]]; then
	die "Changelog for ${NEW_VERSION} is empty. Add release notes to readme.txt before releasing."
fi

PLACEHOLDER="* Release ${NEW_VERSION}"
if [[ "$CHANGELOG_ENTRY" == "${PLACEHOLDER}"* && $(echo "$CHANGELOG_ENTRY" | wc -l | tr -d ' ') -eq 1 ]]; then
	die "No changes added in current changelog for ${NEW_VERSION}.\n       Edit readme.txt and replace the placeholder before releasing."
fi

# ── Regenerate README.md ─────────────────────────────────────────────────────

info "regenerating README.md via grunt"
npm run readme --silent 2>/dev/null

# ── Update POT ───────────────────────────────────────────────────────────────

info "generating axellcore.pot via wp i18n make-pot"
wp i18n make-pot "$PLUGIN_DIR" "$POT_FILE" \
	--domain=axellcore \
	--exclude=lib,vendor,node_modules,tests \
	--quiet

# ── Commit, tag, push ────────────────────────────────────────────────────────

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
echo "Run './bin/release.sh language' after translating to publish language packs."
REPO=$(git remote get-url origin | sed 's/.*github\.com[:/]//' | sed 's/\.git$//')
echo "https://github.com/${REPO}/releases/tag/${NEW_VERSION}"