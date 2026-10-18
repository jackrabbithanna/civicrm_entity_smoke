<?php

/**
 * @file
 * Punch list J3: an unconfigured record type's default view, form and list.
 *
 *   ddev drush --uri=https://drupal-civi-composer-exp-d11.ddev.site \
 *     php:script scripts/smoke/j3-smoke.php
 *
 * The fixture saves a default view and form display for every record type.
 * This sets them aside, restoring them on shutdown, so each type falls back
 * to what Drupal builds from the base field display options, as on a site
 * where nobody has configured the type yet. The `add` form mode and the
 * `ce_compact` view mode are left alone.
 *
 * 1. Every field but `id` has a formatter and a widget, of the type expected,
 *    and shows its label. CiviCRM's Radio and CheckBox fields, booleans
 *    included, get radios or checkboxes. File fields are noted, not counted:
 *    J3 does not cover them.
 * 2. Each field of each record type, rendered for a record holding a value,
 *    shows its label and its value: option labels, not IDs; Yes or No; two
 *    decimals; the referenced entity's label.
 * 3. The edit form holds each value.
 * 4. The record list shows the same, not the stored values.
 * 5. In headless Chromium (ddev selenium-chrome), as admin: an event session,
 *    a board term and an education record show their values on their pages
 *    and edit forms; an unchanged save changes nothing in CiviCRM; references
 *    picked through the autocomplete, Money, Float, Boolean, Country and
 *    State/Province edited through the form are stored; a required reference
 *    is enforced. The radios of a CheckBox, an Int Radio and a Boolean Radio
 *    field hold and save their values, and a Boolean Radio left empty stays
 *    empty (a checkbox saved it as No). Edited values are put back through
 *    API4.
 */

use Behat\Mink\Driver\Selenium2Driver;
use Behat\Mink\Session;
use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\user\Entity\User;

const BASE = 'https://drupal-civi-composer-exp-d11.ddev.site';

\Drupal::service('civicrm')->initialize();
$etm = \Drupal::entityTypeManager();
$efm = \Drupal::service('entity_field.manager');
$renderer = \Drupal::service('renderer');
$pass = 0;
$fail = [];
$session = NULL;
$check = static function (bool $ok, string $label, string $detail = '') use (&$pass, &$fail): void {
  $ok ? $pass++ : $fail[] = $label . ($detail ? " ($detail)" : '');
  printf("  [%s] %s%s\n", $ok ? 'ok' : 'FAIL', $label, $detail ? " -- $detail" : '');
};
$note = static fn(string $text) => printf("  [--] %s\n", $text);
$rows = static fn(string $query) => \CRM_Core_DAO::executeQuery($query)->fetchAll();
$mode = \Drupal::config('civicrm_entity.settings')->get('api_backend');
echo "Mode: $mode\n";

// ---------------------------------------------------------------------------
// The record types, their groups and fields.
// ---------------------------------------------------------------------------
$types = [];
foreach ($etm->getDefinitions() as $entityTypeId => $definition) {
  if ($group = $definition->get('civicrm_custom_record')) {
    $civi = \Civi\Api4\CustomGroup::get(FALSE)->addWhere('id', '=', $group['group_id'])->execute()->first();
    $fields = [];
    foreach (\Civi\Api4\CustomField::get(FALSE)->addWhere('custom_group_id', '=', $civi['id'])->addWhere('is_active', '=', TRUE)->execute() as $field) {
      $fields['custom_' . $field['id']] = $field;
    }
    $types[$entityTypeId] = ['group' => $civi, 'fields' => $fields];
  }
}
ksort($types);
if (!$types) {
  // `api3` uninstalls them.
  $check($mode === 'api3', 'record types exist', "none in $mode");
  printf("\n%d passed, %d failed\n", $pass, count($fail));
  return;
}

// ---------------------------------------------------------------------------
// Set the saved default displays aside; restore them on shutdown, whatever
// fails (a fatal error inside CiviCRM skips `finally`).
// ---------------------------------------------------------------------------
$setAside = [];
$restoreValues = [];
register_shutdown_function(static function () use (&$setAside, &$restoreValues, &$session, $etm): void {
  $session?->stop();
  foreach ($restoreValues as $restore) {
    try {
      $restore();
    }
    catch (\Throwable $e) {
      echo "\nRESTORE FAILED: " . $e->getMessage() . "\n";
    }
  }
  $mismatch = [];
  foreach ($setAside as $name => [$entityType, $raw]) {
    $storage = $etm->getStorage($entityType);
    $storage->resetCache();
    if (!$storage->load($raw['id'])) {
      $storage->create($raw)->save();
    }
    $now = \Drupal::configFactory()->get($name)->getRawData();
    if (serialize(sortRecursive($now)) !== serialize(sortRecursive($raw))) {
      $mismatch[] = $name;
    }
  }
  printf("\nrestored: %d displays%s; %d value sets\n", count($setAside), $mismatch ? ' (DIFFERENT AFTER RESTORE: ' . implode(', ', $mismatch) . ')' : ', identical', count($restoreValues));
});

