#!/bin/bash
# Exercises the admin screen and its AJAX endpoints over HTTP (runs inside "source").
set -eu
J=/tmp/admin-cookies; rm -f $J
B=http://localhost:8081
curl -fsS -c $J -b $J $B/wp-login.php -o /dev/null
curl -fsS -c $J -b $J --data-urlencode log=admin --data-urlencode pwd=admin --data-urlencode wp-submit=Log\ In \
  --data-urlencode redirect_to=$B/wp-admin/ --data-urlencode testcookie=1 -b "wordpress_test_cookie=WP%20Cookie%20check" $B/wp-login.php -o /dev/null
PAGE=$(curl -fsS -c $J -b $J "$B/wp-admin/tools.php?page=wpssb")
grep -q 'SFTP Stream Backup' <<< "$PAGE" || { echo "admin page not rendered"; exit 1; }
grep -q 'id="wpssb-start"' <<< "$PAGE" || { echo "start button missing"; exit 1; }
grep -q 'Coded by' <<< "$PAGE" && grep -q 'href="https://radici.biz"' <<< "$PAGE" || { echo "Radici credits missing"; exit 1; }
grep -q 'radici-logo.png' <<< "$PAGE" && grep -q 'admin.css?ver=1.1.1' <<< "$PAGE" || { echo "branding assets missing"; exit 1; }
REPOS=$(curl -fsS -c $J -b $J "$B/wp-admin/tools.php?page=wpssb-repositories")
grep -q 'admin.css?ver=1.1.1' <<< "$REPOS" && grep -q 'Coded by' <<< "$REPOS" || { echo "repository branding missing"; exit 1; }
if grep -q 'id="wpssb-admin-js"' <<< "$REPOS"; then echo "backup script must not load on repositories"; exit 1; fi
OTHER=$(curl -fsS -c $J -b $J "$B/wp-admin/profile.php")
if grep -q 'id="wpssb-admin-css"' <<< "$OTHER"; then echo "plugin stylesheet leaked into WordPress dashboard"; exit 1; fi
if grep -q 'secret' <<< "$PAGE"; then echo "password leaked in page"; exit 1; fi
NONCE=$(grep -o 'var WPSSB = {[^;]*' <<< "$PAGE" | grep -o '"nonce":"[a-f0-9]*"' | head -1 | sed 's/.*:"//;s/"$//')
[ -n "$NONCE" ] || { echo "nonce not found"; exit 1; }

ajax() { curl -fsS -c $J -b $J --data-urlencode "action=$1" --data-urlencode "nonce=$NONCE" "${@:2}" $B/wp-admin/admin-ajax.php; }

ajax wpssb_status | grep -q '"success":true' || { echo "status failed"; exit 1; }
ajax wpssb_test --data-urlencode 'wpssb[host]=sftp' --data-urlencode 'wpssb[port]=22' --data-urlencode 'wpssb[username]=backup' \
  --data-urlencode 'wpssb[auth]=password' --data-urlencode 'wpssb[password]=secret' --data-urlencode 'wpssb[fingerprint]=' \
  --data-urlencode 'wpssb[remote_path]=upload/wp-backups' | tee /tmp/test1.json | grep -q '"fingerprint":"SHA256:' || { cat /tmp/test1.json; echo "unknown-host response missing fingerprint"; exit 1; }
grep -q '"success":false' /tmp/test1.json || { echo "unknown host must not succeed"; exit 1; }
FP=$(grep -o '"fingerprint":"SHA256:[^"]*"' /tmp/test1.json | sed 's/.*:"//;s/"$//;s#\\/#/#g')
ajax wpssb_test --data-urlencode 'wpssb[host]=sftp' --data-urlencode 'wpssb[port]=22' --data-urlencode 'wpssb[username]=backup' \
  --data-urlencode 'wpssb[auth]=password' --data-urlencode 'wpssb[password]=secret' --data-urlencode "wpssb[fingerprint]=$FP" \
  --data-urlencode 'wpssb[remote_path]=upload/wp-backups' | grep -q '"success":true' || { echo "test with trusted fingerprint failed"; exit 1; }
ajax wpssb_test --data-urlencode 'wpssb[host]=sftp' --data-urlencode 'wpssb[username]=backup' --data-urlencode 'wpssb[auth]=password' \
  --data-urlencode 'wpssb[password]=wrong' --data-urlencode "wpssb[fingerprint]=$FP" | grep -q 'authentication failed' || { echo "wrong password not reported"; exit 1; }
LIST=$(ajax wpssb_list)
grep -q '"success":true' <<< "$LIST" || { echo "$LIST"; echo "list failed"; exit 1; }
echo "remote backups listed: $(echo "$LIST" | grep -o '"complete":true' | wc -l)"
curl -sS -o /dev/null -w '%{http_code}' --data-urlencode action=wpssb_status $B/wp-admin/admin-ajax.php | grep -q '^400$\|^403$' || { echo "anonymous ajax not rejected"; exit 1; }
curl -sS -o /dev/null -w '%{http_code}' --data-urlencode action=wpssb_bg --data-urlencode job=x --data-urlencode token=y $B/wp-admin/admin-ajax.php | grep -q '^403$' || { echo "bg endpoint without token not rejected"; exit 1; }
echo "admin checks passed"
