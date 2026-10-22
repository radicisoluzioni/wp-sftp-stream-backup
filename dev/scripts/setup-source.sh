#!/bin/bash
# Prepares the source WordPress site (runs inside the "source" container).
set -euo pipefail
cd /var/www/html

for i in $(seq 1 120); do [ -f wp-includes/version.php ] && break; sleep 1; done
php -r '
mysqli_report(MYSQLI_REPORT_OFF);
for ($i = 0; $i < 60 && !($m = @mysqli_connect("db", "root", "root")); $i++) sleep(1);
foreach (array("wp", "wp2", "wp3") as $d) { $m->query("DROP DATABASE IF EXISTS $d"); $m->query("CREATE DATABASE $d CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); }
'

rm -f wp-config.php
wp config create --dbname=wp --dbuser=root --dbpass=root --dbhost=db --skip-check --allow-root --quiet
wp core install --url=http://localhost:8081 --title="Source Site" --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email --allow-root --quiet
wp plugin activate wp-sftp-stream-backup --allow-root
wp post generate --count=400 --allow-root
wp eval-file /scripts/seed.php --allow-root

mkdir -p wp-content/uploads/2026/10 wp-content/cache
head -c 26214400 /dev/urandom > wp-content/uploads/2026/10/big.bin
for i in $(seq 1 300); do echo "small file $i" > "wp-content/uploads/2026/10/small-$i.txt"; done
echo "cached page" > wp-content/cache/page.html
mkdir -p "wp-content/uploads/très long/$(printf 'x%.0s' $(seq 1 120))"
echo "deep" > "wp-content/uploads/très long/$(printf 'x%.0s' $(seq 1 120))/$(printf 'y%.0s' $(seq 1 110)).txt"
chown -R www-data:www-data wp-content/uploads wp-content/cache
echo "source ready"
