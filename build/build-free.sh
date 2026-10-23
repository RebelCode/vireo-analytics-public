#!/usr/bin/env bash
# Produces vireo-analytics.zip — Free build for wp.org submission.
#
# Layout: contains everything at the plugin root EXCEPT the pro/ directory,
# Pro-only templates, Pro-only JS/CSS, build tooling, and any local dev
# artefacts. The Free plugin is self-contained; it does not reference any
# Pro file and does not ship one.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIST="$ROOT/dist"

# Free must not duplicate or collide with Pro. See check-pro-collisions.sh.
if ! bash "$(dirname "${BASH_SOURCE[0]}")/check-pro-collisions.sh"; then
    exit 1
fi
# The browser-facing scripts are minified copies of assets/js/src/. A build
# with a stale copy would ship old behaviour under a fresh version, so it
# stops here rather than later in a bug report. See minify-js.sh.
if ! bash "$(dirname "${BASH_SOURCE[0]}")/minify-js.sh" --check; then
    exit 1
fi
# Default to whatever the source header says, so a build can't silently stamp a
# stale version onto current code. Override with VA_RELEASE_VERSION.
SOURCE_VERSION="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$(dirname "$0")/../vireo-analytics.php" | head -1)"
RELEASE_VERSION="${VA_RELEASE_VERSION:-${SOURCE_VERSION}}"
if [ -z "$RELEASE_VERSION" ]; then
	echo "Could not resolve a version from vireo-analytics.php" >&2
	exit 1
fi

rm -rf "$DIST/vireo-analytics" "$DIST/vireo-analytics.zip"
mkdir -p "$DIST/vireo-analytics"

# The `/`-anchored image excludes below drop stray working files from the plugin
# root. A screenshot left there while debugging is untracked, so nothing in the
# plugin can depend on it, but rsync would copy it straight into the
# wordpress.org download — 4.5MB of them nearly shipped in 0.4.1. The leading
# slash keeps the exclusion at the root so assets/ retains its own images, and
# `/--*` catches artifacts named after a mistyped CLI flag.
rsync -a \
    --exclude='pro/' \
    --exclude='build/' \
    --exclude='dist/' \
    --exclude='.git' \
    --exclude='.gitignore' \
    --exclude='.github/' \
    --exclude='.distignore' \
    --exclude='.wordpress-org/' \
    --exclude='.impeccable/' \
    --exclude='.secret-scan-allow' \
    --exclude='.wp-env.json' \
    --exclude='.claude/' \
    --exclude='*.log' \
    --exclude='.DS_Store' \
    --exclude='node_modules/' \
    --exclude='.phpunit.result.cache' \
    --exclude='composer.json' \
    --exclude='composer.lock' \
    --exclude='vendor/' \
    --exclude='phpcs.xml.dist' \
    --exclude='phpunit.xml.dist' \
    --exclude='tests/' \
    --exclude='docs/' \
    --exclude='*.md' \
    \
    --exclude='/*.png' \
    --exclude='/*.jpg' \
    --exclude='/*.jpeg' \
    --exclude='/*.gif' \
    --exclude='/*.webp' \
    --exclude='/--*' \
    \
    --exclude='templates/ai-report-email.php' \
    --exclude='templates/admin/ai-preview.php' \
    --exclude='templates/admin/pro-goals-edit.php' \
    --exclude='templates/admin/pro-goals-list.php' \
    --exclude='templates/admin/pro-woo-dashboard.php' \
    --exclude='templates/dashboard/live-visitors.php' \
    --exclude='templates/dashboard/authors.php' \
    --exclude='templates/dashboard/post-types.php' \
    --exclude='templates/dashboard/taxonomies.php' \
    --exclude='templates/dashboard/entry-exit.php' \
    --exclude='templates/dashboard/utm-table.php' \
    --exclude='templates/dashboard/search-table.php' \
    --exclude='templates/dashboard/outbound-table.php' \
    --exclude='templates/dashboard/downloads-table.php' \
    --exclude='templates/dashboard/goals.php' \
    --exclude='templates/dashboard/woo-overview-card.php' \
    \
    --exclude='assets/js/live-beacon.js' \
    --exclude='assets/js/live-dashboard.js' \
    --exclude='assets/js/outbound-tracker.js' \
    --exclude='assets/css/live.css' \
    \
    "$ROOT/" "$DIST/vireo-analytics/"

# The MCP server needs the bundled wordpress/mcp-adapter. A fresh production
# install into the build, rather than copying the working tree's vendor/, so
# the zip never carries dev packages or a stale autoloader. composer.json and
# composer.lock are copied in for the install and removed again afterwards:
# the plugin does not read them at runtime.
cp "$ROOT/composer.json" "$ROOT/composer.lock" "$DIST/vireo-analytics/"
composer install --no-dev --optimize-autoloader --no-interaction --quiet \
    --working-dir="$DIST/vireo-analytics"
rm -f "$DIST/vireo-analytics/composer.json" "$DIST/vireo-analytics/composer.lock"
# Package tooling and docs that the adapter ships but WordPress never reads.
# LICENSE.md stays: the packages are GPL and the notice travels with them.
find "$DIST/vireo-analytics/vendor" \( -name 'package.json' -o -name 'package-lock.json' -o -name 'composer.lock' -o -name 'README.md' -o -name 'CHANGELOG.md' -o -name 'readme.txt' -o -name '.gitattributes' -o -name '.editorconfig' \) -delete
# Composer's bin stubs point at CLI entry points nothing in WordPress runs.
rm -rf "$DIST/vireo-analytics/vendor/bin"

# Bump version headers in the Free build only — trunk stays at its monorepo
# dev version (typically *-alpha).
MAIN="$DIST/vireo-analytics/vireo-analytics.php"
README="$DIST/vireo-analytics/readme.txt"

python3 - "$MAIN" "$README" "$RELEASE_VERSION" <<'PY'
import re
import sys

main_path, readme_path, version = sys.argv[1], sys.argv[2], sys.argv[3]

with open(main_path, 'r', encoding='utf-8') as f:
    main = f.read()
main = re.sub(r"(?m)^(\s*\*\s*Version:\s*).+$", r"\g<1>" + version, main, count=1)
main = re.sub(r"define\(\s*'VIREO_VERSION',\s*'[^']+'\s*\);", f"define( 'VIREO_VERSION', '{version}' );", main, count=1)
with open(main_path, 'w', encoding='utf-8') as f:
    f.write(main)

with open(readme_path, 'r', encoding='utf-8') as f:
    readme = f.read()
readme = re.sub(r"(?m)^Stable tag:.*$", f"Stable tag: {version}", readme, count=1)
with open(readme_path, 'w', encoding='utf-8') as f:
    f.write(readme)
PY

# Ensure empty languages/ directory survives.
mkdir -p "$DIST/vireo-analytics/languages"

cd "$DIST"
# -X so macOS extended attributes don't end up in the zip.
zip -rqX vireo-analytics.zip vireo-analytics/

echo "Built: $DIST/vireo-analytics.zip (version $RELEASE_VERSION)"
du -h "$DIST/vireo-analytics.zip" | awk '{print "Size:", $1}'
