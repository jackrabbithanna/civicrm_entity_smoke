<?php

/**
 * @file
 * Exposes the smoke-test entity types in CiviCRM Entity's settings.
 *
 *   ddev drush --uri=https://drupal-civi-composer-exp-d11.ddev.site \
 *     php:script scripts/smoke/ce-settings.php
 *
 * Adds to whatever is already enabled; never changes api_backend. Needs a
 * cache rebuild afterwards: exposing a type changes its routes and handlers.
 * Multi-record record types are not listed: they follow the type their group
 * extends (contact, event).
 */

$types = [
  'civicrm_contact',
  'civicrm_event',
  'civicrm_activity',
  'civicrm_participant',
  'civicrm_contribution',
  'civicrm_membership',
  'civicrm_address',
  'civicrm_email',
  'civicrm_phone',
];

$config = \Drupal::configFactory()->getEditable('civicrm_entity.settings');
$enabled = array_values(array_unique(array_merge($config->get('enabled_entity_types') ?? [], $types)));
$links = $config->get('enable_links_per_type') ?? [];
foreach ($types as $type) {
  $links[$type] ??= ['values' => ['view' => 'view', 'add' => 'add', 'edit' => 'edit', 'delete' => 'delete']];
}
$config->set('enabled_entity_types', $enabled)->set('enable_links_per_type', $links);
// Rich text from CiviCRM renders through this format; empty means the
// fallback (plain text), which shows the HTML escaped.
if (!$config->get('filter_format')) {
  $config->set('filter_format', 'basic_html');
}
$config->save();

printf("api_backend: %s\nfilter_format: %s\nenabled: %s\n", $config->get('api_backend'), $config->get('filter_format'), implode(', ', $enabled));
