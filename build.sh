#!/usr/bin/env bash
# Builds (or rebuilds) the CiviCRM Entity multi-record smoke-test fixture.
#
#   scripts/smoke/build.sh            seed + configure + verify
#   scripts/smoke/build.sh --reinstall-civicrm
#                                     first drop CiviCRM and reinstall it with
#                                     the generated sample data (DESTRUCTIVE:
#                                     all CiviCRM data is replaced)
#
# Every step is idempotent. Run from the project root.
set -euo pipefail
cd "$(dirname "$0")/../.."
URI=https://drupal-civi-composer-exp-d11.ddev.site
drush() { ddev drush --uri="$URI" "$@"; }

if [[ "${1:-}" == "--reinstall-civicrm" ]]; then
  # Record types stay installed through the reinstall: CiviCRM's system
  # rebuilds while it enables its extensions redefine them (punch list J1), and
  # the `drush cr` after it forgets them.
  echo "== Reinstalling CiviCRM with sample data"
  drush php:eval '\Drupal::service("civicrm")->initialize(); \Drupal::moduleHandler()->loadInclude("civicrm", "install"); _civicrm_setup()->uninstallDatabase(); echo "dropped\n";'
  drush php:script scripts/smoke/civi-reinstall-sample.php
  drush cr
fi

echo "== Seeding CiviCRM custom groups and values"
drush php:script scripts/smoke/civi-seed-custom-data.php

echo "== CiviCRM Entity settings"
drush php:script scripts/smoke/ce-settings.php
drush cr

echo "== Drupal configuration"
drush php:script scripts/smoke/drupal-smoke-config.php
drush cr

echo "== Verifying"
drush php:script scripts/smoke/verify-smoke.php
