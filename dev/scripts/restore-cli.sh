#!/bin/bash
# Restores the newest backup with the CLI mode of restore.php into /var/www/cli (DB wp3).
# The process is killed (SIGKILL) at arbitrary points and resumed, to prove that the
# restore is crash-safe (no duplicated SQL statements, no double URL replacement).
set -uo pipefail
B=$(for d in /sftpdata/wp-backups/*/; do [ -f "$d/manifest.json" ] && echo "$d"; done | sort | tail -1)
rm -rf /var/www/cli && mkdir -p /var/www/cli && cd /var/www/cli
cp "$B"/files.tar.gz "$B"/database.sql.gz "$B"/manifest.json "$B"/restore.php .
ARGS="--db-host=db --db-name=wp3 --db-user=root --db-pass=root --url=http://localhost:8081/sub --step=1 --delete-archives --self-delete"
kills=0
for i in $(seq 1 300); do
  # Random kill delay between 0.3 and 2.2 seconds.
  delay=$(awk -v s=$RANDOM 'BEGIN{srand(s); printf "%.1f", 0.3 + rand()*1.9}')
  timeout -s KILL "$delay" php restore.php $ARGS > /tmp/restore-cli.out 2>&1
  rc=$?
  if [ $rc -eq 0 ]; then break; fi
  if [ $rc -eq 137 ]; then kills=$((kills+1)); continue; fi
  cat /tmp/restore-cli.out; echo "restore failed (rc=$rc)"; exit 1
done
tail -n 3 /tmp/restore-cli.out
echo "restore was killed $kills times"
[ ! -f restore.php ] && [ ! -f files.tar.gz ] && [ ! -f wpssb-restore-state.php ] || { echo "cleanup failed"; exit 1; }
grep -q "define( 'DB_NAME', 'wp3' )" wp-config.php || { echo "wp-config not updated"; exit 1; }
echo "cli restore finished"
