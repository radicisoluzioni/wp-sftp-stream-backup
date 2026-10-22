#!/bin/bash
# Drives a one-click runtime over HTTP exactly like its page does: oneclick-web.sh <url#token> [host-port]
# Also checks that only run.php is reachable and that a wrong token is refused.
set -uo pipefail
URL="${1%%#*}"
TOKEN="${1#*#}"
BASE="${URL%/run.php}"
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }

[ "$(code "$URL")" = "200" ] || { echo "runtime page not reachable"; exit 1; }
for f in config.php engine.php state.php src/Remote/S3Storage.php; do
  c=$(code "$BASE/$f")
  [ "$c" = "403" ] || [ "$c" = "404" ] || { echo "$f is reachable over HTTP ($c)"; exit 1; }
done
echo "[ok] only run.php is reachable"
c=$(code -X POST -d action=status -d token=0000000000000000000000000000000000000000000000000000000000000000 "$URL")
[ "$c" = "403" ] || { echo "wrong token accepted ($c)"; exit 1; }
echo "[ok] wrong token refused"

for i in $(seq 1 2000); do
  out=$(curl -s -X POST -d action=step --data-urlencode "token=$TOKEN" "$URL")
  if echo "$out" | grep -q '"done":true'; then
    echo "[ok] web restore finished after $i steps"
    break
  fi
  if ! echo "$out" | grep -q '"ok":true'; then
    echo "step failed: $out"; exit 1
  fi
done
echo "$out" | grep -q '"done":true' || { echo "restore did not finish"; exit 1; }
c=$(code "$URL")
[ "$c" = "404" ] || [ "$c" = "403" ] || { echo "runtime still present after completion ($c)"; exit 1; }
echo "[ok] runtime removed itself"
