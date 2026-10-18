<?php

/**
 * @file
 * Punch list J4: the empty operators of filters on custom value tables.
 *
 *   ddev drush --uri=https://drupal-civi-composer-exp-d11.ddev.site \
 *     php:script scripts/smoke/j4-smoke.php
 *
 * Read-only. All of it runs on Views without relationships, since a
 * relationship makes FilterPluginBase::init() allow empty on every filter.
 *
 * 1. Every active custom field's filter on the `civicrm_value_*` table its
 *    parent describes allows empty, and offers "Is empty (NULL)".
 * 2. On contacts, `empty` and `not empty` on each `ce_profile_all` column and
 *    on `ce_education`'s school (a multi-record group's table, joined from the
 *    parent) list as many contacts as SQL counts.
 * 3. The directory page with its own filters, the text filter set to
 *    `not empty`: the first pass got every contact (203) instead of 80.
 * 4. As uid 1, the Views UI's forms for the directory's text and size filters
 *    offer both operators.
 */

use Drupal\Core\Render\RenderContext;
use Drupal\user\Entity\User;
use Drupal\views\Views;
use GuzzleHttp\Cookie\CookieJar;

const BASE = 'https://drupal-civi-composer-exp-d11.ddev.site';

\Drupal::service('civicrm')->initialize();
$renderer = \Drupal::service('renderer');
$viewsData = \Drupal::service('views.views_data');
$sql = static fn(string $query) => (int) \CRM_Core_DAO::singleValueQuery($query);
$pass = 0;
$fail = [];
$check = static function (bool $ok, string $label, string $detail = '') use (&$pass, &$fail): void {
  $ok ? $pass++ : $fail[] = $label . ($detail ? " ($detail)" : '');
  printf("  [%s] %s%s\n", $ok ? 'ok' : 'FAIL', $label, $detail ? " -- $detail" : '');
};
$note = static fn(string $text) => printf("  [--] %s\n", $text);

$fields = \Civi\Api4\CustomField::get(FALSE)
  ->addSelect('name', 'column_name', 'data_type', 'html_type', 'custom_group_id.name', 'custom_group_id.table_name')
  ->addWhere('is_active', '=', TRUE)
  ->addWhere('custom_group_id.is_active', '=', TRUE)
  ->addOrderBy('custom_group_id.name')
  ->addOrderBy('id')
  ->execute();

echo "1. Views data: custom field filters allow empty\n";
$described = 0;
$undescribed = [];
$byPlugin = [];
foreach ($fields as $field) {
  $table = $field['custom_group_id.table_name'];
  $column = $field['column_name'];
  $label = "{$field['custom_group_id.name']}.{$field['name']} ({$field['data_type']}/{$field['html_type']})";
  $definition = $viewsData->get($table)[$column]['filter'] ?? NULL;
  if ($definition === NULL) {
    $undescribed[] = $label;
    continue;
  }
  $described++;
  // Initialised on the directory View, which has no relationship: some
  // handlers decide in init() (BooleanOperator always allows empty).
  $view = Views::getView('ce_smoke_contact_directory');
  $view->setDisplay('page_1');
  $options = ['id' => $column, 'table' => $table, 'field' => $column];
  $handler = Views::handlerManager('filter')->getHandler($options);
  $handler->init($view, $view->display_handler, $options);
  $operators = array_keys((fn() => $this->operators())->call($handler));
  $byPlugin[$definition['id']] = ($byPlugin[$definition['id']] ?? 0) + 1;
  $offered = in_array('empty', $operators, TRUE) && in_array('not empty', $operators, TRUE);
  $check(!empty($definition['allow empty']) && $offered, $label, $definition['id'] . (!empty($definition['allow empty']) ? '' : ', no allow empty in Views data') . ($offered ? '' : ', empty operators not offered'));
}
$note("$described filters described; by plugin: " . json_encode($byPlugin));
if ($undescribed) {
  $note('not described by any parent (expected for groups whose parent type is not exposed): ' . implode(', ', $undescribed));
}

/**
 * Runs the directory View with the given filters, and only the contact name.
 */