/**
 * Sorts an array by key, recursively, for comparison.
 */
function sortRecursive(array $data): array {
  ksort($data);
  foreach ($data as &$value) {
    if (is_array($value)) {
      $value = sortRecursive($value);
    }
  }
  return $data;
}

foreach (array_keys($types) as $entityTypeId) {
  foreach (['entity_view_display', 'entity_form_display'] as $displayType) {
    $id = "$entityTypeId.$entityTypeId.default";
    $name = 'core.' . $displayType . '.' . $id;
    $raw = \Drupal::configFactory()->get($name)->getRawData();
    if ($raw) {
      $setAside[$name] = [$displayType, $raw];
      $etm->getStorage($displayType)->load($id)->delete();
    }
  }
}
printf("Set aside %d saved default displays.\n", count($setAside));
\Drupal::service('account_switcher')->switchTo(User::load(1));

// ---------------------------------------------------------------------------
// What each field should show.
// ---------------------------------------------------------------------------
$expectedFormatter = static fn(string $type): ?string => match ($type) {
  'entity_reference' => 'entity_reference_label',
  'float' => 'number_decimal',
  'boolean' => 'boolean',
  'list_integer', 'list_string' => 'list_default',
  'string' => 'string',
  'string_long' => 'basic_string',
  'text_long' => 'text_default',
  'datetime' => 'datetime_default',
  'integer' => 'number_integer',
  default => NULL,
};
$expectedTypeWidget = static fn(string $type): ?string => match ($type) {
  'entity_reference' => 'entity_reference_autocomplete',
  'float', 'integer' => 'number',
  'boolean' => 'boolean_checkbox',
  'list_integer', 'list_string' => 'options_select',
  'string' => 'string_textfield',
  'string_long' => 'string_textarea',
  'text_long' => 'civicrm_entity_textarea',
  'datetime' => 'datetime_default',
  default => NULL,
};
$expectedWidget = static fn(string $type, string $html = ''): ?string => match (TRUE) {
  in_array($html, ['Radio', 'CheckBox'], TRUE) && in_array($type, ['list_integer', 'list_string', 'boolean'], TRUE) => 'options_buttons',
  default => $expectedTypeWidget($type),
};
$isFile = static fn(string $entityTypeId, string $name): bool => ($types[$entityTypeId]['fields'][$name]['data_type'] ?? '') === 'File';

/**
 * The texts a field's rendered output must hold, or NULL for "any output".
 */
$expectedTexts = static function (object $entity, string $name): ?array {
  $items = $entity->get($name);
  $definition = $items->getFieldDefinition();
  $texts = [];
  foreach ($items as $item) {
    $value = $item->get($item->mainPropertyName())->getValue();
    $texts[] = match ($definition->getType()) {
      'list_integer', 'list_string' => (string) (options_allowed_values($definition->getFieldStorageDefinition(), $entity)[$value] ?? "(no label for $value)"),
      'boolean' => $value ? 'Yes' : 'No',
      'float' => number_format((float) $value, 2, '.', ''),
      'entity_reference' => (string) ($item->entity?->label() ?? "(entity $value not loaded)"),
      'string', 'integer' => (string) $value,
      default => NULL,
    };
  }
  $texts = array_filter($texts, static fn($text) => $text !== NULL);
  return $texts ?: NULL;
};
$contains = static function (string $html, array $texts): array {
  $html = html_entity_decode(strip_tags($html), ENT_QUOTES);
  return array_values(array_filter($texts, static fn($text) => !str_contains($html, $text)));
};
// A record of the type holding a value in the field, or any record.
$recordFor = static function (string $entityTypeId, ?string $name) use ($types, $rows, $etm): ?object {
  $table = $types[$entityTypeId]['group']['table_name'];
  $column = $name && isset($types[$entityTypeId]['fields'][$name]) ? $types[$entityTypeId]['fields'][$name]['column_name'] : NULL;
  $where = $column ? "WHERE $column IS NOT NULL AND $column <> ''" : '';
  $id = $rows("SELECT id FROM $table $where ORDER BY id LIMIT 1")[0]['id'] ?? NULL;
  $etm->getStorage($entityTypeId)->resetCache();
  return $id ? $etm->getStorage($entityTypeId)->load($id) : NULL;
};

