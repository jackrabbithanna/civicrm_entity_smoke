#!/usr/bin/env bash
# Punch list J1: ce_vehicles gone and back without CiviCRM's API. Deactivates
# it in SQL and runs CiviCRM's system rebuild twice, then reactivates it in SQL
# and runs a single `drush cr`, checking the type, its routes and its pages
# after each. Then J29: disabled and enabled through API4, with the routes
# left as they are. Leaves the group active.
set -uo pipefail
cd "$(dirname "$0")/../.."
URI=https://drupal-civi-composer-exp-d11.ddev.site
drush() { ddev drush --uri="$URI" "$@"; }
status=0
drush php:script scripts/smoke/j1-smoke.php gone || status=1
drush php:script scripts/smoke/j1-smoke.php back || status=1
echo "== drush cr"
drush cr 2>&1 | tail -n 1
drush php:script scripts/smoke/j1-smoke.php check || status=1
drush php:script scripts/smoke/j1-smoke.php off || status=1
drush php:script scripts/smoke/j1-smoke.php on || status=1
exit $status
