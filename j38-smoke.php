<?php

/**
 * @file
 * Punch list J38: an unchanged save must not store No over an empty Boolean
 * Radio field.
 *
 *   ddev drush --uri=https://drupal-civi-composer-exp-d11.ddev.site \
 *     php:script scripts/smoke/j38-smoke.php
 *
 * Writes and restores. `ce_profile_all` has a Boolean Radio field
 * (`boolean_radio`) and a Boolean Toggle (`boolean_toggle`) on contacts.
 * CiviCRM shows the first as Yes/No radios that can be cleared, so it keeps an
 * empty value empty, and the second as an `advcheckbox`, which submits 0.
 *
 * 1. The default contact form display, never saved here, gives the Radio
 *    field radios with N/A; the Toggle and a core boolean keep a checkbox.
 * 2. That form, built and submitted unchanged for a contact with no row in the
 *    group's table: the Radio field stays empty, and the Toggle stores 0, as
 *    CiviCRM's own form would.
 * 3. In headless Chromium as admin, the fixture's `edit` form, which saves
 *    checkboxes for both, as a configured 4.0.x site has them:
 *    - a contact with no row, saved unchanged: the Radio field stays empty;
 *    - a contact whose Radio field is empty: saved unchanged, it stays
 *      empty; ticked, it stores 1;
 *    - a contact whose Radio field is 1: unticked, it stores 0;
 *    - a contact whose Radio field is 0: saved unchanged, it stays 0.
 *
 * In `api3` the contact forms also show the required first fields of the
 * multi-record groups (4.0.x's multi-record fields on the parent, I3), so a
 * contact without records cannot be saved through them: the browser blocks
 * the submit. There, 2 and 3 check that the guard is attached to the built
 * forms instead, and kernel BooleanRadioEmptyTest covers the saves.
 *
 * Rows the script adds are deleted, and values it changes are put back
 * through API4, on shutdown.
 */

use Behat\Mink\Driver\Selenium2Driver;
use Behat\Mink\Session;
use Drupal\Core\Form\FormState;
use Drupal\user\Entity\User;

const BASE = 'https://drupal-civi-composer-exp-d11.ddev.site';

\Drupal::service('civicrm')->initialize();
$etm = \Drupal::entityTypeManager();
$pass = 0;
$fail = [];
$session = NULL;
$check = static function (bool $ok, string $label, string $detail = '') use (&$pass, &$fail): void {
  $ok ? $pass++ : $fail[] = $label . ($detail ? " ($detail)" : '');
  printf("  [%s] %s%s\n", $ok ? 'ok' : 'FAIL', $label, $detail ? " -- $detail" : '');
};
$rows = static fn(string $query) => \CRM_Core_DAO::executeQuery($query)->fetchAll();
$mode = \Drupal::config('civicrm_entity.settings')->get('api_backend');
echo "Mode: $mode\n";

$fields = [];
foreach (\Civi\Api4\CustomField::get(FALSE)->addWhere('custom_group_id:name', '=', 'ce_profile_all')->addWhere('name', 'IN', ['boolean_radio', 'boolean_toggle'])->execute() as $field) {
  $fields[$field['name']] = $field + ['drupal' => 'custom_' . $field['id']];
}
$table = \Civi\Api4\CustomGroup::get(FALSE)->addWhere('name', '=', 'ce_profile_all')->execute()->first()['table_name'];
[$radio, $toggle] = [$fields['boolean_radio'], $fields['boolean_toggle']];
// The DAO reads NULL as '', so NULL is spelled out.
$stored = static function (int $contactId) use ($rows, $table, $radio, $toggle): ?array {
  $row = $rows("SELECT IF({$radio['column_name']} IS NULL, 'NULL', {$radio['column_name']}) AS radio, IF({$toggle['column_name']} IS NULL, 'NULL', {$toggle['column_name']}) AS toggle FROM $table WHERE entity_id = $contactId")[0] ?? NULL;
  return $row ? [$row['radio'], $row['toggle']] : NULL;
};