$total = static function (array $filters, bool $ownFilters = FALSE) use ($renderer): int {
  $view = Views::getView('ce_smoke_contact_directory');
  $view->setDisplay('page_1');
  $display = $view->display_handler;
  if (!$ownFilters) {
    $display->overrideOption('filters', $filters);
  }
  else {
    $own = $display->getOption('filters');
    $own = array_replace_recursive($own, $filters);
    $display->overrideOption('filters', $own);
  }
  $display->overrideOption('fields', ['display_name' => $display->getOption('fields')['display_name']]);
  $display->overrideOption('relationships', []);
  $view->get_total_rows = TRUE;
  $renderer->executeInRenderContext(new RenderContext(), static fn() => $view->execute());
  return (int) ($view->total_rows ?? count($view->result));
};
$filter = static fn(string $table, string $column, string $operator) => [
  $column => ['id' => $column, 'table' => $table, 'field' => $column, 'operator' => $operator],
];

echo "\n2. empty / not empty on contacts, against SQL\n";
$contacts = $sql('SELECT COUNT(*) FROM civicrm_contact');
$note("$contacts contacts");
foreach ($fields as $field) {
  $group = $field['custom_group_id.name'];
  if (!($group === 'ce_profile_all' || ($group === 'ce_education' && $field['name'] === 'school'))) {
    continue;
  }
  $table = $field['custom_group_id.table_name'];
  $column = $field['column_name'];
  $filled = $sql("SELECT COUNT(DISTINCT c.id) FROM civicrm_contact c JOIN $table t ON t.entity_id = c.id WHERE t.$column IS NOT NULL");
  $unfilled = $sql("SELECT COUNT(DISTINCT c.id) FROM civicrm_contact c LEFT JOIN $table t ON t.entity_id = c.id WHERE t.$column IS NULL");
  foreach (['not empty' => $filled, 'empty' => $unfilled] as $operator => $expected) {
    try {
      $got = $total($filter($table, $column, $operator));
      $check($got === $expected, "$group.{$field['name']} $operator", "$got vs $expected");
    }
    catch (\Throwable $e) {
      $check(FALSE, "$group.{$field['name']} $operator", get_class($e) . ': ' . $e->getMessage());
    }
  }
}

echo "\n3. the directory page, its text filter set to not empty\n";
$view = Views::getView('ce_smoke_contact_directory');
$view->setDisplay('page_1');
$own = $view->display_handler->getOption('filters');
$note('configured: ' . $own['has_profile']['operator'] . ' ' . json_encode($own['has_profile']['value']));
$profileTable = \CRM_Core_DAO::singleValueQuery("SELECT table_name FROM civicrm_custom_group WHERE name = 'ce_profile_all'");
$textColumn = $own['has_profile']['field'];
$expected = $sql("SELECT COUNT(*) FROM $profileTable WHERE $textColumn IS NOT NULL");
$got = $total(['has_profile' => ['operator' => 'not empty', 'value' => '']], TRUE);
$check($got === $expected, 'ce_smoke_contact_directory:page_1 with not empty', "$got vs $expected (first pass: 203)");

echo "\n4. Views UI: the directory's filter forms offer the operators\n";
$client = \Drupal::httpClient();
$jar = new CookieJar();
$login = parse_url(user_pass_reset_url(User::load(1)) . '/login', PHP_URL_PATH);
$client->get(BASE . $login, ['cookies' => $jar, 'allow_redirects' => FALSE, 'http_errors' => FALSE, 'verify' => FALSE]);
foreach (['has_profile' => 'string', 'size' => 'civicrm_entity_in_operator'] as $id => $plugin) {
  $response = $client->get(BASE . "/admin/structure/views/nojs/handler/ce_smoke_contact_directory/page_1/filter/$id", ['cookies' => $jar, 'allow_redirects' => FALSE, 'http_errors' => FALSE, 'verify' => FALSE, 'timeout' => 120]);
  $html = (string) $response->getBody();
  // Radios, or a select from ten operators up (FilterPluginBase::operatorForm()).
  preg_match('/<select[^>]*name="options\[operator\]".*?<\/select>/s', $html, $select);
  $offers = static fn(string $operator) => (bool) preg_match('/name="options\[operator\]" value="' . preg_quote($operator, '/') . '"/', $html)
    || str_contains($select[0] ?? '', 'value="' . $operator . '"');
  $check($response->getStatusCode() === 200 && $offers('empty') && $offers('not empty'), "filter $id ($plugin)", 'HTTP ' . $response->getStatusCode() . ', empty ' . json_encode($offers('empty')) . ', not empty ' . json_encode($offers('not empty')));
}

printf("\n%d passed, %d failed\n", $pass, count($fail));
foreach ($fail as $f) {
  echo "  - $f\n";
}
if ($fail) {
  exit(1);
}
