<?php

/**
 * @file
 * Submits record, contact and event forms in a real browser.
 *
 *   ddev drush --uri=https://drupal-civi-composer-exp-d11.ddev.site \
 *     php:script scripts/smoke/browser-smoke.php
 *
 * Drives the ddev selenium-chrome service (headless Chromium) through Mink,
 * as the admin user and as smoke_editor (made by access-smoke.php). Every
 * submission is checked against the CiviCRM tables, not against what Drupal
 * says it saved. Records the script creates are deleted again, and
 * check_permissions is restored on shutdown. Screenshots of failures go to
 * scripts/smoke/output/.
 */

use Behat\Mink\Driver\Selenium2Driver;
use Behat\Mink\Element\NodeElement;
use Behat\Mink\Session;
use Drupal\user\Entity\User;

const BASE = 'https://drupal-civi-composer-exp-d11.ddev.site';

\Drupal::service('civicrm')->initialize();
$pass = 0;
$fail = [];
$out = dirname(__FILE__) . '/output';
@mkdir($out);
$session = NULL;
$shot = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$pass, &$fail, &$session, &$shot, $out): void {
  $ok ? $pass++ : $fail[] = $label;
  printf("  [%s] %s%s\n", $ok ? 'ok' : 'FAIL', $label, $detail ? " -- $detail" : '');
  if (!$ok && $session) {
    $file = sprintf('%s/fail-%02d.png', $out, ++$shot);
    file_put_contents($file, $session->getScreenshot());
    printf("         screenshot %s, at %s\n", basename($file), $session->getCurrentUrl());
  }
};
$rows = static fn(string $query, array $params = []) => \CRM_Core_DAO::executeQuery($query, $params)->fetchAll();
$row = static fn(string $table, int $id) => $rows("SELECT * FROM $table WHERE id = $id")[0] ?? NULL;
$started = time();

register_shutdown_function(static function () use (&$session): void {
  \Drupal::configFactory()->getEditable('civicrm_entity.settings')->set('check_permissions', FALSE)->save();
  $session?->stop();
});

// ---------------------------------------------------------------------------
// The browser.
// ---------------------------------------------------------------------------
$driver = new Selenium2Driver('chrome', [
  'browserName' => 'chrome',
  'goog:chromeOptions' => [
    'w3c' => FALSE,
    'args' => ['--headless', '--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu', '--ignore-certificate-errors', '--window-size=1400,1200'],
  ],
], 'http://selenium-chrome:4444/wd/hub');
$session = new Session($driver);
$session->start();
$page = static fn() => $session->getPage();

