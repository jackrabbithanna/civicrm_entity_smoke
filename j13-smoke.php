<?php

/**
 * @file
 * Punch list J13: emptying a field and saving clears it in CiviCRM.
 *
 *   ddev drush --uri=https://drupal-civi-composer-exp-d11.ddev.site \
 *     php:script scripts/smoke/j13-smoke.php
 *
 * Writes, then restores what it changed through API4 (also on failure):
 *
 * 1. A contact holding a value in every ce_profile_all field: each field but
 *    the booleans (which a form cannot empty), the file (clearing it makes
 *    CiviCRM delete the file, which no restore brings back) and one kept as a
 *    control, and four core fields, are emptied through the entity API and
 *    saved. CiviCRM must hold none of them, and the control and the name
 *    must be unchanged. In api3 every contact save also adds a record per
 *    multi-record group (punch list I3); the records it adds are deleted.
 * 2. An education record (auto and api4_native): its honors, end date and
 *    notes are emptied and saved; the school is kept.
 * 3. A save of the reloaded contact changes nothing more.
 */

use Civi\Api4\Contact;
use Civi\Api4\CustomValue;
use Drupal\civicrm_entity\CustomRecord\CustomRecordTypeRegistry;

\Drupal::service('civicrm')->initialize();
$etm = \Drupal::entityTypeManager();
$mode = \Drupal::config('civicrm_entity.settings')->get('api_backend');
$pass = 0;
$fail = [];
$check = static function (bool $ok, string $label, string $detail = '') use (&$pass, &$fail): void {
  $ok ? $pass++ : $fail[] = $label . ($detail ? " ($detail)" : '');
  printf("  [%s] %s%s\n", $ok ? 'ok' : 'FAIL', $label, $detail ? " -- $detail" : '');
};
$blank = static fn($value) => $value === NULL || $value === '' || $value === [] || $value === [''];

$fields = \Civi\Api4\CustomField::get(FALSE)
  ->addSelect('id', 'name', 'data_type', 'html_type', 'serialize', 'column_name')
  ->addWhere('custom_group_id.name', '=', 'ce_profile_all')
  ->addWhere('is_active', '=', TRUE)
  ->execute()
  ->indexBy('name');
$control = 'hidden';
$emptied = array_filter(array_keys($fields->getArrayCopy()), static fn($name) => !in_array($fields[$name]['data_type'], ['Boolean', 'File'], TRUE) && $name !== $control);
$core = ['middle_name', 'job_title', 'birth_date', 'preferred_communication_method'];
$select = array_merge(['first_name', 'last_name', "ce_profile_all.$control"], $core, array_map(static fn($name) => "ce_profile_all.$name", $emptied));

// A profile contact holding every field to be emptied.
$where = Contact::get(FALSE)->addSelect('id', ...$select)->addWhere('contact_type', '=', 'Individual');
foreach ($emptied as $name) {
  $where->addWhere("ce_profile_all.$name", 'IS NOT EMPTY');
}
$snapshot = $where->setLimit(1)->execute()->first();
if (!$snapshot) {
  echo "No individual holds every ce_profile_all field; reseed the fixture.\n";
  exit(1);
}
$contactId = (int) $snapshot['id'];
$restoreContact = static function () use ($contactId, $snapshot, $core, $emptied, $control): void {
  $values = array_intersect_key($snapshot, array_flip(array_merge($core, array_map(static fn($name) => "ce_profile_all.$name", array_merge($emptied, [$control])))));
  Contact::update(FALSE)->addWhere('id', '=', $contactId)->setValues($values)->execute();
};
register_shutdown_function($restoreContact);
// The records an api3 save adds (I3), removed again.
$multiRecordGroups = \Civi\Api4\CustomGroup::get(FALSE)->addSelect('name', 'table_name')->addWhere('is_multiple', '=', TRUE)->addWhere('is_active', '=', TRUE)->execute()->column('table_name', 'name');
$lastRecord = array_map(static fn($table) => (int) \CRM_Core_DAO::singleValueQuery("SELECT MAX(id) FROM $table"), $multiRecordGroups);
$removeAdded = static function () use ($multiRecordGroups, $lastRecord, $contactId): int {
  $removed = 0;
  foreach ($multiRecordGroups as $group => $table) {
    $removed += CustomValue::delete($group, FALSE)->addWhere('entity_id', '=', $contactId)->addWhere('id', '>', $lastRecord[$group])->execute()->count();
  }
  return $removed;
};
register_shutdown_function($removeAdded);

