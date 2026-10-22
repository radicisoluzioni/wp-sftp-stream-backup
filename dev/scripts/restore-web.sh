#!/bin/bash
# Restores the newest complete backup through the restore.php web UI (runs inside "target").
set -u
B=$(for d in /sftpdata/wp-backups/*/; do [ -f "$d/manifest.json" ] && echo "$d"; done | sort | tail -1)
echo "restoring from $B"
cd /var/www/html
rm -rf ./* ./.htaccess 2>/dev/null
cp "$B"/files.tar.gz "$B"/database.sql.gz "$B"/manifest.json "$B"/restore.php .
chown -R www-data:www-data /var/www/html

J=/tmp/cookies; rm -f $J
U=http://localhost:8082/restore.php
curl -fsS $U | grep -q 'set a password first' || { echo "expected locked page"; exit 1; }
BRANDING=$(curl -fsS $U)
grep -q 'data:image/png;base64,' <<< "$BRANDING" && grep -q 'href="https://radici.biz"' <<< "$BRANDING" && grep -q 'Coded by' <<< "$BRANDING" || { echo "standalone restore branding missing"; exit 1; }
sed -i "s/define( 'WPSSB_RESTORE_PASSWORD', '' );/define( 'WPSSB_RESTORE_PASSWORD', 'restore-secret-123' );/" restore.php

curl -fsS -c $J -b $J $U | grep -q 'Restore password' || { echo "expected login page"; exit 1; }
curl -fsS -c $J -b $J --data-urlencode action=login --data-urlencode password=wrong $U | grep -q 'Wrong password' || { echo "wrong password accepted?"; exit 1; }
curl -fsS -c $J -b $J --data-urlencode action=login --data-urlencode password=restore-secret-123 $U -o /dev/null
PAGE=$(curl -fsS -c $J -b $J $U)
grep -q 'Start restore' <<< "$PAGE" || { echo "$PAGE"; echo "form not shown"; exit 1; }
CSRF=$(echo "$PAGE" | grep -o 'name="csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"$//')
curl -fsS -c $J -b $J --data-urlencode action=begin --data-urlencode "csrf=$CSRF" \
  --data-urlencode db_host=db --data-urlencode db_name=wp2 --data-urlencode db_user=root --data-urlencode db_pass=root \
  --data-urlencode url=http://localhost:8082 $U -o /dev/null

for i in $(seq 1 3000); do
  R=$(curl -fsS -c $J -b $J --data-urlencode action=step --data-urlencode "csrf=$CSRF" $U)
  OUT=$(echo "$R" | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(!is_array($d)){echo "BAD"; exit;} if(!empty($d["busy"])){echo "busy"; exit;} if(!empty($d["error"])){echo "ERROR ".$d["error"]; exit;} echo $d["phase"]." ".$d["progress"];')
  echo "step $i: $OUT"
  case "$OUT" in
    done*) break ;;
    ERROR*|BAD*) echo "$R"; exit 1 ;;
  esac
done
[ ! -f restore.php ] || { echo "restore.php not deleted"; exit 1; }
[ ! -f wpssb-restore-state.php ] || { echo "state file not deleted"; exit 1; }
echo "web restore finished"
