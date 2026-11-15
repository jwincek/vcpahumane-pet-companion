#!/usr/bin/env bash
#
# Build the plugin as partners install it, and optionally zip it.
#
# The repository carries tests and tooling a partner's site has no use for.
# This copies only what runs, then refuses to finish if anything else got in or
# anything needed is missing, so packaging is checked rather than hoped for.
# Adapted from ShelterKit Pets' bin/build-dist.sh.
#
# Usage:
#   bin/build-dist.sh          # build the tree into build/<slug>/
#   bin/build-dist.sh --zip    # also build/<slug>-<version>.zip
#
# The zip holds a single <slug>/ folder. WordPress installs into a folder named
# after the one inside the zip, so "Replace current with uploaded" updates the
# existing plugin instead of installing a second copy.
#
# Prints the version as the last line.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# The plugin's main file is the one with a "Plugin Name:" header. The checkout
# directory is not reliably the slug: CI clones into a folder named after the
# repository.
MAIN_FILE=""
for candidate in "$ROOT"/*.php; do
	[[ -f "$candidate" ]] || continue
	if head -c 8192 "$candidate" | grep -qE '^[[:space:]]*\*?[[:space:]]*Plugin Name:'; then
		MAIN_FILE="$candidate"
		break
	fi
done
if [[ -z "$MAIN_FILE" ]]; then
	echo "Error: no PHP file at the repo root carries a 'Plugin Name:' header." >&2
	exit 1
fi

SLUG="$(basename "$MAIN_FILE" .php)"
BUILD_ROOT="$ROOT/build"
DEST="$BUILD_ROOT/$SLUG"

MAKE_ZIP=0
for arg in "$@"; do
	case "$arg" in
		--zip) MAKE_ZIP=1 ;;
		*) echo "Unknown argument: $arg" >&2; exit 1 ;;
	esac
done

if [[ ! -f "$ROOT/.distignore" ]]; then
	echo "Error: no .distignore at the repo root; refusing to guess what to ship." >&2
	exit 1
fi

VERSION="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' "$MAIN_FILE" | head -1)"
if [[ -z "$VERSION" ]]; then
	echo "Error: could not read the Version header from $(basename "$MAIN_FILE")." >&2
	exit 1
fi

EXCLUDES=()
while IFS= read -r line || [[ -n "$line" ]]; do
	line="${line%"${line##*[![:space:]]}"}"
	[[ -z "$line" || "$line" == \#* ]] && continue
	EXCLUDES+=(--exclude="$line")
done < "$ROOT/.distignore"

rm -rf "$BUILD_ROOT"
mkdir -p "$DEST"
rsync -a "${EXCLUDES[@]}" "$ROOT/" "$DEST/"

# 1. Known development files.
LEAKED=()
for f in .git .github bin build vendor node_modules tests composer.json composer.lock \
         phpcs.xml.dist phpunit.xml.dist .phpunit.result.cache; do
	[[ -e "$DEST/$f" ]] && LEAKED+=("$f")
done
if (( ${#LEAKED[@]} )); then
	echo "Error: development files leaked into the build: ${LEAKED[*]}" >&2
	exit 1
fi

# 2. Anything not committed. Everything that ships is tracked, and unlike the
#    list above this catches files nobody has thought of yet.
if git -C "$ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
	TRACKED="$(mktemp)"
	trap 'rm -f "$TRACKED"' EXIT
	git -C "$ROOT" ls-files > "$TRACKED"

	UNTRACKED=()
	while IFS= read -r -d '' f; do
		rel="${f#"$DEST"/}"
		grep -Fxq "$rel" "$TRACKED" || UNTRACKED+=("$rel")
	done < <(find "$DEST" -type f -print0)
	if (( ${#UNTRACKED[@]} )); then
		echo "Error: ${#UNTRACKED[@]} untracked file(s) reached the build:" >&2
		printf '  %s\n' "${UNTRACKED[@]:0:10}" >&2
		echo "Add them to .distignore, or commit them if they are meant to ship." >&2
		exit 1
	fi
else
	echo "Warning: not a git work tree; skipping the untracked-file check." >&2
fi

# 3. The top level is an allowlist, so a new tracked file at the root has to
#    be a decision to ship it rather than an oversight.
ALLOWED_TOP=( "$SLUG.php" README.md CHANGELOG.md LICENSE assets blocks includes )
UNEXPECTED=()
while IFS= read -r -d '' entry; do
	name="$(basename "$entry")"
	allowed=0
	for permitted in "${ALLOWED_TOP[@]}"; do
		[[ "$name" == "$permitted" ]] && { allowed=1; break; }
	done
	(( allowed )) || UNEXPECTED+=("$name")
done < <(find "$DEST" -mindepth 1 -maxdepth 1 -print0)
if (( ${#UNEXPECTED[@]} )); then
	echo "Error: not on the build's top-level allowlist: ${UNEXPECTED[*]}" >&2
	echo "Add it to .distignore, or to ALLOWED_TOP in bin/build-dist.sh if it is meant to ship." >&2
	exit 1
fi

# 4. Every PHP file refuses to run when requested directly. Opened by URL, a
#    render.php without the guard dies on an undefined function and prints a
#    fatal error with the server's path.
UNGUARDED=()
while IFS= read -r -d '' f; do
	grep -qE "defined\( *'ABSPATH' *\)" "$f" || UNGUARDED+=("${f#"$DEST"/}")
done < <(find "$DEST" -type f -name '*.php' -print0)
if (( ${#UNGUARDED[@]} )); then
	echo "Error: ${#UNGUARDED[@]} PHP file(s) in the build have no direct-access guard:" >&2
	printf '  %s\n' "${UNGUARDED[@]}" >&2
	echo "Add  if ( ! defined( 'ABSPATH' ) ) { exit; }  after any declare line." >&2
	exit 1
fi

# 5. Nothing the plugin needs is missing.
MISSING=()
for f in "$SLUG.php" LICENSE assets/style.css includes/class-client.php includes/class-settings.php \
         includes/render-helpers.php blocks/pet-card/block.json blocks/pet-card/render.php \
         blocks/pet-card/edit.js blocks/pet-card/edit.asset.php blocks/featured-pets/block.json \
         blocks/featured-pets/render.php blocks/featured-pets/edit.js blocks/featured-pets/edit.asset.php; do
	[[ -e "$DEST/$f" ]] || MISSING+=("$f")
done
if (( ${#MISSING[@]} )); then
	echo "Error: required files missing from the build: ${MISSING[*]}" >&2
	exit 1
fi

echo "Built $DEST ($(find "$DEST" -type f | wc -l | tr -d ' ') files)"

if (( MAKE_ZIP )); then
	ZIP="$BUILD_ROOT/$SLUG-$VERSION.zip"
	( cd "$BUILD_ROOT" && zip -rq "$ZIP" "$SLUG" -x '*.DS_Store' )
	echo "Packaged $ZIP"
fi

echo "$VERSION"
