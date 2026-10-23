#!/usr/bin/env bash
# Minifies the front-end scripts: assets/js/src/<name>.js is the readable
# source that ships for wordpress.org's reviewers, assets/js/<name>.js is what
# browsers load. The shipped names never change, because connected sites and
# headless front ends embed them by URL.
#
#   build/minify-js.sh          rewrite the shipped files from src/
#   build/minify-js.sh --check  fail if a shipped file is older than its source
#                               or differs from what src/ minifies to
#
# Terser is pinned so the check compares like with like: a different release
# can emit different (equally correct) bytes and read as stale.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TERSER="terser@5.51.2"
FILES=(tracker vitals)

minify() { # src dest
	npx --yes "$TERSER" "$1" --compress --mangle --comments false --output "$2"
}

if [ "${1:-}" = "--check" ]; then
	status=0
	tmp="$(mktemp -d)"
	trap 'rm -rf "$tmp"' EXIT
	for name in "${FILES[@]}"; do
		src="$ROOT/assets/js/src/$name.js"
		out="$ROOT/assets/js/$name.js"
		if [ ! -f "$out" ]; then
			echo "assets/js/$name.js is missing; run build/minify-js.sh" >&2
			status=1
			continue
		fi
		if [ "$src" -nt "$out" ]; then
			echo "assets/js/$name.js is older than assets/js/src/$name.js; run build/minify-js.sh" >&2
			status=1
		fi
		minify "$src" "$tmp/$name.js"
		if ! cmp -s "$tmp/$name.js" "$out"; then
			echo "assets/js/$name.js does not match what assets/js/src/$name.js minifies to; run build/minify-js.sh" >&2
			status=1
		fi
	done
	[ "$status" -eq 0 ] && echo "Minified scripts are current."
	exit "$status"
fi

for name in "${FILES[@]}"; do
	src="$ROOT/assets/js/src/$name.js"
	out="$ROOT/assets/js/$name.js"
	minify "$src" "$out"
	printf '%s: %s B -> %s B (%s B gzipped)\n' "$name.js" "$(wc -c < "$src" | tr -d ' ')" "$(wc -c < "$out" | tr -d ' ')" "$(gzip -9 -c "$out" | wc -c | tr -d ' ')"
done
