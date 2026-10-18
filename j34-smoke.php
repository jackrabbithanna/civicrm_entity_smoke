<?php

/**
 * @file
 * Punch list J34: widget and formatter settings against the config schema.
 *
 *   ddev drush --uri=https://drupal-civi-composer-exp-d11.ddev.site \
 *     php:script scripts/smoke/j34-smoke.php
 *
 * Read-only. A long text field put CiviCRM's row count beside its widget's
 * settings (`content.<field>.rows`), where no widget read it and no schema
 * allowed it, and the `civicrm_entity_textarea` widget and the two formatters
 * with settings had no schema.
 *
 * 1. Every saved CiviCRM display and every View passes its config schema.
 * 2. A new default form and view display, built in memory for every enabled
 *    CiviCRM entity type and bundle, passes too. Nothing is saved.
 * 3. The new form displays carry CiviCRM's row counts in the widget settings:
 *    an activity's details (8), an event's summary (4), and the TextArea
 *    custom fields' `note_rows` (4 when empty, as CiviCRM's own forms) where
 *    API4 describes them: in `api4_native`, and on record types in every mode.
 *    The built form uses them.
 */

use Drupal\Core\Config\Schema\SchemaCheckTrait;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Form\FormState;

\Drupal::service('civicrm')->initialize();
$typed = \Drupal::service('config.typed');
$checker = new class {
  use SchemaCheckTrait;
};
$schemaErrors = static function (string $name, array $data) use ($typed, $checker): array {
  $result = $checker->checkConfigSchema($typed, $name, $data);
  return $result === TRUE ? [] : array_map(static fn($k, $v) => "$k: $v", array_keys($result), $result);
};
$pass = 0;
$fail = [];
$check = static function (bool $ok, string $label, string $detail = '') use (&$pass, &$fail): void {
  $ok ? $pass++ : $fail[] = $label . ($detail ? " ($detail)" : '');
  printf("  [%s] %s%s\n", $ok ? 'ok' : 'FAIL', $label, $detail ? " -- $detail" : '');
};
$note = static fn(string $text) => printf("  [--] %s\n", $text);
$mode = \Drupal::config('civicrm_entity.settings')->get('api_backend');
echo "Mode: $mode\n";

echo "\n1. Saved configuration\n";
$factory = \Drupal::configFactory();
$names = array_merge(
  $factory->listAll('core.entity_form_display.civicrm_'),
  $factory->listAll('core.entity_view_display.civicrm_'),
  $factory->listAll('views.view.'),
);
// Errors that belong to the fixture, not the module: its `expose.reduce` on
// filters that are not "in" filters, and its multi-value options on the file
// field, which has none. J35 (Views handlers without a schema of their own) is
// fixed, so their errors count.
$known = [];
foreach ($names as $name) {
  $data = $factory->get($name)->getRawData();
  $errors = [];
  foreach ($schemaErrors($name, $data) as $error) {
    if (preg_match('/^[^:]+:display\.([^.]+)\.display_options\.(\w+)\.([^.]+)\.(\S+)/', $error, $m)) {
      $plugin = $data['display'][$m[1]]['display_options'][$m[2]][$m[3]]['plugin_id'] ?? '';
      if (str_starts_with($m[4], 'expose.reduce')) {
        $known["fixture: expose.reduce on a $plugin filter"][$name] = TRUE;
        continue;
      }
      if ($plugin === 'civicrm_entity_custom_file') {
        $known["fixture: field-API options on a $plugin field"][$name] = TRUE;
        continue;
      }
    }
    $errors[] = $error;
  }
  $check(!$errors, $name, implode('; ', array_slice($errors, 0, 4)) . (count($errors) > 4 ? sprintf('; +%d more', count($errors) - 4) : ''));
}
foreach ($known as $cause => $configs) {
  $note("$cause, not counted: " . implode(', ', array_keys($configs)));
}

echo "\n2. New default displays\n";
$bundleInfo = \Drupal::service('entity_type.bundle.info');
$formDisplays = [];
foreach (\Drupal::entityTypeManager()->getDefinitions() as $entityTypeId => $definition) {
  if (!str_starts_with($entityTypeId, 'civicrm_')) {
    continue;
  }
  foreach (array_keys($bundleInfo->getBundleInfo($entityTypeId)) as $bundle) {
    foreach ([EntityFormDisplay::class => 'core.entity_form_display', EntityViewDisplay::class => 'core.entity_view_display'] as $class => $prefix) {
      $label = "$prefix.$entityTypeId.$bundle.default";
      try {
        $display = $class::create(['targetEntityType' => $entityTypeId, 'bundle' => $bundle, 'mode' => 'default', 'status' => TRUE]);
        $errors = $schemaErrors($label, $display->toArray());
        $check(!$errors, "$label (new)", implode('; ', array_slice($errors, 0, 4)) . (count($errors) > 4 ? sprintf('; +%d more', count($errors) - 4) : ''));
        if ($class === EntityFormDisplay::class && $bundle === $entityTypeId) {
          $formDisplays[$entityTypeId] = $display;
        }
      }
      catch (\Throwable $e) {
        $check(FALSE, "$label (new)", get_class($e) . ': ' . $e->getMessage());
      }
    }
  }
}