// ---------------------------------------------------------------------------
// Contacts, and what to put back.
// ---------------------------------------------------------------------------
$noRow = static function (int $offset) use ($rows, $table): int {
  return (int) $rows("SELECT c.id FROM civicrm_contact c LEFT JOIN $table p ON p.entity_id = c.id WHERE c.contact_type = 'Individual' AND c.is_deleted = 0 AND c.first_name IS NOT NULL AND c.last_name IS NOT NULL AND p.id IS NULL ORDER BY c.id LIMIT 1 OFFSET $offset")[0]['id'];
};
$holding = static function (string $value, int $offset) use ($rows, $table, $radio): int {
  return (int) $rows("SELECT p.entity_id AS id FROM $table p JOIN civicrm_contact c ON c.id = p.entity_id WHERE c.contact_type = 'Individual' AND c.is_deleted = 0 AND c.first_name IS NOT NULL AND c.last_name IS NOT NULL AND p.{$radio['column_name']} = $value ORDER BY p.entity_id LIMIT 1 OFFSET $offset")[0]['id'];
};
$contacts = [
  'programmatic, no row' => $noRow(0),
  'browser, no row' => $noRow(1),
  'browser, emptied' => $holding('1', 0),
  'browser, Yes' => $holding('1', 1),
  'browser, No' => $holding('0', 0),
];
$restore = [];
foreach ($contacts as $key => $contactId) {
  $restore[$contactId] = $stored($contactId);
  printf("%s: contact %d, stored %s\n", $key, $contactId, json_encode($restore[$contactId]));
}
register_shutdown_function(static function () use (&$restore, &$session, $table, $radio, $toggle): void {
  $session?->stop();
  foreach ($restore as $contactId => $before) {
    if ($before === NULL) {
      \CRM_Core_DAO::executeQuery("DELETE FROM $table WHERE entity_id = $contactId");
      continue;
    }
    \Civi\Api4\Contact::update(FALSE)
      ->addWhere('id', '=', $contactId)
      ->addValue('ce_profile_all.boolean_radio', $before[0] === 'NULL' ? NULL : (bool) $before[0])
      ->addValue('ce_profile_all.boolean_toggle', $before[1] === 'NULL' ? NULL : (bool) $before[1])
      ->execute();
  }
  \Drupal::entityTypeManager()->getStorage('civicrm_contact')->resetCache(array_keys($restore));
  printf("\nrestored %d contacts\n", count($restore));
});
\Civi\Api4\Contact::update(FALSE)
  ->addWhere('id', '=', $contacts['browser, emptied'])
  ->addValue('ce_profile_all.boolean_radio', NULL)
  ->execute();
$etm->getStorage('civicrm_contact')->resetCache([$contacts['browser, emptied']]);
$check($stored($contacts['browser, emptied'])[0] === 'NULL', 'boolean_radio emptied through API4 for contact ' . $contacts['browser, emptied']);

echo "\n1. The default contact form\n";
$form = \Drupal::service('entity_display.repository')->getFormDisplay('civicrm_contact', 'civicrm_contact', 'default');
$check($form->isNew(), 'no saved default contact form display');
foreach ([$radio['drupal'] => 'options_buttons', $toggle['drupal'] => 'boolean_checkbox', 'is_deceased' => 'boolean_checkbox'] as $name => $want) {
  $got = $form->getComponent($name)['type'] ?? NULL;
  $check($got === $want, "$name widget", var_export($got, TRUE) . " vs '$want'");
}
$edit = \Drupal::service('entity_display.repository')->getFormDisplay('civicrm_contact', 'civicrm_contact', 'edit');
$check(!$edit->isNew() && ($edit->getComponent($radio['drupal'])['type'] ?? NULL) === 'boolean_checkbox', "the saved edit form keeps the checkbox for {$radio['drupal']}");

\Drupal::service('account_switcher')->switchTo(User::load(1));
if ($mode === 'api3') {
  echo "\n2-3. api3: the guard on the built edit form (saves are not possible here)\n";
  $guarded = static function (string $key) use ($contacts, $etm, $radio): bool {
    $etm->getStorage('civicrm_contact')->resetCache([$contacts[$key]]);
    $form = \Drupal::service('entity.form_builder')->getForm($etm->getStorage('civicrm_contact')->load($contacts[$key]), 'edit');
    return in_array(['Drupal\civicrm_entity\Hook\FieldWidgetHooks', 'keepEmpty'], $form[$radio['drupal']]['widget']['value']['#element_validate'] ?? [], TRUE);
  };
  $check($guarded('browser, emptied'), 'the guard is on the emptied contact\'s checkbox');
  $check($guarded('browser, no row'), 'the guard is on the checkbox of the contact with no row');
  $check(!$guarded('browser, Yes'), 'the guard is not on a stored Yes');
  $check(!$guarded('browser, No'), 'the guard is not on a stored No');
  printf("\n%d passed, %d failed\n", $pass, count($fail));
  if ($fail) {
    exit(1);
  }
  return;
}