echo "\n1. Components of the new default displays\n";
$viewDisplays = [];
foreach (array_keys($types) as $entityTypeId) {
  $repository = \Drupal::service('entity_display.repository');
  $view = $repository->getViewDisplay($entityTypeId, $entityTypeId, 'default');
  $form = $repository->getFormDisplay($entityTypeId, $entityTypeId, 'default');
  $check($view->isNew() && $form->isNew(), "$entityTypeId: no saved default display");
  $viewDisplays[$entityTypeId] = $view;
  foreach ($efm->getBaseFieldDefinitions($entityTypeId) as $name => $definition) {
    if ($name === 'id') {
      continue;
    }
    $type = $definition->getType();
    $formatter = $view->getComponent($name)['type'] ?? NULL;
    $widget = $form->getComponent($name)['type'] ?? NULL;
    $label = sprintf('%s.%s (%s, %s)', $entityTypeId, $name, $type, $types[$entityTypeId]['fields'][$name]['data_type'] ?? 'base');
    if ($isFile($entityTypeId, $name)) {
      $note("$label: File, not J3's; formatter " . var_export($formatter, TRUE) . ', widget ' . var_export($widget, TRUE));
      continue;
    }
    $html = $types[$entityTypeId]['fields'][$name]['html_type'] ?? '';
    $check($formatter === $expectedFormatter($type), "$label formatter", var_export($formatter, TRUE) . ' vs ' . var_export($expectedFormatter($type), TRUE));
    $check($widget === $expectedWidget($type, $html), "$label widget" . ($html ? " ($html)" : ''), var_export($widget, TRUE) . ' vs ' . var_export($expectedWidget($type, $html), TRUE));
    // The parent's reference reads inline, as the record type defines it.
    $wantLabel = $name === 'entity_id' ? 'inline' : 'above';
    $shownLabel = $view->getComponent($name)['label'] ?? NULL;
    $check($shownLabel === $wantLabel, "$label label", var_export($shownLabel, TRUE) . " vs '$wantLabel'");
  }
}

echo "\n2. Rendered records\n";
foreach (array_keys($types) as $entityTypeId) {
  foreach (array_keys($efm->getBaseFieldDefinitions($entityTypeId)) as $name) {
    if ($name === 'id' || $isFile($entityTypeId, $name)) {
      continue;
    }
    $record = $recordFor($entityTypeId, $name === 'entity_id' ? NULL : $name);
    if (!$record) {
      $note("$entityTypeId.$name: no record holds a value");
      continue;
    }
    $build = EntityViewDisplay::collectRenderDisplay($record, 'full')->build($record);
    $html = isset($build[$name]) ? (string) $renderer->renderInIsolation($build[$name]) : '';
    $label = "$entityTypeId #{$record->id()} $name";
    $fieldLabel = (string) $record->get($name)->getFieldDefinition()->getLabel();
    $check($html !== '' && !$contains($html, [$fieldLabel]), "$label shows its label $fieldLabel", trim(preg_replace('/\s+/', ' ', strip_tags($html))));
    $texts = $expectedTexts($record, $name);
    if ($texts === NULL) {
      $check($html !== '', "$label is shown");
      continue;
    }
    $missing = $html === '' ? $texts : $contains($html, $texts);
    $check(!$missing, "$label shows " . implode(', ', $texts), $html === '' ? 'not rendered' : 'missing ' . implode(', ', $missing) . ' in: ' . trim(preg_replace('/\s+/', ' ', strip_tags($html))));
  }
}

