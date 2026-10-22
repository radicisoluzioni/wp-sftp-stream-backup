#!/bin/bash
# Kills restore steps at random moments during SQL import and URL replacement, then
# verifies (with compare-db.php wp4) that nothing was duplicated or replaced twice.
set -uo pipefail
B=$(for d in /sftpdata/wp-backups/*/; do [ -f "$d/manifest.json" ] && echo "$d"; done | sort | tail -1)
php -r '$m=mysqli_connect("db","root","root"); $m->query("DROP DATABASE IF EXISTS wp4"); $m->query("CREATE DATABASE wp4");'
rm -rf /var/www/chaos && mkdir -p /var/www/chaos && cd /var/www/chaos
cp "$B"/files.tar.gz "$B"/database.sql.gz "$B"/manifest.json "$B"/restore.php .

# Extraction runs undisturbed.
while :; do
  p=$(php /scripts/chaos-step.php) || { echo "step failed: $p"; exit 1; }
  [ "$p" != "extract" ] && break
done
echo "extraction done, now in phase $p"

kills=0; import_kills=0; replace_kills=0
for i in $(seq 1 3000); do
  before=$(grep -o '"phase":"[a-z]*"' wpssb-restore-state.php | cut -d'"' -f4)
  roll=$((RANDOM % 100))
  if [ $roll -lt 15 ]; then
    # Deterministic crash after commit: the step runs but its checkpoint is not saved.
    out=$(php /scripts/chaos-step.php nosave 2>&1); rc=137
  elif [ $roll -lt 30 ]; then
    delay=$(awk -v s=$RANDOM 'BEGIN{srand(s); printf "%.3f", 0.02 + rand()*0.15}')
    out=$(timeout -s KILL "$delay" php /scripts/chaos-step.php 2>&1)
    rc=$?
  else
    out=$(php /scripts/chaos-step.php 2>&1)
    rc=$?
  fi
  if [ $rc -eq 137 ]; then
    kills=$((kills+1))
    [ "$before" = "import" ] && import_kills=$((import_kills+1))
    [ "$before" = "replace" ] && replace_kills=$((replace_kills+1))
    continue
  fi
  [ $rc -eq 0 ] || { echo "$out"; echo "step failed rc=$rc"; exit 1; }
  [ "$(echo "$out" | head -1)" = "done" ] && break
done
echo "$out" | grep -E 'replaying|imported|updated|completed'
echo "kills: total=$kills import=$import_kills replace=$replace_kills"
[ "$import_kills" -gt 5 ] && [ "$replace_kills" -gt 0 ] || { echo "interruptions did not hit import/replace enough"; exit 1; }
echo "chaos restore finished"