$loginAs = static function (string $name) use ($session): void {
  $session->reset();
  // A login moves the user's login time, which the link's hash covers; the
  // copy cached in this process would make a spent link.
  \Drupal::entityTypeManager()->getStorage('user')->resetCache();
  $account = $name === 'admin' ? User::load(1) : user_load_by_name($name);
  $session->visit(BASE . parse_url(user_pass_reset_url($account) . '/login', PHP_URL_PATH));
};
// WebDriver has no status code, and this Chromium predates responseStatus:
// read it from the page Drupal serves.
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
$messages = static function () use ($session): string {
  $text = [];
  foreach ($session->getPage()->findAll('css', '[data-drupal-messages] [role="alert"], [data-drupal-messages] [role="status"], .messages, .form-item--error-message') as $element) {
    $text[] = trim(preg_replace('/\s+/', ' ', $element->getText()));
  }
  return implode(' | ', array_unique(array_filter($text)));
};
$fatal = static fn() => str_contains($session->getPage()->getContent(), 'The website encountered an unexpected error');
$field = static function (string $name) use ($session): ?NodeElement {
  return $session->getPage()->find('named', ['field', $name]) ?? $session->getPage()->find('css', '[name="' . $name . '"]');
};
$fill = static function (string $name, string $value) use ($field): bool {
  $element = $field($name);
  if (!$element) {
    return FALSE;
  }
  $element->setValue($value);
  return TRUE;
};
// Chromium's date inputs take keys in the locale's order; set the value.
$setDate = static function (string $name, string $value) use ($session): bool {
  return (bool) $session->evaluateScript(sprintf(
    'return (function () { var e = document.querySelector(%s); if (!e) { return false; } e.value = %s; e.dispatchEvent(new Event("change", {bubbles: true})); return true; })()',
    json_encode('[name="' . $name . '"]'),
    json_encode($value),
  ));
};
$firstOption = static function (string $name, int $nth = 1) use ($field): ?string {
  $select = $field($name);
  $values = [];
  foreach ($select?->findAll('css', 'option') ?? [] as $option) {
    if (($value = $option->getAttribute('value')) !== '' && !str_starts_with($value, '_')) {
      $values[] = $value;
    }
  }
  return $values[$nth - 1] ?? NULL;
};
$save = static function (string $label = 'Save') use ($session): void {
  $session->getPage()->pressButton($label);
  $session->wait(10000, 'document.readyState === "complete"');
};
// Types into an entity autocomplete and picks the suggestion matching $pick.
$autocomplete = static function (string $name, string $typed, string $pick) use ($session, $field): ?string {
  $element = $field($name);
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
// A saved record's form returns to its parent, saying which record it made.
$recordId = static function () use ($messages): ?int {
  return preg_match('/#(\d+) has been created/', $messages(), $m) ? (int) $m[1] : NULL;
};
$snapshot = static function (string $entity, int $id) use ($rows): array {
  $snap = [];
  $snap[$entity] = $rows("SELECT * FROM civicrm_" . strtolower($entity) . " WHERE id = $id");
  $extends = $entity === 'Contact' ? "'Contact','Individual','Organization','Household'" : "'$entity'";
  foreach ($rows("SELECT table_name, is_multiple FROM civicrm_custom_group WHERE extends IN ($extends)") as $group) {
    $snap[$group['table_name']] = $rows("SELECT * FROM {$group['table_name']} WHERE entity_id = $id ORDER BY id");
  }
  if ($entity === 'Contact') {
    foreach (['civicrm_email', 'civicrm_phone', 'civicrm_address', 'civicrm_website', 'civicrm_im'] as $table) {
      $snap[$table] = $rows("SELECT * FROM $table WHERE contact_id = $id ORDER BY id");
    }
  }
  // Columns CiviCRM itself moves on every save.
  array_walk_recursive($snap, static function (&$value, $key): void {
    if (in_array($key, ['modified_date', 'hash'], TRUE)) {
      $value = '*';
    }
  });
  return $snap;
};
$diff = static function (array $before, array $after): string {
  $changes = [];
  foreach ($before + $after as $table => $_) {
    $b = $before[$table] ?? [];
    $a = $after[$table] ?? [];
    if (count($b) !== count($a)) {
      $changes[] = "$table rows " . count($b) . ' -> ' . count($a);
      continue;
    }
    foreach ($b as $i => $values) {
      foreach ($values as $column => $value) {
        if (($a[$i][$column] ?? NULL) !== $value) {
          $changes[] = sprintf('%s.%s %s -> %s', $table, $column, var_export($value, TRUE), var_export($a[$i][$column] ?? NULL, TRUE));
        }
      }
    }
  }
  return implode('; ', $changes);
};
$created = [];

echo "1. education record: add, edit, unchanged save, delete (admin)\n";
$loginAs('admin');
$status = $visit('/civicrm-cv-ce-education/add?entity_id=28');
$check($status === 200 && !$fatal(), 'add form for contact 28 opens', "HTTP $status");
$school = 'Browser Smoke University ' . time();
$degree = $firstOption('custom_8', 2);
$fill('custom_7[0][value]', $school);
if ($degree !== NULL) {
  $field('custom_8')->selectOption($degree);
}
$fill('custom_9[0][value]', 'Analytical Engines');
$setDate('custom_11[0][value][date]', '2021-06-15');
$field('custom_13[value]')?->check();
$save();
$id = $recordId();
$check($id !== NULL && !$fatal() && str_ends_with($session->getCurrentUrl(), '/civicrm-contact/28'), 'saving reports the new record and returns to the contact', $session->getCurrentUrl() . ' ' . $messages());
$saved = $id ? $row('civicrm_value_education_his_4', $id) : NULL;
if ($saved) {
  $created[] = ['civicrm_value_education_his_4', $id];
  $check($saved['entity_id'] == 28 && $saved['school_7'] === $school && $saved['degree_8'] === $degree && $saved['field_of_study_9'] === 'Analytical Engines' && $saved['graduated_13'] == 1, 'the row holds what was entered', json_encode(array_intersect_key($saved, array_flip(['entity_id', 'school_7', 'degree_8', 'field_of_study_9', 'graduated_13']))));
  $check(str_starts_with((string) $saved['end_date_11'], '2021-06-15'), 'the date-only end date is stored as entered', (string) $saved['end_date_11']);
}

if ($id) {
  $status = $visit("/civicrm-cv-ce-education/$id/edit");
  $check($status === 200 && !$fatal(), 'edit form opens', "HTTP $status");
  $fill('custom_9[0][value]', 'Difference Engines');
  $field('custom_14[cum_laude]')?->check();
  $field('custom_14[deans_list]')?->check();
  $fill('custom_12[0][value]', '3.75');
  $setDate('custom_10[0][value][date]', '2017-09-01');
  $fill('custom_15[0][value]', "Line one\nLine two");
  $save();
  $edited = $row('civicrm_value_education_his_4', $id);
  $check($edited['field_of_study_9'] === 'Difference Engines', 'edit saves a changed text field', (string) $edited['field_of_study_9']);
  $check(in_array($edited['honors_14'], ["\x01cum_laude\x01deans_list\x01", "\x01deans_list\x01cum_laude\x01"], TRUE), 'edit saves two checked honors', json_encode($edited['honors_14']));
  $check((float) $edited['gpa_12'] === 3.75, 'edit saves a float', (string) $edited['gpa_12']);
  $check(str_starts_with((string) $edited['start_date_10'], '2017-09-01') && str_starts_with((string) $edited['end_date_11'], '2021-06-15'), 'dates: the new one stored, the old one unchanged', "{$edited['start_date_10']} / {$edited['end_date_11']}");
  $check($edited['notes_15'] === "Line one\r\nLine two" || $edited['notes_15'] === "Line one\nLine two", 'edit saves a multi-line memo', json_encode($edited['notes_15']));
  $check($edited['school_7'] === $school && $edited['degree_8'] === $degree && $edited['graduated_13'] == 1, 'fields left alone keep their values', json_encode(array_intersect_key($edited, array_flip(['school_7', 'degree_8', 'graduated_13']))));

  $visit("/civicrm-cv-ce-education/$id/edit");
  $save();
  $again = $row('civicrm_value_education_his_4', $id);
  $changes = array_diff_assoc(array_map('strval', $again), array_map('strval', $edited));
  $check($changes === [], 'saving the edit form unchanged changes nothing', json_encode($changes));

  // Clearing: every honor unchecked, a text field and a date emptied.
  $visit("/civicrm-cv-ce-education/$id/edit");
  $field('custom_14[cum_laude]')?->uncheck();
  $field('custom_14[deans_list]')?->uncheck();
  $fill('custom_9[0][value]', '');
  $setDate('custom_11[0][value][date]', '');
  $save();
  $cleared = $row('civicrm_value_education_his_4', $id);
  $check(in_array($cleared['honors_14'], [NULL, '', "\x01\x01"], TRUE), 'unchecking every honor clears the field', json_encode($cleared['honors_14']));
  $check(in_array($cleared['field_of_study_9'], [NULL, ''], TRUE), 'emptying a text field clears it', json_encode($cleared['field_of_study_9']));
  // CRM_Core_DAO reads a NULL column as ''.
  $check(in_array($cleared['end_date_11'], [NULL, ''], TRUE), 'emptying a date clears it', json_encode($cleared['end_date_11']));

  $status = $visit("/civicrm-cv-ce-education/$id/delete");
  $check($status === 200, 'delete form opens', "HTTP $status");
  $save('Delete');
  $check($row('civicrm_value_education_his_4', $id) === NULL, 'confirming deletes the row', $session->getCurrentUrl() . ' ' . $messages());
}

echo "\n2. education record with the parent chosen by autocomplete (admin)\n";
$status = $visit('/civicrm-cv-ce-education/add');
$check($status === 200, 'add form without a parent opens', "HTTP $status");
$chosen = $autocomplete('entity_id[0][target_id]', 'Erik Terr', 'Erik Terrell');
$check($chosen !== NULL && preg_match('/\((\d+)\)$/', $chosen, $m) === 1, 'the autocomplete offers and fills the contact', var_export($chosen, TRUE));
$parent = isset($m[1]) ? (int) $m[1] : 0;
$fill('custom_7[0][value]', "Autocomplete Academy $parent");
$save();
$id = $recordId();
$saved = $id ? $row('civicrm_value_education_his_4', $id) : NULL;
$check($saved && (int) $saved['entity_id'] === $parent, 'the record belongs to the chosen contact', $saved ? "entity_id {$saved['entity_id']}" : $session->getCurrentUrl() . ' ' . $messages());
if ($saved) {
  $created[] = ['civicrm_value_education_his_4', $id];
}

// A household cannot hold an Individual-only group.
$visit('/civicrm-cv-ce-education/add');
$autocomplete('entity_id[0][target_id]', 'Terrell fam', 'Terrell family');
$fill('custom_7[0][value]', 'Household Academy');
$before = count($rows('SELECT id FROM civicrm_value_education_his_4'));
$save();
$check(count($rows('SELECT id FROM civicrm_value_education_his_4')) === $before && $recordId() === NULL, 'a household is refused with a message', $messages());

echo "\n3. limits and required fields (admin)\n";
$before = count($rows('SELECT id FROM civicrm_value_skills_5 WHERE entity_id = 1'));
$visit('/civicrm-cv-ce-skills/add?entity_id=1');
$fill('custom_18[0][value]', 'One skill too many');
$save();
$check(count($rows('SELECT id FROM civicrm_value_skills_5 WHERE entity_id = 1')) === $before && $recordId() === NULL && !$fatal(), "a 6th skill (max 5) is refused with a message", $messages());

$visit('/civicrm-cv-ce-board-terms/add?entity_id=47');
$check($field('custom_26[0][target_id]') !== NULL, 'board term add form offers the required Board Member');
$before = count($rows('SELECT id FROM civicrm_value_board_terms_6 WHERE entity_id = 47'));
$setDate('custom_28[0][value][date]', '2026-01-01');
$save();
$check(count($rows('SELECT id FROM civicrm_value_board_terms_6 WHERE entity_id = 47')) === $before && !$fatal(), 'a board term without its required member is refused', $messages());
$member = $autocomplete('custom_26[0][target_id]', 'Erik Terr', 'Erik Terrell');
$save();
$id = $recordId();
$saved = $id ? $row('civicrm_value_board_terms_6', $id) : NULL;
$check($saved && preg_match('/\((\d+)\)$/', (string) $member, $m) && (int) $saved['board_member_26'] === (int) $m[1], 'with the member chosen by autocomplete it saves', $saved ? "board_member_26 {$saved['board_member_26']}, term_start {$saved['term_start_28']}" : $messages());
if ($saved) {
  $created[] = ['civicrm_value_board_terms_6', $id];
  $check(str_starts_with((string) $saved['term_start_28'], '2026-01-01'), 'the date entered before the failed submit is kept', (string) $saved['term_start_28']);
}

echo "\n4. parents saved unchanged (admin)\n";
foreach ([['Contact', 28, '/civicrm-contact/28/edit'], ['Contact', 47, '/civicrm-contact/47/edit'], ['Event', 1, '/civicrm-event/1/edit']] as [$entity, $id, $path]) {
  $before = $snapshot($entity, $id);
  $status = $visit($path);
  if ($status !== 200 || $fatal()) {
    $check(FALSE, "$entity $id edit form opens", "HTTP $status");
    continue;
  }
  $save();
  $after = $snapshot($entity, $id);
  $changes = $diff($before, $after);
  $check($changes === '' && !$fatal(), "$entity $id saved unchanged through $path changes nothing", $changes ?: $messages());
}

echo "\n4b. clearing a contact's single-record values (admin, contact 28)\n";
$original = $rows('SELECT * FROM civicrm_value_profile_all_f_12 WHERE entity_id = 28')[0];
$visit('/civicrm-contact/28/edit');
foreach ($page()->findAll('css', 'input[type="checkbox"][name^="custom_59["]') as $box) {
  $box->uncheck();
}
// Native gives the field unlimited cardinality: a multiple select named
// custom_60[]. In auto and api3 it holds one value (punch list J14), and "none"
// is the single select's - None -.
if ($multiple = $field('custom_60[]')) {
  $multiple->setValue([]);
}
else {
  $field('custom_60')?->selectOption('_none');
}
$fill('custom_56[0][value]', '');
$fill('custom_62[0][value]', '');
$setDate('custom_68[0][value][date]', '');
$save();
$cleared = $rows('SELECT * FROM civicrm_value_profile_all_f_12 WHERE entity_id = 28')[0];
$expectCleared = ['checkboxes_59' => 'every checkbox unchecked', 'multi_select_60' => 'every option deselected', 'text_56' => 'text emptied', 'integer_62' => 'integer emptied', 'date_68' => 'date emptied'];
foreach ($expectCleared as $column => $what) {
  $check(in_array($cleared[$column], [NULL, '', "\x01\x01"], TRUE), "contact: $what clears $column", json_encode($original[$column]) . ' -> ' . json_encode($cleared[$column]));
}
$untouched = array_diff_key($original, $expectCleared);
$changed = array_diff_assoc(array_map('strval', array_intersect_key($cleared, $untouched)), array_map('strval', $untouched));
$check($changed === [], 'contact: the other values are untouched', json_encode($changed));
// Put the row back as it was.
$sets = [];
$params = [];
foreach (array_keys($expectCleared) as $i => $column) {
  $sets[] = "$column = %" . ($i + 1);
  $params[$i + 1] = $original[$column] === NULL ? ['', 'String'] : [$original[$column], 'String'];
}
\CRM_Core_DAO::executeQuery('UPDATE civicrm_value_profile_all_f_12 SET ' . implode(', ', $sets) . ' WHERE entity_id = 28', $params);
\Drupal::service('cache_tags.invalidator')->invalidateTags(['civicrm_contact:28']);

echo "\n5. a record edited by smoke_editor, with check_permissions on and off\n";
$id = (int) \Civi\Api4\CustomValue::create('ce_education', FALSE)->addValue('entity_id', 28)->addValue('school', 'Permission Smoke College')->execute()->first()['id'];
$created[] = ['civicrm_value_education_his_4', $id];
\Drupal::service('cache_tags.invalidator')->invalidateTags(['civicrm_contact:28']);
foreach ([FALSE, TRUE] as $on) {
  \Drupal::configFactory()->getEditable('civicrm_entity.settings')->set('check_permissions', $on)->save();
  $loginAs('smoke_editor');
  $status = $visit("/civicrm-cv-ce-education/$id/edit");
  $label = 'check_permissions ' . ($on ? 'on' : 'off');
  if ($status !== 200 || $fatal()) {
    $check(FALSE, "smoke_editor opens the edit form ($label)", "HTTP $status " . $messages());
    continue;
  }
  $value = 'Edited by smoke_editor, ' . $label;
  $fill('custom_9[0][value]', $value);
  $save();
  $stored = $row('civicrm_value_education_his_4', $id)['field_of_study_9'] ?? NULL;
  $check($stored === $value, "smoke_editor's edit is saved ($label)", json_encode($stored) . ' ' . $messages());
  $check(!$fatal(), "smoke_editor lands on contact 28 after saving ($label)", $session->getCurrentUrl());
}
\Drupal::configFactory()->getEditable('civicrm_entity.settings')->set('check_permissions', FALSE)->save();

echo "\n6. the contact page's add action (admin)\n";
$loginAs('admin');
$visit('/civicrm-contact/28');
$links = [];
foreach ($page()->findAll('css', 'a[href*="/add?entity_id=28"]') as $link) {
  $links[] = trim($link->getText()) . ' ' . $link->getAttribute('href');
}
$check($links !== [], 'contact 28 links to adding its records', implode('; ', $links));

// Clean up.
foreach ($created as [$table, $id]) {
  \CRM_Core_DAO::executeQuery("DELETE FROM $table WHERE id = $id");
}
\Drupal::service('cache_tags.invalidator')->invalidateTags(['civicrm_contact:28', 'civicrm_contact:47', 'civicrm_contact_list']);
printf("\ncleaned up %d records\n", count($created));

$errors = \Drupal::database()->query("SELECT type, message, variables FROM {watchdog} WHERE timestamp >= :t AND severity <= 4 ORDER BY wid", [':t' => $started])->fetchAll();
$check(count($errors) === 0, 'no errors or warnings logged', count($errors) . ' logged');
foreach (array_slice($errors, 0, 15) as $error) {
  $variables = @unserialize($error->variables, ['allowed_classes' => FALSE]) ?: [];
  printf("    %s: %s\n", $error->type, mb_strimwidth(strip_tags(strtr($error->message, array_map('strval', array_filter($variables, 'is_scalar')))), 0, 300, '...'));
}

printf("\n%d passed, %d failed\n", $pass, count($fail));