echo "\n3. Edit forms\n";
$formBuilder = \Drupal::service('entity.form_builder');
foreach (array_keys($types) as $entityTypeId) {
  foreach ($efm->getBaseFieldDefinitions($entityTypeId) as $name => $definition) {
    if ($name === 'id' || $isFile($entityTypeId, $name)) {
      continue;
    }
    $record = $recordFor($entityTypeId, $name === 'entity_id' ? NULL : $name);
    if (!$record) {
      continue;
    }
    $form = $formBuilder->getForm($record, 'edit');
    $widget = $form[$name]['widget'] ?? NULL;
    $label = "$entityTypeId #{$record->id()} $name";
    if (!$widget) {
      $check(FALSE, "$label has a widget");
      continue;
    }
    $stored = $record->get($name)->getValue()[0] ?? [];
    [$shown, $want] = match ($definition->getType()) {
      'entity_reference' => [
        (static function ($default) {
          $default = is_array($default) ? reset($default) : $default;
          return is_object($default) ? (int) $default->id() : NULL;
        })($widget[0]['target_id']['#default_value'] ?? NULL),
        (int) ($stored['target_id'] ?? 0),
      ],
      'float', 'integer', 'string' => [(string) ($widget[0]['value']['#default_value'] ?? ''), (string) ($stored['value'] ?? '')],
      'boolean' => [(bool) ($widget['value']['#default_value'] ?? $widget['#default_value'] ?? NULL), (bool) ($stored['value'] ?? FALSE)],
      'list_integer', 'list_string' => [array_map('strval', (array) ($widget['#default_value'] ?? [])), array_map('strval', array_column($record->get($name)->getValue(), 'value'))],
      default => [TRUE, TRUE],
    };
    if ($definition->getType() === 'float') {
      [$shown, $want] = [(float) $shown, (float) $want];
    }
    $check($shown == $want, "$label widget holds the value", var_export($shown, TRUE) . ' vs ' . var_export($want, TRUE));
    if ($expectedWidget($definition->getType(), $types[$entityTypeId]['fields'][$name]['html_type'] ?? '') === 'options_buttons') {
      $element = $definition->getFieldStorageDefinition()->isMultiple() ? 'checkboxes' : 'radios';
      $check(($widget['#type'] ?? NULL) === $element, "$label widget is $element", var_export($widget['#type'] ?? NULL, TRUE));
    }
  }
}

echo "\n4. Record lists\n";
foreach (array_keys($types) as $entityTypeId) {
  $builder = $etm->getListBuilder($entityTypeId);
  $header = $builder->buildHeader();
  foreach (array_keys($header) as $name) {
    if (in_array($name, ['id', 'entity_id', 'operations'], TRUE) || $isFile($entityTypeId, $name)) {
      continue;
    }
    $record = $recordFor($entityTypeId, $name);
    if (!$record) {
      continue;
    }
    $cell = $builder->buildRow($record)[$name];
    $cell = is_array($cell) ? ($cell['data'] ?? $cell) : $cell;
    $html = is_array($cell) ? (string) $renderer->renderInIsolation($cell) : (string) $cell;
    $texts = $expectedTexts($record, $name);
    if ($texts === NULL) {
      $check(trim(strip_tags($html)) !== '', "$entityTypeId #{$record->id()} list: $name is shown");
      continue;
    }
    $missing = $contains($html, $texts);
    $check(!$missing, "$entityTypeId #{$record->id()} list: $name shows " . implode(', ', $texts), 'got: ' . trim(strip_tags($html)));
  }
}
\Drupal::service('account_switcher')->switchBack();

// ---------------------------------------------------------------------------
// 5. The browser.
// ---------------------------------------------------------------------------
echo "\n5. Pages and forms in the browser (admin)\n";
$driver = new Selenium2Driver('chrome', [
  'browserName' => 'chrome',
  'goog:chromeOptions' => [
    'w3c' => FALSE,
    'args' => ['--headless', '--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu', '--ignore-certificate-errors', '--window-size=1400,1200'],
  ],
], 'http://selenium-chrome:4444/wd/hub');
$session = new Session($driver);
$session->start();
\Drupal::entityTypeManager()->getStorage('user')->resetCache();
$session->visit(BASE . parse_url(user_pass_reset_url(User::load(1)) . '/login', PHP_URL_PATH));

