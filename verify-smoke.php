<?php

/**
 * @file
 * Checks the smoke-test data and configuration through Drupal.
 *
 *   ddev drush --uri=https://drupal-civi-composer-exp-d11.ddev.site \
 *     php:script scripts/smoke/verify-smoke.php
 *
 * Read-only except for section 5, which creates, updates and deletes one
 * record of its own and restores the one contact value it changes.
 */

use Civi\Api4\Contact;
use Drupal\civicrm_entity\CustomRecord\CustomRecordTypeRegistry;
use Drupal\Core\Form\FormState;
use Drupal\Core\Render\RenderContext;
use Drupal\views\Views;

\Drupal::service('civicrm')->initialize();
$etm = \Drupal::entityTypeManager();
$renderer = \Drupal::service('renderer');
$sql = static fn(string $query) => \CRM_Core_DAO::singleValueQuery($query);
$pass = 0;
$fail = [];
$check = static function (bool $ok, string $label, string $detail = '') use (&$pass, &$fail): void {
  $ok ? $pass++ : $fail[] = $label . ($detail ? " ($detail)" : '');
  printf("  [%s] %s%s\n", $ok ? 'ok' : 'FAIL', $label, $detail ? " -- $detail" : '');
};
$render = static function ($build) use ($renderer): string {
  return (string) $renderer->executeInRenderContext(new RenderContext(), static fn() => $renderer->renderInIsolation($build));
};
$groups = [];
foreach (\CRM_Core_BAO_CustomGroup::getAll(['is_multiple' => TRUE, 'is_active' => TRUE]) as $group) {
  if (!empty($group['fields'])) {
    $groups[$group['name']] = $group['table_name'];
  }
}

echo "1. record types: counts, loading, rendering\n";
foreach ($groups as $name => $table) {
  $type = CustomRecordTypeRegistry::typeIdForName($name);
  $expected = (int) $sql("SELECT COUNT(*) FROM $table");
  $count = (int) $etm->getStorage($type)->getQuery()->accessCheck(FALSE)->count()->execute();
  $check($count === $expected, "$type entity query count", "$count vs $expected in $table");
  $id = $sql("SELECT MIN(id) FROM $table");
  $record = $etm->getStorage($type)->load($id);
  $check($record !== NULL && (int) $record->get('entity_id')->target_id === (int) $sql("SELECT entity_id FROM $table WHERE id = $id"), "$type #$id loads with its parent");
  foreach (['default', 'ce_compact'] as $mode) {
    try {
      $html = $render($etm->getViewBuilder($type)->view($record, $mode));
      $check(strlen($html) > 100, "$type #$id renders in $mode", strlen($html) . ' bytes');
    }
    catch (\Throwable $e) {
      $check(FALSE, "$type #$id renders in $mode", get_class($e) . ': ' . $e->getMessage());
    }
  }
}

echo "\n2. parent multi-record fields (contact 28, event 1, organization 47)\n";
$parentChecks = [
  ['civicrm_contact', 28, 'ce_education', 'school'],
  ['civicrm_contact', 28, 'ce_skills', 'skill'],
  ['civicrm_contact', 28, 'ce_skills', 'endorsed_by'],
  ['civicrm_contact', 47, 'ce_board_terms', 'member'],
  ['civicrm_contact', 47, 'ce_board_terms', 'role'],
  ['civicrm_event', 1, 'ce_event_sessions', 'session_title'],
];
foreach ($parentChecks as [$entityType, $id, $group, $fieldName]) {
  $field = \Civi\Api4\CustomField::get(FALSE)->addSelect('id', 'column_name')->addWhere('custom_group_id:name', '=', $group)->addWhere('name', '=', $fieldName)->execute()->first();
  $expected = (int) $sql("SELECT COUNT({$field['column_name']}) FROM {$groups[$group]} WHERE entity_id = $id");
  $entity = $etm->getStorage($entityType)->loadUnchanged($id);
  $items = $entity->get('custom_' . $field['id']);
  $values = array_column($items->getValue(), $items->getFieldDefinition()->getType() === 'entity_reference' ? 'target_id' : 'value');
  $check(count($values) === $expected, "$entityType $id custom_{$field['id']} ($group.$fieldName) lists every record", count($values) . " vs $expected: " . implode(', ', array_slice($values, 0, 4)));
}
$contact = $etm->getStorage('civicrm_contact')->loadUnchanged(28);
$html = $render($etm->getViewBuilder('civicrm_contact')->view($contact, 'default'));
$check(str_contains($html, 'Education') || str_contains($html, 'School'), 'contact 28 default display renders multi-record fields', strlen($html) . ' bytes');

