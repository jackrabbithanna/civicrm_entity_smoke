<?php

/**
 * @file
 * Checks the smoke site in whatever api_backend is configured.
 *
 *   ddev drush --uri=https://drupal-civi-composer-exp-d11.ddev.site \
 *     php:script scripts/smoke/modes-smoke.php
 *
 * Run by modes-smoke.sh after each switch. What it expects follows the
 * design (docs/multi-record-phase-2-plan.md, decision 3): record types exist
 * in `auto` and `api4_native` and not in `api3`; the parent's multi-record
 * fields are read-only lists of every record outside `api3`, and one value in
 * `api3`, as in 4.0.x. Writes it makes are undone.
 */

use Drupal\civicrm_entity\CustomRecord\CustomRecordTypeRegistry;
use Drupal\user\Entity\User;
use Drupal\views\Views;
use GuzzleHttp\Cookie\CookieJar;

const BASE = 'https://drupal-civi-composer-exp-d11.ddev.site';

\Drupal::service('civicrm')->initialize();
$etm = \Drupal::entityTypeManager();
$mode = \Drupal::config('civicrm_entity.settings')->get('api_backend');
$records = $mode !== 'api3';
$pass = 0;
$fail = [];
$check = static function (bool $ok, string $label, string $detail = '') use (&$pass, &$fail): void {
  $ok ? $pass++ : $fail[] = $label;
  printf("  [%s] %s%s\n", $ok ? 'ok' : 'FAIL', $label, $detail ? " -- $detail" : '');
};
$note = static fn(string $text) => printf("  [--] %s\n", $text);
$sql = static fn(string $query) => \CRM_Core_DAO::singleValueQuery($query);
$started = time();
echo "api_backend: $mode\n";

echo "\n1. record types and entity definitions\n";
$groups = [];
foreach (\CRM_Core_BAO_CustomGroup::getAll(['is_multiple' => TRUE, 'is_active' => TRUE]) as $group) {
  if (!empty($group['fields'])) {
    $groups[$group['name']] = $group['table_name'];
  }
}
$defined = array_values(array_filter(array_keys($etm->getDefinitions()), static fn($t) => str_starts_with($t, 'civicrm_cv_')));
$installed = array_values(array_filter(array_keys(\Drupal::service('entity.last_installed_schema.repository')->getLastInstalledDefinitions()), static fn($t) => str_starts_with($t, 'civicrm_cv_')));
$check(count($defined) === ($records ? count($groups) : 0), 'record types defined', count($defined) . ' defined, ' . count($groups) . ' groups');
$check(count($installed) === count($defined), 'record types installed as defined', count($installed) . ' installed');
$changes = \Drupal::entityDefinitionUpdateManager()->getChangeSummary();
$summary = [];
foreach ($changes as $type => $list) {
  $summary[] = "$type: " . implode('; ', array_map('strip_tags', array_map('strval', $list)));
}
$check($changes === [], 'no entity definition updates pending', mb_strimwidth(implode(' | ', $summary), 0, 600, '...'));

echo "\n2. status report\n";
\Drupal::moduleHandler()->loadInclude('civicrm_entity', 'install');
$requirements = \Drupal::moduleHandler()->invoke('civicrm_entity', 'runtime_requirements') ?? [];
if (!$requirements && function_exists('civicrm_entity_requirements')) {
  $requirements = civicrm_entity_requirements('runtime');
}
foreach ($requirements as $key => $requirement) {
  $severity = $requirement['severity'] ?? 0;
  $severity = $severity instanceof \UnitEnum ? strtolower($severity->name) : (['info', 'ok', 'warning', 'error'][$severity + 1] ?? (string) $severity);
  $description = $requirement['description'] ?? '';
  if (is_array($description)) {
    $description = \Drupal::service('renderer')->renderInIsolation($description);
  }
  $note(sprintf('%s [%s] %s: %s', $key, $severity, strip_tags((string) ($requirement['title'] ?? '')), mb_strimwidth(trim(preg_replace('/\s+/', ' ', strip_tags((string) $description))), 0, 300, '...')));
}

