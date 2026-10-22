#!/bin/sh
# Prints "sha1  path" for every site file that a backup/restore must preserve.
cd "${1:-/var/www/html}" || exit 1
find . -path ./wp-content/cache -prune -o -type f -print \
  | grep -v '^./wp-content/plugins/wp-sftp-stream-backup' \
  | grep -v '^./wp-config.php$' \
  | grep -Ev '^./(files.tar.gz|database.sql.gz|manifest.json)$' \
  | sort | while IFS= read -r f; do sha1sum "$f"; done