$visit = static function (string $path) use ($session): int {
  $session->visit(BASE . $path);
  $title = (string) $session->evaluateScript('return document.title');
  return match (TRUE) {
    str_starts_with($title, 'Access denied') => 403,
    str_starts_with($title, 'Page not found') => 404,
    str_contains($session->getPage()->getContent(), 'The website encountered an unexpected error') => 500,
    default => 200,
  };
};
$text = static fn() => preg_replace('/\s+/', ' ', $session->getPage()->getText());
$messages = static function () use ($session): string {
  $found = [];
  foreach ($session->getPage()->findAll('css', '[data-drupal-messages] [role="alert"], [data-drupal-messages] [role="status"], .messages, .form-item--error-message') as $element) {
    $found[] = trim(preg_replace('/\s+/', ' ', $element->getText()));
  }
  return implode(' | ', array_unique(array_filter($found)));
};
$value = static fn(string $name) => $session->getPage()->find('css', '[name="' . $name . '"]')?->getValue();
$save = static function () use ($session): void {
  $session->getPage()->pressButton('Save');
  $session->wait(10000, 'document.readyState === "complete"');
};
$autocomplete = static function (string $name, string $typed, string $pick) use ($session): ?string {
  $element = $session->getPage()->find('css', '[name="' . $name . '"]');
  if (!$element) {
    return NULL;
  }
  $element->setValue($typed);
  $session->getDriver()->keyDown($element->getXpath(), ' ');
  $session->getDriver()->keyUp($element->getXpath(), ' ');
  $session->wait(10000, 'jQuery("ul.ui-autocomplete li:visible").length > 0');
  foreach ($session->getPage()->findAll('css', 'ul.ui-autocomplete li') as $item) {
    if (str_contains($item->getText(), $pick)) {
      ($item->find('css', 'a') ?? $item)->click();
      $session->wait(1000, 'false');
      return $element->getValue();
    }
  }
  return NULL;
};
$row = static fn(string $table, int $id) => $rows("SELECT * FROM $table WHERE id = $id")[0] ?? [];
$diff = static function (array $before, array $after): string {
  $changes = [];
  foreach ($before as $column => $old) {
    if (($after[$column] ?? NULL) !== $old) {
      $changes[] = sprintf('%s %s -> %s', $column, var_export($old, TRUE), var_export($after[$column] ?? NULL, TRUE));
    }
  }
  return implode('; ', $changes);
};
// Puts a record's columns back through API4, by field name.
$restoreLater = static function (string $entityTypeId, int $id, array $before) use (&$restoreValues, $types): void {
  $group = $types[$entityTypeId]['group'];
  $values = [];
  foreach ($types[$entityTypeId]['fields'] as $field) {
    if (array_key_exists($field['column_name'], $before)) {
      $stored = $before[$field['column_name']];
      // A serialized column goes back as the list it holds.
      $values[$field['name']] = $field['serialize'] && $stored !== NULL ? \CRM_Utils_Array::explodePadded($stored) : $stored;
    }
  }
  $restoreValues[] = static fn() => \Civi\Api4\CustomValue::update($group['name'], FALSE)->addWhere('id', '=', $id)->setValues($values)->execute();
};
$path = static fn(string $entityTypeId) => '/' . str_replace('_', '-', $entityTypeId);
$col = static function (string $entityTypeId, string $fieldName) use ($types): array {
  foreach ($types[$entityTypeId]['fields'] as $name => $field) {
    if ($field['name'] === $fieldName) {
      return [$name, $field['column_name']];
    }
  }
  throw new \RuntimeException("$entityTypeId has no field $fieldName");
};
$label = static fn(string $entity, int $id) => (string) $etm->getStorage($entity)->load($id)?->label();
$money = static fn($v) => number_format((float) $v, 2, '.', '');

// --- An event session: two references and Money. ---------------------------
$type = 'civicrm_cv_ce_event_sessions';
$table = $types[$type]['group']['table_name'];
[$speaker, $speakerCol] = $col($type, 'speaker');
[$fee, $feeCol] = $col($type, 'fee');
[$activity, $activityCol] = $col($type, 'related_activity');
$id = (int) ($rows("SELECT id FROM $table WHERE $speakerCol IS NOT NULL AND $feeCol IS NOT NULL AND $activityCol IS NOT NULL ORDER BY id LIMIT 1")[0]['id'] ?? 0);
$before = $row($table, $id);
echo "event session #$id\n";
$status = $visit($path($type) . "/$id");
$page = $text();
$check($status === 200, "page opens", "HTTP $status");
foreach ([
  'speaker' => $label('civicrm_contact', (int) $before[$speakerCol]),
  'fee' => $money($before[$feeCol]),
  'related activity' => $label('civicrm_activity', (int) $before[$activityCol]),
] as $what => $expected) {
  $check($expected !== '' && str_contains($page, $expected), "page shows the $what", $expected);
}
$status = $visit($path($type) . "/$id/edit");
$check($status === 200, "edit form opens", "HTTP $status");
$check($value("{$speaker}[0][target_id]") === sprintf('%s (%d)', $label('civicrm_contact', (int) $before[$speakerCol]), $before[$speakerCol]), 'speaker autocomplete holds the speaker', var_export($value("{$speaker}[0][target_id]"), TRUE));
$check((float) $value("{$fee}[0][value]") === (float) $before[$feeCol], 'fee field holds the fee', var_export($value("{$fee}[0][value]"), TRUE));
$check($value("{$activity}[0][target_id]") === sprintf('%s (%d)', $label('civicrm_activity', (int) $before[$activityCol]), $before[$activityCol]), 'activity autocomplete holds the activity', var_export($value("{$activity}[0][target_id]"), TRUE));
if ($status === 200) {
  $save();
  $check(($d = $diff($before, $row($table, $id))) === '', 'unchanged save changes nothing', $d . ' ' . $messages());
}
$restoreLater($type, $id, $before);
$newContact = $rows("SELECT id, display_name, last_name FROM civicrm_contact WHERE contact_type = 'Individual' AND is_deleted = 0 AND last_name IS NOT NULL AND id <> " . (int) $before[$speakerCol] . " ORDER BY id LIMIT 1 OFFSET 3")[0];
$newActivity = $rows("SELECT id, subject FROM civicrm_activity WHERE subject IS NOT NULL AND subject <> '' AND is_deleted = 0 AND id <> " . (int) $before[$activityCol] . " ORDER BY id LIMIT 1 OFFSET 5")[0];
$visit($path($type) . "/$id/edit");
$picked = $autocomplete("{$speaker}[0][target_id]", $newContact['last_name'], $newContact['display_name']);
$check($picked !== NULL, 'autocomplete offers ' . $newContact['display_name'], var_export($picked, TRUE));
$picked = $autocomplete("{$activity}[0][target_id]", mb_substr($newActivity['subject'], 0, 8), $newActivity['subject']);
$check($picked !== NULL, 'autocomplete offers activity ' . $newActivity['subject'], var_export($picked, TRUE));
// Subjects repeat in the sample data: the activity is the one picked.
$newActivity['id'] = preg_match('/\((\d+)\)$/', (string) $picked, $m) ? (int) $m[1] : NULL;
$session->getPage()->find('css', '[name="' . $fee . '[0][value]"]')?->setValue('77.25');
$save();
$after = $row($table, $id);
$check((int) $after[$speakerCol] === (int) $newContact['id'], 'edited speaker is stored', var_export($after[$speakerCol], TRUE) . ' vs ' . $newContact['id'] . ' ' . $messages());
$check((int) $after[$activityCol] === (int) $newActivity['id'], 'edited activity is stored', var_export($after[$activityCol], TRUE) . ' vs ' . $newActivity['id']);
$check((float) $after[$feeCol] === 77.25, 'edited fee is stored', var_export($after[$feeCol], TRUE));