echo "\n3. the parent's multi-record fields (contact 28, School)\n";
$school = \Civi\Api4\CustomField::get(FALSE)->addSelect('id', 'column_name')->addWhere('custom_group_id:name', '=', 'ce_education')->addWhere('name', '=', 'school')->execute()->first();
$definition = $etm->getStorage('civicrm_contact')->loadUnchanged(28)->getFieldDefinition('custom_' . $school['id']);
$contact = $etm->getStorage('civicrm_contact')->loadUnchanged(28);
$values = array_column($contact->get('custom_' . $school['id'])->getValue(), 'value');
$expected = $sql("SELECT COUNT({$school['column_name']}) FROM civicrm_value_education_his_4 WHERE entity_id = 28");
if ($records) {
  $check($definition->getFieldStorageDefinition()->getCardinality() === -1 && $definition->isReadOnly(), "custom_{$school['id']} is a read-only list");
  $check(count($values) === (int) $expected, "custom_{$school['id']} lists every record", count($values) . " vs $expected: " . implode(', ', $values));
}
else {
  $check($definition->getFieldStorageDefinition()->getCardinality() === 1, "custom_{$school['id']} holds one value, as in 4.0.x");
  $note("custom_{$school['id']}: " . json_encode($values) . " ($expected records)");
}

echo "\n4. Views\n";
foreach ($etm->getStorage('view')->loadMultiple() as $view_id => $view) {
  if (!str_starts_with($view_id, 'ce_smoke_')) {
    continue;
  }
  $executable = Views::getView($view_id);
  $broken = [];
  foreach (array_keys($view->get('display')) as $display_id) {
    if (!$executable->setDisplay($display_id)) {
      continue;
    }
    foreach (['field', 'filter', 'sort', 'argument', 'relationship'] as $type) {
      foreach ($executable->getHandlers($type, $display_id) as $id => $handler) {
        $plugin = $executable->display_handler->getHandler($type, $id);
        if (!$plugin || $plugin->broken()) {
          $broken["$display_id:$type:$id"] = TRUE;
        }
      }
    }
  }
  $status = $view->status() ? 'enabled' : 'DISABLED';
  $base = $view->get('base_table');
  $expectBroken = !$records && str_starts_with($base, 'civicrm_cv_');
  $check($expectBroken || $broken === [], "$view_id ($base, $status) has no broken handlers", implode(', ', array_slice(array_keys($broken), 0, 6)) . (count($broken) > 6 ? ', ...' : ''));
}

echo "\n5. pages as admin\n";
$client = \Drupal::httpClient();
$jar = new CookieJar();
$options = ['cookies' => $jar, 'allow_redirects' => FALSE, 'http_errors' => FALSE, 'verify' => FALSE, 'timeout' => 120];
$etm->getStorage('user')->resetCache();
$client->get(BASE . parse_url(user_pass_reset_url(User::load(1)) . '/login', PHP_URL_PATH), $options);
$pages = ['/civicrm-contact/28', '/civicrm-contact/28/edit', '/civicrm-contact/47', '/civicrm-event/1', '/civicrm-event/1/edit', '/smoke/education', '/smoke/skills', '/smoke/skills/summary', '/smoke/board-terms', '/smoke/directory', '/smoke/directory/cards', '/smoke/event-sessions', '/smoke/vehicles', '/smoke/memberships-history', '/admin/structure/civicrm-entity/settings', '/admin/reports/status'];
if ($records) {
  $pages = array_merge($pages, ['/civicrm-cv-ce-education/47', '/civicrm-cv-ce-education/47/edit', '/civicrm-cv-ce-education/add?entity_id=28', '/civicrm-cv-ce-skills/1', '/civicrm-cv-ce-event-sessions/1', '/admin/structure/civicrm-entity/civicrm-cv-ce-education']);
}
foreach ($pages as $path) {
  $response = $client->get(BASE . $path, $options);
  $body = (string) $response->getBody();
  $status = $response->getStatusCode();
  $error = str_contains($body, 'The website encountered an unexpected error') || preg_match('/(Warning|Notice|Deprecated|Error): .* in .* line \d+/', strip_tags($body));
  $record_view = str_starts_with($path, '/smoke/') && !$records && in_array($path, ['/smoke/education', '/smoke/skills/summary', '/smoke/board-terms', '/smoke/event-sessions', '/smoke/vehicles', '/smoke/memberships-history'], TRUE);
  $check(($status === 200 && !$error) || ($record_view && in_array($status, [200, 404], TRUE)), "GET $path", "HTTP $status" . ($error ? ', error on page' : ''));
}

