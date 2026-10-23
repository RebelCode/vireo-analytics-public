#!/usr/bin/env bash
# End-to-end check of the MCP server, the REST routes and the Abilities run
# endpoint against a real WordPress, over HTTP with application passwords.
#
#   VA_URL=http://localhost:8947 VA_ADMIN=admin VA_ADMIN_PW_FILE=... \
#   VA_VIEWER=viewer VA_VIEWER_PW_FILE=... bash tests/wp/mcp-e2e.sh
#
# The passwords are read from files so they never appear in a process list or
# a transcript. Prints each request and a trimmed response, then a summary.
# Exits non-zero on the first assertion that fails.
set -uo pipefail

URL="${VA_URL:-http://localhost:8947}"
ADMIN="${VA_ADMIN:-admin}"
VIEWER="${VA_VIEWER:-viewer}"
ADMIN_PW="$(tr -d '\n' < "${VA_ADMIN_PW_FILE:?set VA_ADMIN_PW_FILE}")"
VIEWER_PW="$(tr -d '\n' < "${VA_VIEWER_PW_FILE:?set VA_VIEWER_PW_FILE}")"
READER="${VA_READER:-reader}"
READER_PW="$(tr -d '\n' < "${VA_READER_PW_FILE:?set VA_READER_PW_FILE}")"
AUTHOR="${VA_AUTHOR:-writer}"
AUTHOR_PW="$(tr -d '\n' < "${VA_AUTHOR_PW_FILE:?set VA_AUTHOR_PW_FILE}")"
MCP="$URL/wp-json/vireo-analytics/mcp"
START="$(date -v-29d +%F 2>/dev/null || date -d '-29 days' +%F)"
END="$(date +%F)"

fail=0
ok() {
	if [ "$1" -eq 0 ]; then echo "PASS: $2"; else echo "FAIL: $2"; fail=$((fail+1)); fi
}
show() { echo "$1" | head -c "${2:-600}"; echo; echo; }

# MCP over streamable HTTP. initialize hands back an Mcp-Session-Id header
# that every later request has to carry.
mcp_call() { # user pw session body
	# macOS ships bash 3.2, where an empty array trips `set -u`.
	local session_header="X-E2E-No-Session: 1"
	[ -n "$3" ] && session_header="Mcp-Session-Id: $3"
	curl -s -D "$HDRS" -u "$1:$2" -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' -H "$session_header" "$MCP" --data "$4"
}
HDRS="$(mktemp)"

echo "== MCP initialize (admin)"
INIT='{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"e2e","version":"1"}}}'
echo "POST $MCP  $INIT"
R="$(mcp_call "$ADMIN" "$ADMIN_PW" "" "$INIT")"; show "$R"
SESSION="$(grep -i '^mcp-session-id:' "$HDRS" | awk '{print $2}' | tr -d '\r')"
echo "$R" | grep -q '"serverInfo"' ; ok $? "initialize returns serverInfo"
[ -n "$SESSION" ]; ok $? "initialize returns an Mcp-Session-Id"
echo "$R" | grep -q '"serverInfo":{"name":"Vireo Analytics"' ; ok $? "server is Vireo Analytics"

echo "== MCP tools/list"
R="$(mcp_call "$ADMIN" "$ADMIN_PW" "$SESSION" '{"jsonrpc":"2.0","id":2,"method":"tools/list"}')"
TOOLS="$(echo "$R" | python3 -c 'import sys,json; print("\n".join(sorted(t["name"] for t in json.load(sys.stdin)["result"]["tools"])))' 2>/dev/null)"
echo "$TOOLS" | tr '\n' ' '; echo; echo
N="$(echo "$TOOLS" | grep -c .)"
[ "$N" -eq 21 ]; ok $? "21 tools listed ($N)"
echo "$TOOLS" | grep -qx 'vireo-analytics-get-search-console'; ok $? "get-search-console is a tool"
echo "$TOOLS" | grep -q 'get-goals'; [ $? -ne 0 ]; ok $? "get-goals is not offered on free"
echo "$R" | python3 -c 'import sys,json; t=[x for x in json.load(sys.stdin)["result"]["tools"] if x["name"].endswith("get-totals")][0]; sys.exit(0 if t["annotations"].get("readOnlyHint") is True else 1)'; ok $? "get-totals carries readOnlyHint"

echo "== MCP tools/call get-totals"
CALL="{\"jsonrpc\":\"2.0\",\"id\":3,\"method\":\"tools/call\",\"params\":{\"name\":\"vireo-analytics-get-totals\",\"arguments\":{\"start\":\"$START\",\"end\":\"$END\"}}}"
echo "$CALL"
R="$(mcp_call "$ADMIN" "$ADMIN_PW" "$SESSION" "$CALL")"; show "$R"
echo "$R" | grep -q '"pageviews"' ; ok $? "get-totals returns pageviews"
PV="$(echo "$R" | python3 -c 'import sys,json; r=json.load(sys.stdin)["result"]; c=r.get("structuredContent") or json.loads(r["content"][0]["text"]); print(c["pageviews"])' 2>/dev/null)"
[ "${PV:-0}" -gt 1000 ]; ok $? "pageviews over the seeded month is a real number ($PV)"