// --- A board term: a required reference, Money, Country, State. -------------
$type = 'civicrm_cv_ce_board_terms';
$table = $types[$type]['group']['table_name'];
[$member, $memberCol] = $col($type, 'member');
[$stipend, $stipendCol] = $col($type, 'stipend');
[$country, $countryCol] = $col($type, 'country');
[$state, $stateCol] = $col($type, 'state');
$id = (int) ($rows("SELECT id FROM $table WHERE $stipendCol IS NOT NULL AND $stateCol IS NOT NULL AND $countryCol IS NOT NULL ORDER BY id LIMIT 1")[0]['id'] ?? 0);
$before = $row($table, $id);
echo "board term #$id\n";
$countryName = (string) \CRM_Core_PseudoConstant::country($before[$countryCol]);
$stateName = (string) \CRM_Core_PseudoConstant::stateProvince($before[$stateCol]);
$status = $visit($path($type) . "/$id");
$page = $text();
$check($status === 200, 'page opens', "HTTP $status");
foreach ([
  'board member' => $label('civicrm_contact', (int) $before[$memberCol]),
  'stipend' => $money($before[$stipendCol]),
  'country' => $countryName,
  'state' => $stateName,
] as $what => $expected) {
  $check($expected !== '' && str_contains($page, $expected), "page shows the $what", $expected);
}
$status = $visit($path($type) . "/$id/edit");
$check($value("{$member}[0][target_id]") === sprintf('%s (%d)', $label('civicrm_contact', (int) $before[$memberCol]), $before[$memberCol]), 'board member autocomplete holds the member', var_export($value("{$member}[0][target_id]"), TRUE));
$check((float) $value("{$stipend}[0][value]") === (float) $before[$stipendCol], 'stipend field holds the stipend', var_export($value("{$stipend}[0][value]"), TRUE));
$check((string) $value($country) === (string) $before[$countryCol], 'country select holds the country', var_export($value($country), TRUE));
$check((string) $value($state) === (string) $before[$stateCol], 'state select holds the state', var_export($value($state), TRUE));
if ($status === 200) {
  $save();
  $check(($d = $diff($before, $row($table, $id))) === '', 'unchanged save changes nothing', $d . ' ' . $messages());
}
$restoreLater($type, $id, $before);
$visit($path($type) . "/$id/edit");
$memberInput = $session->getPage()->find('css', '[name="' . $member . '[0][target_id]"]');
$check((bool) $memberInput?->hasAttribute('required'), 'board member input is required in the browser');
// Past the browser's own check, to Drupal's.
$session->executeScript(sprintf('document.querySelector(%s)?.removeAttribute("required")', json_encode('[name="' . $member . '[0][target_id]"]')));
$memberInput?->setValue('');
$save();
$check(str_contains($messages(), 'field is required'), 'emptied board member is refused', $messages());
$check(($d = $diff($before, $row($table, $id))) === '', 'refused save changes nothing', $d);
$ontario = $rows("SELECT s.id FROM civicrm_state_province s JOIN civicrm_country c ON c.id = s.country_id WHERE c.iso_code = 'CA' AND s.abbreviation = 'ON'")[0]['id'];
$canada = $rows("SELECT id FROM civicrm_country WHERE iso_code = 'CA'")[0]['id'];
$visit($path($type) . "/$id/edit");
$session->getPage()->find('css', '[name="' . $stipend . '[0][value]"]')?->setValue('2500.5');
$session->getPage()->find('css', '[name="' . $country . '"]')?->selectOption($canada);
$session->getPage()->find('css', '[name="' . $state . '"]')?->selectOption($ontario);
$save();
$after = $row($table, $id);
$check((float) $after[$stipendCol] === 2500.5, 'edited stipend is stored', var_export($after[$stipendCol], TRUE) . ' ' . $messages());
$check((int) $after[$countryCol] === (int) $canada, 'edited country is stored', var_export($after[$countryCol], TRUE));
$check((int) $after[$stateCol] === (int) $ontario, 'edited state is stored', var_export($after[$stateCol], TRUE));
$visit($path($type) . "/$id");
$page = $text();
$check(str_contains($page, 'Canada') && str_contains($page, 'Ontario') && str_contains($page, '2500.50'), 'page shows Canada, Ontario and 2500.50');

