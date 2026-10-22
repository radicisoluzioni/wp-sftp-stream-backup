#!/bin/sh
# Lints all plugin PHP files (run inside a php:*-cli container with the plugin mounted on /app).
set -e
cd /app
fail=0
for f in $(find . -name '*.php' -not -path './vendor/*' -not -path './vendor-prefixed/*' -not -path './bin/*'); do
  if ! php -l "$f" > /tmp/lint.out 2>&1; then
    cat /tmp/lint.out
    fail=1
  fi
done
echo "PHP $(php -r 'echo PHP_VERSION;') lint finished, fail=$fail"
exit $fail
