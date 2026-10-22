#!/bin/sh
# Lists files created/modified on the source server since the marker (should be none during a backup).
find /var/www/html /tmp -type f -newer /tmp/wpssb-marker 2>/dev/null | grep -v '^/tmp/admin-cookies' | head