// --- An education record: a label, Float, Boolean radios, CheckBox. --------
$type = 'civicrm_cv_ce_education';
$table = $types[$type]['group']['table_name'];
[$school, $schoolCol] = $col($type, 'school');
[$gpa, $gpaCol] = $col($type, 'gpa');
[$graduated, $graduatedCol] = $col($type, 'graduated');
[$honors, $honorsCol] = $col($type, 'honors');
$honorsLabels = \Civi\Api4\OptionValue::get(FALSE)
  ->addWhere('option_group_id', '=', $types[$type]['fields'][$honors]['option_group_id'])
  ->execute()
  ->column('label', 'value');
$held = static fn(?string $stored): array => $stored === NULL ? [] : array_values(array_filter(\CRM_Utils_Array::explodePadded($stored), 'strlen'));
// A record holding two honors; their order in the sample data is not sorted.
$id = 0;
foreach ($rows("SELECT id, $honorsCol AS honors FROM $table WHERE $gpaCol IS NOT NULL AND $graduatedCol = 1 ORDER BY id") as $candidate) {
  if (count($held($candidate['honors'])) === 2) {
    $id = (int) $candidate['id'];
    break;
  }
}
$before = $row($table, $id);
$heldHonors = $held($before[$honorsCol]);
echo "education record #$id\n";
$status = $visit($path($type) . "/$id");
$page = $text();
$check($status === 200, 'page opens', "HTTP $status");
$schoolLabel = $types[$type]['fields'][$school]['label'];
$check(str_contains($page, "$schoolLabel {$before[$schoolCol]}"), "page shows $schoolLabel: {$before[$schoolCol]}");
$check(str_contains($page, $money($before[$gpaCol])), 'page shows the GPA', $money($before[$gpaCol]));
$graduatedLabel = $types[$type]['fields'][$graduated]['label'];
$check((bool) preg_match('/' . preg_quote($graduatedLabel, '/') . '\s*Yes\b/', $page), "page shows $graduatedLabel: Yes");
foreach ($heldHonors as $honor) {
  $check(str_contains($page, $honorsLabels[$honor]), "page shows honor {$honorsLabels[$honor]}");
}
$status = $visit($path($type) . "/$id/edit");
$radio = static fn(string $name, string $value) => $session->getPage()->find('css', 'input[type="radio"][name="' . $name . '"][value="' . $value . '"]');
$box = static fn(string $name, string $key) => $session->getPage()->find('css', 'input[type="checkbox"][name="' . $name . '[' . $key . ']"]');
$check((float) $value("{$gpa}[0][value]") === (float) $before[$gpaCol], 'GPA field holds the GPA', var_export($value("{$gpa}[0][value]"), TRUE));
$check($radio($graduated, '1')?->isChecked() === TRUE, "$graduatedLabel radio Yes is checked");
foreach (array_keys($honorsLabels) as $honor) {
  $want = in_array((string) $honor, $heldHonors, TRUE);
  $check($box($honors, (string) $honor)?->isChecked() === $want, "honors checkbox $honor is " . ($want ? 'checked' : 'unchecked'));
}
if ($status === 200) {
  $save();
  $check(($d = $diff($before, $row($table, $id))) === '', 'unchanged save changes nothing', $d . ' ' . $messages());
}
$restoreLater($type, $id, $before);
$newHonors = [$heldHonors[1], (string) array_values(array_diff(array_map('strval', array_keys($honorsLabels)), $heldHonors))[0]];
$visit($path($type) . "/$id/edit");
$session->getPage()->find('css', '[name="' . $gpa . '[0][value]"]')?->setValue('3.25');
$radio($graduated, '0')?->click();
$box($honors, $heldHonors[0])?->uncheck();
$box($honors, $newHonors[1])?->check();
$save();
$after = $row($table, $id);
$check((float) $after[$gpaCol] === 3.25, 'edited GPA is stored', var_export($after[$gpaCol], TRUE) . ' ' . $messages());
$check($after[$graduatedCol] === '0', "$graduatedLabel No is stored as 0", var_export($after[$graduatedCol], TRUE));
$stored = $held($after[$honorsCol]);
sort($stored);
$want = $newHonors;
sort($want);
$check($stored === $want, 'edited honors are stored: ' . implode(', ', $want), var_export($after[$honorsCol], TRUE));
$visit($path($type) . "/$id");
$check((bool) preg_match('/' . preg_quote($graduatedLabel, '/') . '\s*No\b/', $text()), "page then shows $graduatedLabel: No");
// Left empty, a Boolean Radio field stays empty: a checkbox saved it as No.
\Civi\Api4\CustomValue::update($types[$type]['group']['name'], FALSE)->addWhere('id', '=', $id)->addValue('graduated', NULL)->execute();
$etm->getStorage($type)->resetCache([$id]);
// The DAO reads NULL as ''.
$isNull = static fn(): bool => $rows("SELECT $graduatedCol IS NULL AS n FROM $table WHERE id = $id")[0]['n'] === '1';
$check($isNull(), "$graduatedLabel emptied through API4");
$visit($path($type) . "/$id/edit");
$check($radio($graduated, '1')?->isChecked() === FALSE && $radio($graduated, '0')?->isChecked() === FALSE, "neither $graduatedLabel radio is checked");
$save();
$check($isNull(), "unchanged save leaves $graduatedLabel empty", var_export($row($table, $id)[$graduatedCol], TRUE) . ' ' . $messages());

