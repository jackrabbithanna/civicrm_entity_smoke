#!/usr/bin/env bash
# Switches api_backend native -> auto -> api3 -> native the documented way
# (set the backend, cr, config-rewrite --apply) and checks the site in each.
# Exports configuration before and after, so the round trip can be diffed:
#   diff -r scripts/smoke/output/config-before scripts/smoke/output/config-after
set -uo pipefail
cd "$(dirname "$0")/../.."
URI=https://drupal-civi-composer-exp-d11.ddev.site
drush() { ddev drush --uri="$URI" "$@"; }
OUT=scripts/smoke/output
mkdir -p "$OUT"
rm -rf "$OUT/config-before" "$OUT/config-after"
drush config:export --destination=/var/www/html/$OUT/config-before -y >/dev/null

prev=$(drush cget civicrm_entity.settings api_backend --format=string)
for mode in auto api3 api4_native; do
  echo
  echo "################ $prev -> $mode"
  drush cset civicrm_entity.settings api_backend "$mode" -y >/dev/null
  drush cr >/dev/null
  echo "== config-rewrite --from=$prev --apply"
  drush civicrm-entity:config-rewrite --from="$prev" --apply -y > "$OUT/rewrite-$prev-$mode.txt" 2>&1
  echo "exit $?; $(wc -l < "$OUT/rewrite-$prev-$mode.txt") lines in $OUT/rewrite-$prev-$mode.txt"
  tail -n 6 "$OUT/rewrite-$prev-$mode.txt"
  drush cr >/dev/null
  drush php:script scripts/smoke/modes-smoke.php
  for check in j2 j3 j31 j4 j13 j34 j35 j38 j6 j37; do
    echo "== $check-smoke"
    drush php:script scripts/smoke/$check-smoke.php | grep -E 'FAIL|passed'
  done
  if [[ "$mode" != api3 ]]; then
    echo "== verify-smoke"
    drush php:script scripts/smoke/verify-smoke.php | grep -E 'FAIL|passed'
  fi
  if [[ "$mode" == auto ]]; then
    echo "== browser-smoke"
    drush php:script scripts/smoke/browser-smoke.php | grep -vE '^\s+\[ok\]'
  fi
  prev=$mode
done

drush config:export --destination=/var/www/html/$OUT/config-after -y >/dev/null
echo
echo "== configuration changed by the round trip"
diff -rq "$OUT/config-before" "$OUT/config-after" || true
