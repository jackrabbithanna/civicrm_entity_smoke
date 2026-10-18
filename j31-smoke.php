<?php

/**
 * @file
 * Punch list J31: "is one of" filters on multi-value custom fields.
 *
 *   ddev drush --uri=https://drupal-civi-composer-exp-d11.ddev.site \
 *     php:script scripts/smoke/j31-smoke.php
 *
 * Read-only. Multi-value custom fields are stored padded with \x01
 * (`\x01wed\x01fri\x01`), and the `utf8mb4_unicode_ci` collation ignores the
 * \x01: a plain `IN ('wed')` matches only a row holding `wed` alone. The
 * filter must know the field is multi-valued and match each value.
 *
 * 1. Views data: a custom field's filter is `multi` exactly when CiviCRM
 *    says the field is serialized (CRM_Core_BAO_CustomField::isSerialized()).
 * 2. On contacts, for every serialized field described on a value table and
 *    every value it holds: `in` and `not in` that value, and `in` its two
 *    commonest values, list as many contacts as SQL counts. SQL compares the
 *    padded value in binary. Since J2 (2026-10-16) `not in` lists contacts
 *    with no value too, a record-less contact or an empty list, as core's
 *    filter on a multi-value Drupal field does; before, SQL's NOT left them
 *    out.
 */

use Drupal\Core\Render\RenderContext;
use Drupal\views\Views;

\Drupal::service('civicrm')->initialize();
$renderer = \Drupal::service('renderer');
$viewsData = \Drupal::service('views.views_data');
$sql = static fn(string $query, array $params = []) => (int) \CRM_Core_DAO::singleValueQuery($query, $params);
$pass = 0;
$fail = [];
$check = static function (bool $ok, string $label, string $detail = '') use (&$pass, &$fail): void {
  $ok ? $pass++ : $fail[] = $label . ($detail ? " ($detail)" : '');
  printf("  [%s] %s%s\n", $ok ? 'ok' : 'FAIL', $label, $detail ? " -- $detail" : '');
};
$note = static fn(string $text) => printf("  [--] %s\n", $text);

$fields = \Civi\Api4\CustomField::get(FALSE)
  ->addSelect('name', 'column_name', 'data_type', 'html_type', 'serialize', 'custom_group_id.name', 'custom_group_id.table_name')
  ->addWhere('is_active', '=', TRUE)
  ->addWhere('custom_group_id.is_active', '=', TRUE)
  ->addOrderBy('custom_group_id.name')
  ->addOrderBy('id')
  ->execute();

echo "1. Views data: multi follows serialize\n";
$serialized = [];
$plain = 0;
foreach ($fields as $field) {
  $definition = $viewsData->get($field['custom_group_id.table_name'])[$field['column_name']]['filter'] ?? NULL;
  if ($definition === NULL) {
    continue;
  }
  $label = "{$field['custom_group_id.name']}.{$field['name']} ({$field['data_type']}/{$field['html_type']}, serialize {$field['serialize']})";
  if (\CRM_Core_BAO_CustomField::isSerialized($field)) {
    $serialized[] = $field;
    $check(!empty($definition['multi']), $label, $definition['id'] . ', multi ' . json_encode($definition['multi'] ?? NULL));
  }
  elseif (!empty($definition['multi'])) {
    $check(FALSE, $label, $definition['id'] . ' is multi, but the field is not serialized');
  }
  else {
    $plain++;
  }
}
$note(count($serialized) . " serialized fields; $plain others are not multi");

/**
 * Runs the directory View with one filter, and only the contact name.
 */
$total = static function (string $table, string $column, string $operator, array $values) use ($renderer): int {
  $view = Views::getView('ce_smoke_contact_directory');
  $view->setDisplay('page_1');
  $display = $view->display_handler;
  $display->overrideOption('filters', [
    $column => ['id' => $column, 'table' => $table, 'field' => $column, 'operator' => $operator, 'value' => array_combine($values, $values)],
  ]);
  $display->overrideOption('fields', ['display_name' => $display->getOption('fields')['display_name']]);
  $display->overrideOption('relationships', []);
  $view->get_total_rows = TRUE;
  $renderer->executeInRenderContext(new RenderContext(), static fn() => $view->execute());
  return (int) ($view->total_rows ?? count($view->result));
};

echo "\n2. in / not in on contacts, against SQL\n";
foreach ($serialized as $field) {
  $table = $field['custom_group_id.table_name'];
  $column = $field['column_name'];
  $name = "{$field['custom_group_id.name']}.{$field['name']}";
  // Every value held, commonest first.
  $held = [];
  $dao = \CRM_Core_DAO::executeQuery("SELECT $column v FROM $table WHERE $column IS NOT NULL");
  while ($dao->fetch()) {
    foreach (array_filter(explode(\CRM_Core_DAO::VALUE_SEPARATOR, $dao->v), 'strlen') as $value) {
      $held[$value] = ($held[$value] ?? 0) + 1;
    }
  }
  arsort($held);
  $held = array_map('strval', array_keys($held));
  $holding = static fn(string $value) => ["%\x01$value\x01%", 'String'];
  $cases = [];
  foreach ($held as $value) {
    $cases[] = ['in', [$value], $sql("SELECT COUNT(DISTINCT c.id) FROM civicrm_contact c JOIN $table t ON t.entity_id = c.id WHERE BINARY t.$column LIKE %1", [1 => $holding($value)])];
    $cases[] = ['not in', [$value], $sql("SELECT COUNT(DISTINCT c.id) FROM civicrm_contact c LEFT JOIN $table t ON t.entity_id = c.id WHERE t.$column IS NULL OR BINARY t.$column NOT LIKE %1", [1 => $holding($value)])];
  }
  if (count($held) > 1) {
    [$a, $b] = $held;
    $cases[] = ['in', [$a, $b], $sql("SELECT COUNT(DISTINCT c.id) FROM civicrm_contact c JOIN $table t ON t.entity_id = c.id WHERE BINARY t.$column LIKE %1 OR BINARY t.$column LIKE %2", [1 => $holding($a), 2 => $holding($b)])];
  }
  foreach ($cases as [$operator, $values, $expected]) {
    $label = "$name $operator [" . implode(', ', $values) . ']';
    try {
      $got = $total($table, $column, $operator, $values);
      $check($got === $expected, $label, "$got vs $expected");
    }
    catch (\Throwable $e) {
      $check(FALSE, $label, get_class($e) . ': ' . $e->getMessage());
    }
  }
}

printf("\n%d passed, %d failed\n", $pass, count($fail));
foreach ($fail as $f) {
  echo "  - $f\n";
}
if ($fail) {
  exit(1);
}