// --- A skill: an Int Radio field. -------------------------------------------
$type = 'civicrm_cv_ce_skills';
$table = $types[$type]['group']['table_name'];
[$skill, $skillCol] = $col($type, 'skill');
[$proficiency, $proficiencyCol] = $col($type, 'proficiency');
$levels = \Civi\Api4\OptionValue::get(FALSE)
  ->addWhere('option_group_id', '=', $types[$type]['fields'][$proficiency]['option_group_id'])
  ->execute()
  ->column('label', 'value');
$id = (int) ($rows("SELECT id FROM $table WHERE $proficiencyCol IS NOT NULL ORDER BY id LIMIT 1")[0]['id'] ?? 0);
$before = $row($table, $id);
echo "skill #$id\n";
$status = $visit($path($type) . "/$id");
$page = $text();
$check($status === 200, 'page opens', "HTTP $status");
$skillLabel = $types[$type]['fields'][$skill]['label'];
$check(str_contains($page, "$skillLabel {$before[$skillCol]}"), "page shows $skillLabel: {$before[$skillCol]}");
$proficiencyLabel = $types[$type]['fields'][$proficiency]['label'];
$check(str_contains($page, "$proficiencyLabel {$levels[$before[$proficiencyCol]]}"), "page shows $proficiencyLabel: {$levels[$before[$proficiencyCol]]}");
$status = $visit($path($type) . "/$id/edit");
$check($radio($proficiency, (string) $before[$proficiencyCol])?->isChecked() === TRUE, "$proficiencyLabel radio {$levels[$before[$proficiencyCol]]} is checked");
if ($status === 200) {
  $save();
  $check(($d = $diff($before, $row($table, $id))) === '', 'unchanged save changes nothing', $d . ' ' . $messages());
}
$restoreLater($type, $id, $before);
$newLevel = (string) array_values(array_diff(array_map('strval', array_keys($levels)), [(string) $before[$proficiencyCol]]))[0];
$visit($path($type) . "/$id/edit");
$radio($proficiency, $newLevel)?->click();
$save();
$after = $row($table, $id);
$check((string) $after[$proficiencyCol] === $newLevel, "edited $proficiencyLabel is stored", var_export($after[$proficiencyCol], TRUE) . " vs $newLevel " . $messages());

printf("\n%d passed, %d failed\n", $pass, count($fail));
foreach ($fail as $f) {
  echo "  - $f\n";
}
if ($fail) {
  exit(1);
}