echo "\n3. single-record custom fields: Drupal value vs API4\n";
$api = Contact::get(FALSE)->addSelect('ce_profile_all.*')->addWhere('id', '=', 28)->execute()->first();
foreach (\Civi\Api4\CustomField::get(FALSE)->addSelect('id', 'name')->addWhere('custom_group_id:name', '=', 'ce_profile_all')->execute() as $field) {
  $items = $contact->get('custom_' . $field['id']);
  $property = $items->getFieldDefinition()->getType() === 'entity_reference' ? 'target_id' : 'value';
  $drupal = array_column($items->getValue(), $property);
  $civi = $api['ce_profile_all.' . $field['name']] ?? NULL;
  $civi = $civi === NULL || $civi === '' ? [] : (array) $civi;
  // Datetimes: Drupal stores UTC, CiviCRM the site's local time; compare
  // them as instants, in local time to the minute.
  if ($items->getFieldDefinition()->getType() === 'datetime' && $items->getFieldDefinition()->getSetting('datetime_type') === 'datetime') {
    $drupal = array_map(static fn($x) => (new \DateTime($x, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i'), $drupal);
  }
  $norm = static fn(array $v) => array_map(static fn($x) => is_bool($x) ? (int) $x : preg_replace('/^(\d{4}-\d\d-\d\d)[ T](\d\d:\d\d).*/', '$1 $2', (string) $x), $v);
  $a = $norm($drupal);
  $b = $norm($civi);
  sort($a);
  sort($b);
  $check($a == $b, "custom_{$field['id']} {$field['name']}", json_encode($drupal) . ' vs api4 ' . json_encode($civi));
}

echo "\n4. views: totals vs the database\n";
$expectations = [
  ['ce_smoke_education', 'page_1', [], (int) $sql("SELECT COUNT(*) FROM {$groups['ce_education']}")],
  ['ce_smoke_education', 'block_contact', ['28'], (int) $sql("SELECT COUNT(*) FROM {$groups['ce_education']} WHERE entity_id = 28")],
  ['ce_smoke_skills', 'page_1', [], (int) $sql("SELECT COUNT(*) FROM {$groups['ce_skills']} s JOIN civicrm_contact c ON c.id = s.entity_id")],
  ['ce_smoke_skills_summary', 'page_1', [], (int) $sql("SELECT COUNT(DISTINCT entity_id) FROM {$groups['ce_skills']}")],
  ['ce_smoke_board_terms', 'page_1', [], (int) $sql("SELECT COUNT(*) FROM {$groups['ce_board_terms']}")],
  ['ce_smoke_contact_directory', 'page_1', [], (int) $sql('SELECT COUNT(*) FROM civicrm_value_profile_all_f_12 WHERE text_56 IS NOT NULL')],
  ['ce_smoke_event_sessions', 'page_1', [], (int) $sql("SELECT COUNT(*) FROM {$groups['ce_event_sessions']}")],
  ['ce_smoke_event_sessions', 'block_event', ['1'], (int) $sql("SELECT COUNT(*) FROM {$groups['ce_event_sessions']} WHERE entity_id = 1")],
  ['ce_smoke_vehicles', 'page_1', [], (int) $sql("SELECT COUNT(*) FROM {$groups['ce_vehicles']}")],
  ['ce_smoke_memberships_history', 'page_1', [], (int) $sql("SELECT COUNT(*) FROM {$groups['ce_professional_memberships_history']}")],
];
foreach ($expectations as [$viewId, $displayId, $args, $expected]) {
  $view = Views::getView($viewId);
  $view->setDisplay($displayId);
  $view->setArguments($args);
  $view->get_total_rows = TRUE;
  try {
    $renderer->executeInRenderContext(new RenderContext(), static fn() => $view->execute());
    $total = (int) ($view->total_rows ?? count($view->result));
    $check($total === $expected, "$viewId:$displayId" . ($args ? '(' . implode(',', $args) . ')' : ''), "$total vs $expected");
    if ($total !== $expected) {
      printf("      SQL: %s\n", preg_replace('/\s+/', ' ', (string) $view->build_info['query']));
    }
  }
  catch (\Throwable $e) {
    $check(FALSE, "$viewId:$displayId", get_class($e) . ': ' . $e->getMessage());
  }
}

echo "\n5. writes through Drupal\n";
$educationType = CustomRecordTypeRegistry::typeIdForName('ce_education');
$storage = $etm->getStorage($educationType);
$schoolField = 'custom_' . \Civi\Api4\CustomField::get(FALSE)->addSelect('id')->addWhere('custom_group_id:name', '=', 'ce_education')->addWhere('name', '=', 'school')->execute()->first()['id'];
$record = $storage->create(['entity_id' => 28, $schoolField => 'Smoke Test Academy']);
// Validating a reference needs a user who may view its target (punch list
// J8); drush runs as anonymous.
\Drupal::service('account_switcher')->switchTo(\Drupal\user\Entity\User::load(1));
$violations = $record->validate();
\Drupal::service('account_switcher')->switchBack();
$check(count($violations) === 0, 'new education record validates', (string) $violations);
try {
  $record->save();
  $id = $record->id();
  $check(!empty($id) && (int) $sql("SELECT COUNT(*) FROM {$groups['ce_education']} WHERE id = $id AND school_7 = 'Smoke Test Academy'") === 1, 'create writes the CiviCRM row', "id $id");
  $record = $storage->loadUnchanged($id);
  $record->set($schoolField, 'Smoke Test Academy (renamed)')->save();
  $check($sql("SELECT school_7 FROM {$groups['ce_education']} WHERE id = $id") === 'Smoke Test Academy (renamed)', 'update writes the CiviCRM row');
  $storage->loadUnchanged($id)->delete();
  $check((int) $sql("SELECT COUNT(*) FROM {$groups['ce_education']} WHERE id = $id") === 0, 'delete removes the CiviCRM row');
}
catch (\Throwable $e) {
  $check(FALSE, 'education record create/update/delete', get_class($e) . ': ' . $e->getMessage());
}

$profileText = 'custom_' . \Civi\Api4\CustomField::get(FALSE)->addSelect('id')->addWhere('custom_group_id:name', '=', 'ce_profile_all')->addWhere('name', '=', 'text')->execute()->first()['id'];
$contact = $etm->getStorage('civicrm_contact')->loadUnchanged(28);
$original = $contact->get($profileText)->value;
try {
  $contact->set($profileText, 'Changed by verify-smoke')->save();
  $now = Contact::get(FALSE)->addSelect('ce_profile_all.text')->addWhere('id', '=', 28)->execute()->first()['ce_profile_all.text'];
  $check($now === 'Changed by verify-smoke', 'contact single-record field saves through Drupal', (string) $now);
}
catch (\Throwable $e) {
  $check(FALSE, 'contact single-record field saves through Drupal', get_class($e) . ': ' . $e->getMessage());
}
finally {
  Contact::update(FALSE)->addWhere('id', '=', 28)->addValue('ce_profile_all.text', $original)->execute();
}

echo "\n6. record constraints\n";
$skillsType = CustomRecordTypeRegistry::typeIdForName('ce_skills');
$fullContact = (int) $sql("SELECT entity_id FROM {$groups['ce_skills']} GROUP BY entity_id HAVING COUNT(*) >= 5 LIMIT 1");
$skillField = 'custom_' . \Civi\Api4\CustomField::get(FALSE)->addSelect('id')->addWhere('custom_group_id:name', '=', 'ce_skills')->addWhere('name', '=', 'skill')->execute()->first()['id'];
$violations = $etm->getStorage($skillsType)->create(['entity_id' => $fullContact, $skillField => 'One too many'])->validate();
$check(count($violations) > 0, "6th skill for contact $fullContact (max_multiple 5) is refused", (string) $violations->get(0)?->getMessage());

$organization = (int) $sql("SELECT id FROM civicrm_contact WHERE contact_type = 'Organization' AND is_deleted = 0 LIMIT 1");
$violations = $storage->create(['entity_id' => $organization, $schoolField => 'Wrong parent'])->validate();
$check(count($violations) > 0, "education record (extends Individual) on organization $organization is refused", (string) $violations->get(0)?->getMessage());

$vehiclesType = CustomRecordTypeRegistry::typeIdForName('ce_vehicles');
$makeField = 'custom_' . \Civi\Api4\CustomField::get(FALSE)->addSelect('id')->addWhere('custom_group_id:name', '=', 'ce_vehicles')->addWhere('name', '=', 'make')->execute()->first()['id'];
$nonStaff = (int) $sql("SELECT id FROM civicrm_contact WHERE contact_type = 'Individual' AND is_deleted = 0 AND (contact_sub_type IS NULL OR contact_sub_type NOT LIKE '%Staff%') LIMIT 1");
$violations = $etm->getStorage($vehiclesType)->create(['entity_id' => $nonStaff, $makeField => 'Wrong subtype'])->validate();
$check(count($violations) > 0, "vehicle (extends Individual/Staff) on non-staff individual $nonStaff is refused", (string) $violations->get(0)?->getMessage());

$fullStaff = (int) $sql("SELECT entity_id FROM {$groups['ce_vehicles']} GROUP BY entity_id HAVING COUNT(*) >= 2 LIMIT 1");
$violations = $etm->getStorage($vehiclesType)->create(['entity_id' => $fullStaff, $makeField => 'Third car'])->validate();
$check(count($violations) > 0, "3rd vehicle for staff $fullStaff (max_multiple 2) is refused", (string) $violations->get(0)?->getMessage());

echo "\n7. forms use their form modes\n";
$formBuilder = \Drupal::service('entity.form_builder');
foreach ([['add', $storage->create(['entity_id' => 28])], ['edit', $storage->load($sql("SELECT MIN(id) FROM {$groups['ce_education']}"))], ['edit', $etm->getStorage('civicrm_contact')->load(28)]] as [$operation, $entity]) {
  $form = $formBuilder->getForm($entity, $operation);
  $fields = array_values(array_filter(array_keys($form), static fn($k) => is_string($k) && ($k === 'entity_id' || str_starts_with($k, 'custom_') || in_array($k, ['first_name', 'last_name', 'display_name', 'contact_type'], TRUE)) && ($form[$k]['#access'] ?? TRUE)));
  $display = \Drupal\Core\Entity\Entity\EntityFormDisplay::collectRenderDisplay($entity, $operation);
  // A form mode is used when its display exists, else the default display.
  $mode = \Drupal\Core\Entity\Entity\EntityFormDisplay::load($entity->getEntityTypeId() . '.' . $entity->bundle() . '.' . $operation) ? $operation : 'default';
  $check(count($fields) === count(array_intersect($fields, array_keys($display->getComponents()))) && $display->getMode() === $mode, $entity->getEntityTypeId() . " $operation form uses form mode '$mode'", count($fields) . ' fields: ' . implode(',', array_slice($fields, 0, 8)) . (count($fields) > 8 ? ',...' : ''));
}

printf("\n%d passed, %d failed\n", $pass, count($fail));
foreach ($fail as $f) {
  echo "  - $f\n";
}