echo "\n6. writes through Drupal\n";
$text = \Civi\Api4\CustomField::get(FALSE)->addSelect('id', 'column_name')->addWhere('custom_group_id:name', '=', 'ce_profile_all')->addWhere('name', '=', 'text')->execute()->first();
$profile = static fn() => \CRM_Core_DAO::executeQuery('SELECT * FROM civicrm_value_profile_all_f_12 WHERE entity_id = 28')->fetchAll()[0];
$tables = [];
foreach (\CRM_Core_BAO_CustomGroup::getAll(['is_multiple' => TRUE]) as $group) {
  $tables[$group['name']] = [$group['table_name'], (int) $sql("SELECT MAX(id) FROM {$group['table_name']}")];
}
$original = $profile();
$contact = $etm->getStorage('civicrm_contact')->loadUnchanged(28);
$contact->set('custom_' . $text['id'], "Saved in $mode")->save();
$saved = $profile();
$check($saved[$text['column_name']] === "Saved in $mode", 'a contact single-record value saves');
$changed = [];
foreach ($original as $column => $value) {
  if ($column !== $text['column_name'] && $saved[$column] !== $value) {
    $changed[] = "$column " . json_encode($value) . ' -> ' . json_encode($saved[$column]);
  }
}
$check($changed === [], "the contact save leaves the group's other values alone", implode('; ', $changed));
$added = [];
foreach ($tables as $name => [$table, $max]) {
  $ids = array_column(\CRM_Core_DAO::executeQuery("SELECT id FROM $table WHERE id > $max")->fetchAll(), 'id');
  if ($ids) {
    $added[$name] = $ids;
  }
}
$summary = implode('; ', array_map(static fn($name, $ids) => "$name +" . count($ids), array_keys($added), $added));
if ($mode === 'api3') {
  $note('I3, kept by decision: the save added ' . ($summary ?: 'no') . ' multi-record records');
}
else {
  $check($added === [], 'the contact save adds no multi-record records', $summary);
}
// Undo: the group's row as it was, and the records the save added.
$sets = [];
$params = [];
foreach ($original as $column => $value) {
  if (!in_array($column, ['id', 'entity_id'], TRUE)) {
    $params[$i = count($params) + 1] = $value === NULL ? ['', 'String'] : [$value, 'String'];
    $sets[] = $value === NULL ? "$column = NULL" : "$column = %$i";
    if ($value === NULL) {
      unset($params[$i]);
    }
  }
}
\CRM_Core_DAO::executeQuery('UPDATE civicrm_value_profile_all_f_12 SET ' . implode(', ', $sets) . ' WHERE entity_id = 28', $params);
foreach ($added as $name => $ids) {
  \Civi\Api4\CustomValue::delete($name, FALSE)->addWhere('id', 'IN', $ids)->execute();
}
\Drupal::service('cache_tags.invalidator')->invalidateTags(['civicrm_contact:28']);
$education_before = (int) $sql('SELECT COUNT(*) FROM civicrm_value_education_his_4');
if ($records) {
  $type = CustomRecordTypeRegistry::typeIdForName('ce_education');
  $storage = $etm->getStorage($type);
  $record = $storage->create(['entity_id' => 28, 'custom_7' => "Modes Academy $mode"]);
  // Validating a reference needs a user who may view its target (punch list
  // J8); drush runs as anonymous.
  \Drupal::service('account_switcher')->switchTo(\Drupal\user\Entity\User::load(1));
  $violations = $record->validate();
  \Drupal::service('account_switcher')->switchBack();
  $check(count($violations) === 0, 'a new record validates', (string) $violations);
  $record->save();
  $id = $record->id();
  $check((string) $sql("SELECT school_7 FROM civicrm_value_education_his_4 WHERE id = " . (int) $id) === "Modes Academy $mode", 'record create', "id $id");
  $record = $storage->loadUnchanged($id);
  $record->set('custom_9', 'Updated')->save();
  $check((string) $sql("SELECT field_of_study_9 FROM civicrm_value_education_his_4 WHERE id = " . (int) $id) === 'Updated', 'record update');
  $record->delete();
  $check($sql("SELECT id FROM civicrm_value_education_his_4 WHERE id = " . (int) $id) === NULL, 'record delete');
}
$check((int) $sql('SELECT COUNT(*) FROM civicrm_value_education_his_4') === $education_before, 'no education records added or lost', "$education_before before");

echo "\n7. errors logged\n";
$errors = \Drupal::database()->query("SELECT type, message, variables FROM {watchdog} WHERE timestamp >= :t AND severity <= 3 ORDER BY wid", [':t' => $started])->fetchAll();
$check(count($errors) === 0, 'no errors logged', count($errors) . ' errors');
$seen = [];
foreach ($errors as $error) {
  $variables = @unserialize($error->variables, ['allowed_classes' => FALSE]) ?: [];
  $text = $error->type . ': ' . mb_strimwidth(strip_tags(strtr($error->message, array_map('strval', array_filter($variables, 'is_scalar')))), 0, 300, '...');
  $seen[$text] = ($seen[$text] ?? 0) + 1;
}
foreach (array_slice($seen, 0, 15, TRUE) as $text => $n) {
  printf("    %dx %s\n", $n, $text);
}

printf("\n%s: %d passed, %d failed\n", $mode, $pass, count($fail));
