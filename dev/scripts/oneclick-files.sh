#!/bin/sh
# Site file changes made after the backup (mutate) and their checks after a rollback (check).
cd /var/www/html || exit 1
U=wp-content/uploads
if [ "$1" = "mutate" ]; then
  echo "added after backup" > $U/oneclick-extra.txt
  mkdir -p $U/oneclick-dir/sub && echo x > $U/oneclick-dir/sub/x.txt
  echo "modified after backup" > $U/2026/10/small-1.txt
  rm -f $U/2026/10/small-2.txt
  mkdir -p wp-content/cache && echo "excluded" > wp-content/cache/oneclick-keep.html
  echo "files mutated"
  exit 0
fi
fail=0
[ ! -e $U/oneclick-extra.txt ] || { echo "[FAIL] new file not deleted"; fail=1; }
[ ! -e $U/oneclick-dir ] || { echo "[FAIL] new directory not deleted"; fail=1; }
[ -f wp-content/cache/oneclick-keep.html ] || { echo "[FAIL] excluded path not preserved"; fail=1; }
[ ! -e .maintenance ] || { echo "[FAIL] maintenance mode left on"; fail=1; }
ls -d wpssb-restore-* >/dev/null 2>&1 && { echo "[FAIL] runtime directory left behind"; fail=1; }
grep -q "DB_NAME', 'wp'" wp-config.php || { echo "[FAIL] wp-config.php changed"; fail=1; }
[ $fail -eq 0 ] && echo "[ok] extra files removed, exclusions and wp-config kept, runtime gone"
exit $fail