echo "1. contact $contactId in $mode: core fields and every ce_profile_all field emptied\n";
foreach ($core as $field) {
  if ($blank($snapshot[$field])) {
    // Not every sample contact has every core field; give it one first.
    Contact::update(FALSE)->addWhere('id', '=', $contactId)->addValue($field, match ($field) {
      'birth_date' => '1980-02-03',
      'preferred_communication_method' => [1, 2],
      default => "Smoke $field",
    })->execute();
  }
}
$storage = $etm->getStorage('civicrm_contact');
$storage->resetCache([$contactId]);
$contact = $storage->load($contactId);
foreach ($core as $field) {
  $contact->set($field, NULL);
}
foreach ($emptied as $name) {
  $contact->set('custom_' . $fields[$name]['id'], NULL);
}
try {
  $contact->save();
  $stored = Contact::get(FALSE)->addSelect(...$select)->addWhere('id', '=', $contactId)->execute()->first();
  foreach ($core as $field) {
    $check($blank($stored[$field]), "$field cleared", json_encode($stored[$field]));
  }
  foreach ($emptied as $name) {
    $label = "ce_profile_all.$name ({$fields[$name]['data_type']}/{$fields[$name]['html_type']})";
    // API3 has no value that clears a multi-value Country or StateProvince
    // field (CRM-3415), so api3 leaves them alone.
    if ($mode === 'api3' && in_array($fields[$name]['data_type'], ['Country', 'StateProvince'], TRUE) && !empty($fields[$name]['serialize'])) {
      $check($stored["ce_profile_all.$name"] === $snapshot["ce_profile_all.$name"], "$label kept in api3, which cannot clear it", json_encode($stored["ce_profile_all.$name"]));
    }
    else {
      $check($blank($stored["ce_profile_all.$name"]), "$label cleared", json_encode($stored["ce_profile_all.$name"]));
    }
  }
  $check($stored["ce_profile_all.$control"] === $snapshot["ce_profile_all.$control"], "ce_profile_all.$control (control) kept", json_encode($stored["ce_profile_all.$control"]));
  $check([$stored['first_name'], $stored['last_name']] === [$snapshot['first_name'], $snapshot['last_name']], 'name kept');

  echo "\n3. saved again, nothing changes\n";
  $storage->resetCache([$contactId]);
  $storage->load($contactId)->save();
  $again = Contact::get(FALSE)->addSelect(...$select)->addWhere('id', '=', $contactId)->execute()->first();
  // In api3 the kept multi-value Country and StateProvince fields lose all
  // but their first value on every save: punch list J14, not this check's.
  if ($mode === 'api3') {
    unset($again['ce_profile_all.countries'], $stored['ce_profile_all.countries'], $again['ce_profile_all.states'], $stored['ce_profile_all.states']);
  }
  $check($again === $stored, 'second save changes nothing', json_encode(array_diff_key(array_map('json_encode', $again), array_intersect_assoc(array_map('json_encode', $again), array_map('json_encode', $stored)))));
}
catch (\Throwable $e) {
  $check(FALSE, 'contact save', get_class($e) . ': ' . $e->getMessage());
}
$restoreContact();
$restored = Contact::get(FALSE)->addSelect(...$select)->addWhere('id', '=', $contactId)->execute()->first();
$check($restored == $snapshot, 'contact restored');
$removed = $removeAdded();
$check($mode === 'api3' || $removed === 0, 'no record added outside api3', "$removed removed");

if ($mode !== 'api3') {
  echo "\n2. an education record in $mode\n";
  $record = CustomValue::get('ce_education', FALSE)->addWhere('honors', 'IS NOT EMPTY')->addWhere('end_date', 'IS NOT EMPTY')->addWhere('notes', 'IS NOT EMPTY')->setLimit(1)->execute()->first();
  if (!$record) {
    $check(FALSE, 'an education record with honors, end date and notes');
  }
  else {
    register_shutdown_function(static fn() => CustomValue::update('ce_education', FALSE)->addWhere('id', '=', $record['id'])->setValues(array_intersect_key($record, array_flip(['honors', 'end_date', 'notes'])))->execute());
    $type = CustomRecordTypeRegistry::typeIdForName('ce_education');
    $recordStorage = $etm->getStorage($type);
    $recordStorage->resetCache([$record['id']]);
    $entity = $recordStorage->load($record['id']);
    $columns = \Civi\Api4\CustomField::get(FALSE)->addSelect('id', 'name')->addWhere('custom_group_id.name', '=', 'ce_education')->execute()->column('id', 'name');
    foreach (['honors', 'end_date', 'notes'] as $name) {
      $entity->set('custom_' . $columns[$name], NULL);
    }
    try {
      $entity->save();
      $stored = CustomValue::get('ce_education', FALSE)->addWhere('id', '=', $record['id'])->execute()->first();
      foreach (['honors', 'end_date', 'notes'] as $name) {
        $check($blank($stored[$name]), "record {$record['id']} $name cleared", json_encode($stored[$name]));
      }
      $check($stored['school'] === $record['school'], "record {$record['id']} school kept", json_encode($stored['school']));
    }
    catch (\Throwable $e) {
      $check(FALSE, 'record save', get_class($e) . ': ' . $e->getMessage());
    }
    CustomValue::update('ce_education', FALSE)->addWhere('id', '=', $record['id'])->setValues(array_intersect_key($record, array_flip(['honors', 'end_date', 'notes'])))->execute();
  }
}

printf("\n%s: %d passed, %d failed\n", $mode, $pass, count($fail));
foreach ($fail as $f) {
  echo "  - $f\n";
}
if ($fail) {
  exit(1);
}
