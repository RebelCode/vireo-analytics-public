#!/usr/bin/env bash
# Compiles locale files from their .po source. For each locale this produces:
#
#   languages/vireo-analytics-<locale>.mo        — PHP gettext strings
#   languages/vireo-analytics-<locale>.l10n.php  — WP 6.5+ fast loader
#   languages/vireo-analytics-<locale>-<md5>.json — JS strings, one file per
#       script source, md5 of the script path relative to the plugin root
#       (e.g. md5("assets/js/dashboard.js")). WordPress computes the same
#       hash in load_script_textdomain(), so these names are load-bearing.
#
# Usage:
#   build/make-l10n.sh pot       # regenerate languages/vireo-analytics.pot
#   build/make-l10n.sh           # compile every languages/*.po
#   build/make-l10n.sh es_ES     # compile just one locale
#
# The POT scans PHP, JS (assets/js and blocks/*/index.js) and block.json
# titles, descriptions and keywords. Anything that does not ship in the free
# build is excluded, so translators never see Pro or tooling strings.
#
# Requires WP-CLI with the bundled i18n-command (wp i18n ...).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
LANG_DIR="$ROOT/languages"

if ! command -v wp >/dev/null 2>&1; then
	echo "WP-CLI (wp) not found in PATH" >&2
	exit 1
fi

compile_po() {
	local po="$1"
	echo "Compiling $(basename "$po")"
	wp i18n make-mo "$po" "$LANG_DIR"
	wp i18n make-php "$po" "$LANG_DIR"
	# --no-purge keeps the JS strings in the .po/.mo too, so the .po stays
	# the single complete source for the locale.
	wp i18n make-json "$po" "$LANG_DIR" --no-purge
}

# Directories and files build-free.sh leaves out of the zip, plus our own
# output. Keep this in step with the rsync excludes there.
POT_EXCLUDE="pro,build,dist,tests,docs,node_modules,languages,data,.wordpress-org,.github,.claude"
POT_EXCLUDE="$POT_EXCLUDE,templates/ai-report-email.php,templates/admin/ai-preview.php,templates/admin/pro-goals-edit.php,templates/admin/pro-goals-list.php,templates/admin/pro-woo-dashboard.php"
POT_EXCLUDE="$POT_EXCLUDE,templates/dashboard/live-visitors.php,templates/dashboard/authors.php,templates/dashboard/post-types.php,templates/dashboard/taxonomies.php,templates/dashboard/entry-exit.php"
POT_EXCLUDE="$POT_EXCLUDE,templates/dashboard/utm-table.php,templates/dashboard/search-table.php,templates/dashboard/outbound-table.php,templates/dashboard/downloads-table.php,templates/dashboard/goals.php,templates/dashboard/woo-overview-card.php"
POT_EXCLUDE="$POT_EXCLUDE,assets/js/live-beacon.js,assets/js/live-dashboard.js,assets/js/outbound-tracker.js"

if [ "${1:-}" = "pot" ]; then
	# --slug pins the support URL in the header to the wordpress.org slug
	# rather than whatever the checkout folder is called.
	wp i18n make-pot "$ROOT" "$LANG_DIR/vireo-analytics.pot" \
		--slug=vireo-analytics \
		--domain=vireo-analytics \
		--exclude="$POT_EXCLUDE"
	exit 0
fi

if [ $# -ge 1 ]; then
	PO="$LANG_DIR/vireo-analytics-$1.po"
	if [ ! -f "$PO" ]; then
		echo "No such file: $PO" >&2
		exit 1
	fi
	compile_po "$PO"
else
	found=0
	for po in "$LANG_DIR"/vireo-analytics-*.po; do
		[ -f "$po" ] || continue
		found=1
		compile_po "$po"
	done
	if [ "$found" -eq 0 ]; then
		echo "No .po files in $LANG_DIR" >&2
		exit 1
	fi
fi

echo "Done."
