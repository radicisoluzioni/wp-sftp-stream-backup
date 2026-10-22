#!/bin/bash
# Restores a chosen format-2 SFTP test backup without joining its parts.
set -euo pipefail
id="${1:?backup ID required}"
[[ "$id" =~ ^[a-zA-Z0-9.-]+$ ]] || { echo "invalid backup ID"; exit 1; }
backup="/sftpdata/segmented/$id"
dest="/var/www/parts/$id"
[ -f "$backup/manifest.json" ] || { echo "backup not found"; exit 1; }
[ ! -e "$dest/wp-config.php" ] || { echo "test destination already restored"; exit 1; }
mkdir -p "$dest"
cp "$backup/"* "$dest/"
cd "$dest"
php -r '$m = new mysqli("db", "root", "root"); if (!$m->query("CREATE DATABASE IF NOT EXISTS wp_parts CHARACTER SET utf8mb4")) exit(1);'
php restore.php --db-host=db --db-name=wp_parts --db-user=root --db-pass=root --url=http://localhost:8082/parts --step=1 --delete-archives --self-delete
[ -f wp-config.php ] && [ ! -f restore.php ] && [ ! -f files.tar.gz.parts.json ] || { echo "restore cleanup failed"; exit 1; }
if [ -f wp-content/uploads/wpssb-large-fixture.bin ]; then
    sha256sum wp-content/uploads/wpssb-large-fixture.bin
fi
php /scripts/compare-db.php wp_parts http://localhost:8082/parts
echo "parts restore finished: $dest"
