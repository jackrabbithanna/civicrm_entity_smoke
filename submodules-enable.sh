#!/usr/bin/env bash
# Enables CiviCRM Entity's six submodules and their contrib dependencies, then
# runs the submodule smoke checks and the base smoke checks.
#
#   scripts/smoke/submodules-enable.sh            enable + check
#   scripts/smoke/submodules-enable.sh --no-check enable only
#
# Each group is enabled in its own drush call, so a failure names its module.
# The contrib modules come from composer (drupal/ds, field_group, rules,
# typed_data, search_api, views_bulk_operations). Undo with
# scripts/smoke/submodules-disable.sh. Run from the project root.
set -uo pipefail
cd "$(dirname "$0")/../.."
URI=https://drupal-civi-composer-exp-d11.ddev.site
drush() { ddev drush --uri="$URI" "$@"; }
failed=()

# Bundles first: civicrm_entity_ds requires it, and it changes the event and
# activity entity types the others then see.
steps=(
  "civicrm_entity_bundles"
  "ds civicrm_entity_ds"
  "field_group civicrm_entity_field_group"
  "typed_data rules civicrm_entity_rules"
  "search_api search_api_db civicrm_entity_search_api"
  "views_bulk_operations civicrm_entity_vbo"
)
for step in "${steps[@]}"; do
  echo "== Enabling $step"
  # shellcheck disable=SC2086
  drush en -y $step || failed+=("$step")
done

echo "== Cache rebuild"
drush cr || failed+=("cr")

echo "== Pending entity definition updates"
drush php:eval 'foreach (\Drupal::entityDefinitionUpdateManager()->getChangeSummary() as $type => $changes) { foreach ($changes as $change) { echo "$type: ", strip_tags((string) $change), "\n"; } } echo "(end)\n";'

if ((${#failed[@]})); then
  echo "FAILED: ${failed[*]}"
  exit 1
fi

if [[ "${1:-}" != "--no-check" ]]; then
  echo "== Submodule smoke checks"
  drush php:script scripts/smoke/submodules-smoke.php
  echo "== Base smoke checks"
  drush php:script scripts/smoke/verify-smoke.php
fi