echo "== MCP tools/call get-changes (new ability)"
CALL="{\"jsonrpc\":\"2.0\",\"id\":4,\"method\":\"tools/call\",\"params\":{\"name\":\"vireo-analytics-get-changes\",\"arguments\":{}}}"
R="$(mcp_call "$ADMIN" "$ADMIN_PW" "$SESSION" "$CALL")"; show "$R" 900
echo "$R" | grep -q '"summary"' && echo "$R" | grep -q '"change_pct"'; ok $? "get-changes returns a summary and change_pct"

echo "== MCP tools/call get-search-console (new ability)"
CALL="{\"jsonrpc\":\"2.0\",\"id\":5,\"method\":\"tools/call\",\"params\":{\"name\":\"vireo-analytics-get-search-console\",\"arguments\":{\"limit\":3}}}"
R="$(mcp_call "$ADMIN" "$ADMIN_PW" "$SESSION" "$CALL")"; show "$R" 700
echo "$R" | grep -q 'headless wordpress'; ok $? "get-search-console returns the seeded top query"

echo "== Planted injection strings come back flattened"
R="$(mcp_call "$ADMIN" "$ADMIN_PW" "$SESSION" '{"jsonrpc":"2.0","id":6,"method":"tools/call","params":{"name":"vireo-analytics-get-top-referrers","arguments":{"limit":50}}}')"
echo "$R" | python3 -c 'import sys,json; r=json.load(sys.stdin)["result"]; rows=r.get("structuredContent") or json.loads(r["content"][0]["text"]); hit=[x for x in rows if "ignore previous" in x["url"].lower()]; v=hit[0]["url"] if hit else "MISSING"; print("planted referrer over MCP:", json.dumps(v)); sys.exit(0 if hit and "\n" not in v and "[" not in v else 1)'; ok $? "referrer with an injected instruction is one flat line over MCP, no brackets"
R="$(curl -s -u "$ADMIN:$ADMIN_PW" "$URL/wp-json/vireo-analytics/v1/stats/top-referrers?start=$START&end=$END&limit=50")"
echo "$R" | python3 -c 'import sys,json; rows=json.load(sys.stdin); hit=[x for x in rows if "ignore previous" in x["url"].lower()]; v=hit[0]["url"] if hit else "MISSING"; print("planted referrer over REST:", json.dumps(v)); sys.exit(0 if hit and "\n" not in v and "[" not in v else 1)'; ok $? "the existing /stats/top-referrers route flattens it too"
R="$(mcp_call "$ADMIN" "$ADMIN_PW" "$SESSION" '{"jsonrpc":"2.0","id":7,"method":"tools/call","params":{"name":"vireo-analytics-get-events","arguments":{"name":"download","limit":50}}}')"
echo "$R" | python3 -c 'import sys,json; r=json.load(sys.stdin)["result"]; rows=r.get("structuredContent") or json.loads(r["content"][0]["text"]); rows=rows if isinstance(rows,list) else list(rows.values())[0]; hit=[x for x in rows if "IGNORE" in json.dumps(x)]; v=json.dumps(hit[0]) if hit else "MISSING"; print("planted event value over MCP:", v); sys.exit(0 if hit and "\n" not in v and "[system]" not in v else 1)'; ok $? "event value with an injected instruction is flat, no [system] marker"
R="$(curl -s -u "$ADMIN:$ADMIN_PW" "$URL/wp-json/vireo-analytics/v1/stats/404s?start=$START&end=$END&limit=50")"
echo "$R" | python3 -c 'import sys,json; d=json.load(sys.stdin); hit=[x for x in d["rows"] if "IGNORE" in x["path"]]; print("planted 404 path as returned:", json.dumps(hit[0]["path"]) if hit else "MISSING"); sys.exit(0 if hit and "\n" not in hit[0]["path"] and "<" not in hit[0]["path"] else 1)'; ok $? "404 path with an injected instruction is flattened over REST too"
echo

echo "== Draft post: not found for a reader who cannot edit it"
R="$(curl -s -o /dev/null -w '%{http_code}' -u "$ADMIN:$ADMIN_PW" "$URL/wp-json/vireo-analytics/v1/stats/post/${VA_DRAFT_ID:-0}?start=$START&end=$END")"
echo "admin (can edit): HTTP $R"; [ "$R" = "200" ]; ok $? "admin can read the draft's stats"
R="$(curl -s -o /dev/null -w '%{http_code}' -u "$READER:$READER_PW" "$URL/wp-json/vireo-analytics/v1/stats/post/${VA_DRAFT_ID:-0}?start=$START&end=$END")"
echo "reader with the dashboard capability only: HTTP $R"; [ "$R" = "404" ]; ok $? "a dashboard viewer who cannot edit the draft gets 404, so its title never leaves"
R="$(curl -s -u "$READER:$READER_PW" "$URL/wp-json/vireo-analytics/v1/stats/post/${VA_GUIDE_ID:-5}?start=$START&end=$END")"
echo "$R" | grep -q '"title":"Guide to headless WordPress"'; ok $? "the same reader gets a published post's stats and title"
R="$(mcp_call "$READER" "$READER_PW" "" "$INIT")"; echo "$R" | grep -q '"serverInfo"'; ok $? "a dashboard viewer (no admin rights) can use MCP"
echo

