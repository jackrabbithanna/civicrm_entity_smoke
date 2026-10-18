#!/usr/bin/env bash
# Returns the site to its pre-submodule baseline: removes the smoke
# configuration, uninstalls the six CiviCRM Entity submodules, then their
# contrib dependencies, and checks the base smoke checks still pass.
#
#   scripts/smoke/submodules-disable.sh
#
# The contrib packages stay in composer; only the modules are uninstalled.
# Run from the project root.
set -uo pipefail
cd "$(dirname "$0")/../.."
URI=https://drupal-civi-composer-exp-d11.ddev.site
drush() { ddev drush --uri="$URI" "$@"; }
failed=()

echo "== Removing smoke configuration"
drush php:script scripts/smoke/submodules-cleanup.php || failed+=("cleanup")

# Dependents before what they depend on: the CiviCRM Entity submodules, then
# bundles (civicrm_entity_ds needs it), then contrib.
steps=(
  "civicrm_entity_vbo civicrm_entity_search_api civicrm_entity_rules civicrm_entity_field_group civicrm_entity_ds"
  "civicrm_entity_bundles"
  "views_bulk_operations search_api_db search_api rules typed_data field_group ds"
)
for step in "${steps[@]}"; do
  echo "== Uninstalling $step"
  # shellcheck disable=SC2086
  drush pmu -y $step || failed+=("$step")
done

echo "== Cache rebuild"
drush cr || failed+=("cr")

echo "== Pending entity definition updates"
drush php:eval 'foreach (\Drupal::entityDefinitionUpdateManager()->getChangeSummary() as $type => $changes) { foreach ($changes as $change) { echo "$type: ", strip_tags((string) $change), "\n"; } } echo "(end)\n";'

if ((${#failed[@]})); then
  echo "FAILED: ${failed[*]}"
  exit 1
fi

echo "== Base smoke checks"
drush php:script scripts/smoke/verify-smoke.php