echo "\n2. The default form, saved unchanged (programmatic, admin)\n";
$contactId = $contacts['programmatic, no row'];
$formObject = $etm->getFormObject('civicrm_contact', 'default');
$formObject->setEntity($etm->getStorage('civicrm_contact')->load($contactId));
$formState = (new FormState())->setValues(['op' => 'Save']);
\Drupal::formBuilder()->submitForm($formObject, $formState);
$check($formState->getErrors() === [], 'the form saves', json_encode(array_map('strval', $formState->getErrors())));
$after = $stored($contactId);
$check(($after[0] ?? 'NULL') === 'NULL', 'boolean_radio stays empty', json_encode($after));
$check(($after[1] ?? NULL) === '0', 'boolean_toggle stores 0, as CiviCRM\'s advcheckbox does', json_encode($after));
\Drupal::service('account_switcher')->switchBack();

echo "\n3. The saved edit form, in the browser (admin)\n";
$driver = new Selenium2Driver('chrome', [
  'browserName' => 'chrome',
  'goog:chromeOptions' => [
    'w3c' => FALSE,
    'args' => ['--headless', '--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu', '--ignore-certificate-errors', '--window-size=1400,1200'],
  ],
], 'http://selenium-chrome:4444/wd/hub');
$session = new Session($driver);
$session->start();
$etm->getStorage('user')->resetCache();
$session->visit(BASE . parse_url(user_pass_reset_url(User::load(1)) . '/login', PHP_URL_PATH));
$messages = static function () use ($session): string {
  $found = [];
  foreach ($session->getPage()->findAll('css', '[data-drupal-messages] [role="alert"], [data-drupal-messages] [role="status"], .messages, .form-item--error-message') as $element) {
    $found[] = trim(preg_replace('/\s+/', ' ', $element->getText()));
  }
  return implode(' | ', array_unique(array_filter($found)));
};
$open = static function (int $contactId) use ($session, $etm): void {
  $etm->getStorage('civicrm_contact')->resetCache([$contactId]);
  $session->visit(BASE . "/civicrm-contact/$contactId/edit");
};
$box = static fn() => $session->getPage()->find('css', 'input[type="checkbox"][name="' . $radio['drupal'] . '[value]"]');
$save = static function () use ($session, $messages): string {
  $session->getPage()->pressButton('Save');
  $session->wait(10000, 'document.readyState === "complete"');
  return $messages();
};

$contactId = $contacts['browser, no row'];
$open($contactId);
$check($box()?->isChecked() === FALSE, "no row: contact $contactId's checkbox is unticked");
$said = $save();
$after = $stored($contactId);
$check(($after[0] ?? 'NULL') === 'NULL', 'no row: unchanged save leaves boolean_radio empty', json_encode($after) . " $said");

$contactId = $contacts['browser, emptied'];
$open($contactId);
$check($box()?->isChecked() === FALSE, "emptied: contact $contactId's checkbox is unticked");
$said = $save();
$check($stored($contactId)[0] === 'NULL', 'emptied: unchanged save leaves boolean_radio empty', json_encode($stored($contactId)) . " $said");
$open($contactId);
$box()?->check();
$said = $save();
$check($stored($contactId)[0] === '1', 'emptied: ticked, it stores 1', json_encode($stored($contactId)) . " $said");

$contactId = $contacts['browser, Yes'];
$open($contactId);
$check($box()?->isChecked() === TRUE, "Yes: contact $contactId's checkbox is ticked");
$box()?->uncheck();
$said = $save();
$check($stored($contactId)[0] === '0', 'Yes: unticked, it stores 0', json_encode($stored($contactId)) . " $said");

$contactId = $contacts['browser, No'];
$open($contactId);
$said = $save();
$check($stored($contactId)[0] === '0', "No: contact $contactId's unchanged save keeps 0", json_encode($stored($contactId)) . " $said");

printf("\n%d passed, %d failed\n", $pass, count($fail));
foreach ($fail as $f) {
  echo "  - $f\n";
}
if ($fail) {
  exit(1);
}