echo "== MCP as an author (dashboard capability not granted by default)"
R="$(curl -s -o /dev/null -w '%{http_code}' -u "$AUTHOR:$AUTHOR_PW" -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' "$MCP" --data "$INIT")"
echo "HTTP $R"; echo
[ "$R" = "403" ]; ok $? "author's application password gets $R on MCP"
R="$(curl -s -o /dev/null -w '%{http_code}' -u "$AUTHOR:$AUTHOR_PW" "$URL/wp-json/vireo-analytics/v1/stats/changes?start=$START&end=$END")"
[ "$R" = "403" ]; ok $? "author gets $R on REST /stats/changes"
R="$(curl -s -o /dev/null -w '%{http_code}' -u "$AUTHOR:$AUTHOR_PW" "$URL/wp-json/wp-abilities/v1/abilities/vireo-analytics/get-changes/run")"
[ "$R" = "403" ]; ok $? "author gets $R on the Abilities run endpoint"

echo "== MCP as a subscriber (must be refused)"
R="$(curl -s -o /dev/null -w '%{http_code}' -u "$VIEWER:$VIEWER_PW" -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' "$MCP" --data "$INIT")"
echo "HTTP $R"; echo
[ "$R" = "403" ] || [ "$R" = "401" ]; ok $? "subscriber's application password gets $R"

echo "== MCP anonymous (must be refused)"
R="$(curl -s -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' "$MCP" --data "$INIT")"
echo "HTTP $R"; echo
[ "$R" = "401" ] || [ "$R" = "403" ]; ok $? "anonymous gets $R"

echo "== The adapter's default site-wide server must not exist"
R="$(curl -s -o /dev/null -w '%{http_code}' -u "$ADMIN:$ADMIN_PW" -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' "$URL/wp-json/mcp/mcp-adapter-default-server" --data "$INIT")"
echo "HTTP $R"; echo
[ "$R" = "404" ]; ok $? "/wp-json/mcp/mcp-adapter-default-server is 404"

echo "== REST /stats/channels"
R="$(curl -s -u "$ADMIN:$ADMIN_PW" "$URL/wp-json/vireo-analytics/v1/stats/channels?start=$START&end=$END")"; show "$R"
echo "$R" | grep -q '"source":"ai"'; ok $? "sources include the ai channel"

echo "== REST /stats/404s"
R="$(curl -s -u "$ADMIN:$ADMIN_PW" "$URL/wp-json/vireo-analytics/v1/stats/404s?start=$START&end=$END&limit=5")"; show "$R"
echo "$R" | grep -q '"broken_links":\[{"path":"\\\?/old-post"'; ok $? "404s split out the broken link with its linking page"

echo "== REST and Abilities run as a subscriber and anonymous"
for route in "channels?" "changes?" "search-console?" "404s?" "bots?" "vitals?" "annotations?" "hourly?" "highlights?" "ai-referrals?" "referrer?host=google.com&" "event/signup?" "year-review?"; do
	R="$(curl -s -o /dev/null -w '%{http_code}' -u "$VIEWER:$VIEWER_PW" "$URL/wp-json/vireo-analytics/v1/stats/${route}start=$START&end=$END")"
	[ "$R" = "403" ]; ok $? "subscriber gets $R on /stats/${route%%[?&]*}"
	R="$(curl -s -o /dev/null -w '%{http_code}' "$URL/wp-json/vireo-analytics/v1/stats/${route}start=$START&end=$END")"
	[ "$R" = "401" ]; ok $? "anonymous gets $R on /stats/${route%%[?&]*}"
done
R="$(curl -s -o /dev/null -w '%{http_code}' -u "$VIEWER:$VIEWER_PW" "$URL/wp-json/wp-abilities/v1/abilities/vireo-analytics/get-search-console/run")"
[ "$R" = "403" ]; ok $? "subscriber gets $R on the Abilities run endpoint"
R="$(curl -s -o /dev/null -w '%{http_code}' "$URL/wp-json/wp-abilities/v1/abilities/vireo-analytics/get-search-console/run")"
[ "$R" = "401" ]; ok $? "anonymous gets $R on the Abilities run endpoint"

echo "== Abilities run endpoint (GET, readonly)"
R="$(curl -s -u "$ADMIN:$ADMIN_PW" "$URL/wp-json/wp-abilities/v1/abilities/vireo-analytics/get-ai-referrals/run?input%5Bstart%5D=$START&input%5Bend%5D=$END")"; show "$R"
echo "$R" | grep -q '"crawler_requests"'; ok $? "get-ai-referrals runs over the Abilities REST API"

rm -f "$HDRS"
echo
if [ "$fail" -eq 0 ]; then echo "All passed"; else echo "$fail FAILED"; exit 1; fi
