#!/usr/bin/env bash
#
# Refuse to build Free if it has walked onto ground Pro already owns.
#
# Written after 0.6.0 shipped an entry/exit report that Pro had covered for
# months, using a table of the same name with a different primary key. On a
# site running both, Free's inserts would have taken the column defaults and
# collapsed every row for a day onto one key, corrupting Pro's data. Nobody was
# hit, and only because the one site running Pro had not updated yet.
#
# Three checks, cheapest first:
#   1. table names created by Free that Pro also creates or writes
#   2. class file names present in both plugins
#   3. metric/option names that look like the same feature in both
#
# Exits non-zero on any hit, so build-free.sh stops.

set -uo pipefail

FREE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PRO_DIR="${VIREO_PRO_DIR:-$(cd "$FREE_DIR/../vireo-analytics-pro" 2>/dev/null && pwd)}"

if [ -z "${PRO_DIR:-}" ] || [ ! -d "$PRO_DIR" ]; then
	echo "  pro-collision check: Pro source not found, skipping."
	echo "  Set VIREO_PRO_DIR to enable. This is a warning, not a pass."
	exit 0
fi

fail=0

note_fail() {
	fail=1
	echo ""
	echo "  COLLISION: $1"
	echo "    $2"
}

# ---------------------------------------------------------------- tables -----
# Only CREATE TABLE counts. Pro reading a table Free owns is the intended
# architecture: Pro extends Free and queries its rollups. The failure mode is
# two plugins each believing they define the same table's shape.
# Free's installer keeps its definitions as short names keyed in
# Installer::table_definitions() ('site_stats' => "..."), with the va_ prefix
# added by Sites::table(); the 404 tracker still spells its CREATE inline.
free_creates=$( { grep -rhoE "CREATE TABLE [^v]*va_[a-z_]+" "$FREE_DIR/includes" 2>/dev/null \
		| grep -oE "va_[a-z_]+"; \
	grep -hoE "^\s+'[a-z0-9_]+' => \"" "$FREE_DIR/includes/class-installer.php" 2>/dev/null \
		| grep -oE "'[a-z0-9_]+'" | tr -d "'" | sed 's/^/va_/'; } | sort -u )

# Pro names its tables in class constants and interpolates them into the
# CREATE, so grepping the CREATE line alone finds nothing. Collect both: any
# va_ table named in a constant, and any named inline.
pro_creates=$( { grep -rhoE "const [A-Z_]*TABLE[A-Z_]*\s*=\s*'va_[a-z_]+'" "$PRO_DIR/pro" 2>/dev/null \
		| grep -oE "va_[a-z_]+"; \
	grep -rhoE "CREATE TABLE[^\"']*va_[a-z_]+" "$PRO_DIR/pro" 2>/dev/null \
		| grep -oE "va_[a-z_]+"; } | sort -u )

for t in $free_creates; do
	if echo "$pro_creates" | grep -qx "$t"; then
		note_fail "both plugins CREATE table '$t'" \
			"Two definitions of one table. dbDelta cannot change a primary key, so
    whichever runs second produces a table neither side expects."
	fi
done

# --------------------------------------------------------------- classes -----
for f in "$FREE_DIR"/includes/class-*.php; do
	[ -e "$f" ] || continue
	n=$(basename "$f")
	if [ -e "$PRO_DIR/pro/$n" ]; then
		note_fail "class file '$n' exists in both plugins" \
			"Two classes with the same job. Decide which tier owns the feature."
	fi
done

# --------------------------------------------------------------- metrics -----
# A metric Free computes and stores that Pro also computes and stores is a
# duplicated feature even when table and class names differ, which is exactly
# how the 0.6.0 mistake got as far as it did. Only counts when BOTH sides write
# it, so a shared hook name or a filter Free exposes for Pro does not trip it.
for m in entry_exit bounces pages_per_visit pages_per_session funnel_step \
         goal_completion order_revenue outbound_click utm_campaign spike_event; do
	free_writes=$(grep -rlE "(INSERT|UPDATE|REPLACE).*$m|'$m'\s*=>" "$FREE_DIR/includes" 2>/dev/null | head -1)
	pro_writes=$(grep -rlE "(INSERT|UPDATE|REPLACE).*$m|'$m'\s*=>" "$PRO_DIR/pro" 2>/dev/null | head -1)
	if [ -n "$free_writes" ] && [ -n "$pro_writes" ]; then
		note_fail "both tiers write metric '$m'" \
			"Free: ${free_writes#"$FREE_DIR"/}  Pro: ${pro_writes#"$PRO_DIR"/}"
	fi
done

# ----------------------------------------------------- shared textdomain -----
# Pro shares Free's text domain on purpose, so one interface reads from one
# catalogue. A Domain Path on the Pro side registers Pro's languages/ directory
# against that shared domain, and WordPress keeps one path per domain, so Pro's
# empty folder silenced all nine of Free's locales. Installed is enough; the
# registry scans installed plugins, not active ones.
pro_domain=$(sed -n 's/^[[:space:]]*\*[[:space:]]*Text Domain:[[:space:]]*//p' "$PRO_DIR"/*.php 2>/dev/null | head -1)
free_domain=$(sed -n 's/^[[:space:]]*\*[[:space:]]*Text Domain:[[:space:]]*//p' "$FREE_DIR"/vireo-analytics.php 2>/dev/null | head -1)
if [ -n "$pro_domain" ] && [ "$pro_domain" = "$free_domain" ]; then
	pro_domain_path=$(sed -n 's/^[[:space:]]*\*[[:space:]]*Domain Path:[[:space:]]*//p' "$PRO_DIR"/*.php 2>/dev/null | head -1)
	if [ -n "$pro_domain_path" ]; then
		note_fail "Pro declares Domain Path on the shared text domain '$pro_domain'" \
			"Pro registers its own languages/ for a domain Free owns, and WordPress resolves one path per domain. Remove Domain Path from Pro, or give Pro its own text domain."
	fi
fi

# Free must actually load what it ships. The header alone does not: the
# automatic path only covers wp-content/languages/plugins/.
if [ -d "$FREE_DIR/languages" ] && ls "$FREE_DIR"/languages/*.mo >/dev/null 2>&1; then
	if ! grep -rq "load_plugin_textdomain" "$FREE_DIR"/vireo-analytics.php "$FREE_DIR"/includes 2>/dev/null; then
		note_fail "Free bundles .mo files but never calls load_plugin_textdomain()" \
			"Text Domain and Domain Path are metadata. Without the call the bundled catalogues are dead weight in the zip."
	fi
fi

if [ "$fail" -ne 0 ]; then
	echo ""
	echo "  Build stopped. Free has duplicated or collided with Pro."
	echo "  Pro is the product being sold; Free giving the same thing away costs"
	echo "  twice, once in revenue and once in whatever the collision breaks."
	exit 1
fi

echo "  pro-collision check: clean"
exit 0
