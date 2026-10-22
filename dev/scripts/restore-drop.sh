#!/bin/bash
# Runs in the test PHP container with the Nextcloud data volume mounted at /nc.
set -euo pipefail
id="${1:?backup ID required}"
generation="${2:?generation required}"
share="${3:?owner share directory required}"
[[ "$id" =~ ^[a-zA-Z0-9.-]+$ ]] && [[ "$generation" =~ ^[a-f0-9]+$ ]] || exit 1
prefix="$id--$generation--"
dest="/var/www/drop-restore"
mkdir -p "$dest"
cp "$share/$prefix"* "$dest/"
cd "$dest"
mv "${prefix}restore.php" restore.php
mv "${prefix}manifest.json" manifest.json
php -r '$m = new mysqli("db", "root", "root"); if (!$m->query("CREATE DATABASE IF NOT EXISTS wp_drop CHARACTER SET utf8mb4")) exit(1);'
php restore.php --db-host=db --db-name=wp_drop --db-user=root --db-pass=root --url=http://localhost:8082/drop --step=1 --delete-archives --self-delete
php /scripts/compare-db.php wp_drop http://localhost:8082/drop
[ ! -f restore.php ] && [ ! -f "${prefix}files.tar.gz.parts.json" ] || { echo "public restore cleanup failed"; exit 1; }
if [ -f wp-content/uploads/wpssb-large-fixture.bin ]; then
    sha256sum wp-content/uploads/wpssb-large-fixture.bin
fi
echo "public File Drop restore finished"
