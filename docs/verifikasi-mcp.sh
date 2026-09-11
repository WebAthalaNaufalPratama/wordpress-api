#!/usr/bin/env bash
# Uji endpoint MCP WordPress tanpa Hermes. Butuh env WP_MCP_AUTH="Basic <base64>".
set -u
URL="${MCP_URL:-https://wordpress-api.test/wp-json/mcp/mcp-adapter-default-server}"
: "${WP_MCP_AUTH:?set dulu: export WP_MCP_AUTH='Basic ...'}"
H=(-H "Content-Type: application/json" -H "Accept: application/json, text/event-stream" -H "Authorization: $WP_MCP_AUTH")

echo "=== 0. tanpa auth -> harus 401, bukan 404 ==="
curl -s -o /dev/null -w "HTTP %{http_code}\n" -X POST "$URL" -H "Content-Type: application/json" -d '{"jsonrpc":"2.0","id":0,"method":"initialize","params":{}}'

echo; echo "=== 1. initialize ==="
HDR=$(mktemp)
curl -s -D "$HDR" -X POST "$URL" "${H[@]}" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl-test","version":"0"}}}' \
  | head -c 600; echo
SID=$(grep -i "^mcp-session-id:" "$HDR" | awk '{print $2}' | tr -d '\r')
echo "session: ${SID:-<TIDAK ADA>}"
[ -z "$SID" ] && { echo "GAGAL: tidak dapat Mcp-Session-Id"; exit 1; }
S=(-H "Mcp-Session-Id: $SID")

curl -s -o /dev/null -X POST "$URL" "${H[@]}" "${S[@]}" -d '{"jsonrpc":"2.0","method":"notifications/initialized"}'

echo; echo "=== 2. tools/list -> cari woo/* ==="
curl -s -X POST "$URL" "${H[@]}" "${S[@]}" -d '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}' \
  | grep -oE '"name":"[^"]+"' | sort -u

echo; echo "=== 3. tools/call woo/list-orders (processing, 3) ==="
curl -s -X POST "$URL" "${H[@]}" "${S[@]}" \
  -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"woo/list-orders","arguments":{"status":"processing","limit":3}}}' \
  | head -c 1200; echo

echo; echo "=== 4. tools/call woo/complete-order pada order yang TIDAK boleh (id 1) -> harus error ==="
curl -s -X POST "$URL" "${H[@]}" "${S[@]}" \
  -d '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"woo/complete-order","arguments":{"order_id":1}}}' \
  | head -c 600; echo
rm -f "$HDR"