echo "\n3. Row counts\n";
$rows = static function (string $entityTypeId, string $field) use ($formDisplays): array {
  $component = isset($formDisplays[$entityTypeId]) ? $formDisplays[$entityTypeId]->getComponent($field) : NULL;
  return [$component['settings']['rows'] ?? NULL, $component ? array_key_exists('rows', $component) : NULL];
};
foreach ([['civicrm_activity', 'details', 8], ['civicrm_event', 'summary', 4]] as [$entityTypeId, $field, $expected]) {
  [$got, $stray] = $rows($entityTypeId, $field);
  $check($got === $expected, "$entityTypeId.$field settings.rows", var_export($got, TRUE) . " vs $expected");
  $check($stray === FALSE, "$entityTypeId.$field has no top-level rows");
}
$memos = \Civi\Api4\CustomField::get(FALSE)
  ->addSelect('id', 'name', 'note_rows', 'custom_group_id', 'custom_group_id.extends', 'custom_group_id.is_multiple')
  ->addWhere('html_type', '=', 'TextArea')
  ->addWhere('is_active', '=', TRUE)
  ->addWhere('custom_group_id.is_active', '=', TRUE)
  ->execute();
$entityTypeIds = [
  'Contact' => 'civicrm_contact',
  'Individual' => 'civicrm_contact',
  'Organization' => 'civicrm_contact',
  'Event' => 'civicrm_event',
  'Activity' => 'civicrm_activity',
  'Contribution' => 'civicrm_contribution',
];
// A multi-record group's fields are on its record type.
$recordTypes = [];
foreach (\Drupal::entityTypeManager()->getDefinitions() as $entityTypeId => $definition) {
  if ($group = $definition->get('civicrm_custom_record')) {
    $recordTypes[(int) $group['group_id']] = $entityTypeId;
  }
}
foreach ($memos as $memo) {
  $entityTypeId = $memo['custom_group_id.is_multiple']
    ? ($recordTypes[$memo['custom_group_id']] ?? NULL)
    : ($entityTypeIds[$memo['custom_group_id.extends']] ?? NULL);
  if (!$entityTypeId) {
    $note("{$memo['name']}: no entity type known for its group, skipped");
    continue;
  }
  [$got] = $rows($entityTypeId, 'custom_' . $memo['id']);
  // CiviCRM gives a text area without `note_rows` 4 rows, and API4 reports
  // that. API3, which describes the fields in `auto` too, reports no row count
  // for a custom field, so the widget's default applies; a record type is
  // described by API4 in every mode.
  $api4 = $mode === 'api4_native' || $memo['custom_group_id.is_multiple'];
  $expected = $api4 ? (int) ($memo['note_rows'] ?: 4) : 5;
  $check($got === $expected, "$entityTypeId.custom_{$memo['id']} ({$memo['name']}, note_rows " . var_export($memo['note_rows'], TRUE) . ') settings.rows', var_export($got, TRUE) . " vs $expected");
}

// The built form, from the new event display.
$eventStorage = \Drupal::entityTypeManager()->getStorage('civicrm_event');
$ids = $eventStorage->getQuery()->accessCheck(FALSE)->range(0, 1)->execute();
$event = $ids ? $eventStorage->load(reset($ids)) : NULL;
if ($event && isset($formDisplays['civicrm_event'])) {
  $form = ['#parents' => []];
  $formState = new FormState();
  $formDisplays['civicrm_event']->buildForm($event, $form, $formState);
  $check(($form['summary']['widget'][0]['value']['#rows'] ?? NULL) === 4, 'event form: summary textarea has 4 rows', var_export($form['summary']['widget'][0]['value']['#rows'] ?? NULL, TRUE));
}
else {
  $check(FALSE, 'event form: an event and its new display');
}

printf("\n%d passed, %d failed\n", $pass, count($fail));
foreach ($fail as $f) {
  echo "  - $f\n";
}
if ($fail) {
  exit(1);
}
