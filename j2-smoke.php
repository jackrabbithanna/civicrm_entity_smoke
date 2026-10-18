<?php

/**
 * @file
 * Punch list J2 / F-UT28 / J32: list fields in Views.
 *
 *   ddev drush --uri=https://drupal-civi-composer-exp-d11.ddev.site \
 *     php:script scripts/smoke/j2-smoke.php
 *
 * Read-only: the Views it runs are built in memory and never saved.
 *
 * A list field is one CiviCRM keeps in one column (`serialize` 1, 2 or 5)
 * and API4's shape makes multi-valued with custom storage: every list field in
 * api4_native, and a record type's list fields. Expected rows come from PHP:
 * every row's column through CRM_Core_DAO::unSerializeField(), so nothing here
 * repeats the module's SQL.
 *
 * 1. Views data: each list column has the list display, the multi-value
 *    filter in its form, the list argument and a sort; the parent-side
 *    multi-value custom columns have the list argument (J32).
 * 2. Filters and contextual filters on each list column that holds data, for
 *    its commonest items: is one of / all of / none of, empty, not empty;
 *    an item, `a+b`, `a,b`, and excluded. Rows compared by ID.
 * 3. A list field renders every item's label in one row.
 * 4. The api3 config set's Views on these tables: no broken handler on a list
 *    field, every display runs, and an exposed list filter given an item
 *    lists what PHP expects.
 */

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\civicrm_entity\SupportedEntities;
use Drupal\views\Entity\View;
use Symfony\Component\Yaml\Yaml;

\Drupal::service('civicrm')->initialize();
$renderer = \Drupal::service('renderer');
$viewsData = \Drupal::service('views.views_data');
$efm = \Drupal::service('entity_field.manager');
$pass = 0;
$fail = [];
$check = static function (bool $ok, string $label, string $detail = '') use (&$pass, &$fail): void {
  $ok ? $pass++ : $fail[] = $label . ($detail ? " ($detail)" : '');
  printf("  [%s] %s%s\n", $ok ? 'ok' : 'FAIL', $label, $detail ? " -- $detail" : '');
};
$note = static fn(string $text) => printf("  [--] %s\n", $text);
$items = static function (?string $stored, int $serialize): array {
  if ($stored === NULL) {
    return [];
  }
  $list = \CRM_Core_DAO::unSerializeField($stored, $serialize);
  return array_values(array_filter(array_map('strval', (array) $list), 'strlen'));
};

// Every list column, found independently of the module's own detection.
$lists = [];
foreach (\Drupal::entityTypeManager()->getDefinitions() as $id => $type) {
  if ($type->getProvider() !== 'civicrm_entity' || !$type->hasHandlerClass('views_data')) {
    continue;
  }
  $record = (bool) $type->get('civicrm_custom_record');
  $dao = $record ? NULL : SupportedEntities::getEntityTypeDaoClass($id);
  $daoColumns = $dao ? array_column($dao::fields(), 'name') : [];
  foreach ($efm->getBaseFieldDefinitions($id) as $name => $definition) {
    $serialize = (int) $definition->getSetting('civicrm_entity_serialize');
    if ($definition->isComputed() || !$definition->hasCustomStorage() || $definition->getCardinality() !== FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED || !in_array($serialize, [1, 2, 5], TRUE)) {
      continue;
    }
    $metadata = $definition->getSetting('civicrm_entity_field_metadata') ?: [];
    if ($record) {
      $lists[] = ['type' => $id, 'name' => $name, 'column' => $metadata['column_name'], 'table' => $metadata['table_name'], 'serialize' => $serialize, 'label' => (string) $definition->getLabel()];
    }
    elseif (!isset($metadata['custom_group_id']) && in_array($name, $daoColumns, TRUE)) {
      $lists[] = ['type' => $id, 'name' => $name, 'column' => $name, 'table' => $dao::getTableName(), 'serialize' => $serialize, 'label' => (string) $definition->getLabel()];
    }
  }
}

echo "1. Views data\n";
foreach ($lists as $list) {
  $data = $viewsData->get($list['type'])[$list['column']] ?? NULL;
  $label = "{$list['type']}.{$list['column']} (serialize {$list['serialize']})";
  if ($data === NULL) {
    $check(FALSE, $label, 'not described');
    continue;
  }
  $problems = [];
  ($data['field']['id'] ?? NULL) === 'civicrm_entity_list_field' || $problems[] = 'field ' . ($data['field']['id'] ?? '-');
  (($data['filter']['id'] ?? NULL) === 'civicrm_entity_in_operator' && !empty($data['filter']['multi']) && (int) ($data['filter']['serialize'] ?? 0) === $list['serialize']) || $problems[] = 'filter ' . json_encode(array_intersect_key($data['filter'] ?? [], array_flip(['id', 'multi', 'serialize'])));
  (($data['argument']['id'] ?? NULL) === 'civicrm_entity_list' && (int) ($data['argument']['serialize'] ?? 0) === $list['serialize']) || $problems[] = 'argument ' . ($data['argument']['id'] ?? '-');
  isset($data['sort']['id']) || $problems[] = 'no sort';
  $check(!$problems, $label, $problems ? implode('; ', $problems) : (isset($data['filter']['options callback']) ? 'options' : 'free values'));
}
$custom = \Civi\Api4\CustomField::get(FALSE)
  ->addSelect('name', 'column_name', 'html_type', 'serialize', 'custom_group_id.name', 'custom_group_id.table_name')
  ->addWhere('is_active', '=', TRUE)
  ->addWhere('custom_group_id.is_active', '=', TRUE)
  ->execute();
foreach ($custom as $field) {
  if (\CRM_Core_BAO_CustomField::isSerialized($field) && ($argument = $viewsData->get($field['custom_group_id.table_name'])[$field['column_name']]['argument'] ?? NULL)) {
    $check(($argument['id'] ?? NULL) === 'civicrm_entity_list', "J32: {$field['custom_group_id.name']}.{$field['name']} on the group table", $argument['id'] ?? '-');
  }
}
$note(count($lists) . ' list columns');

/**
 * Runs an unsaved View on a base table and returns the row IDs, sorted.
 */
$run = static function (string $baseTable, array $filters = [], array $arguments = [], array $args = [], array $exposedInput = []) use ($renderer): array {
  $view = View::create([
    'id' => 'j2_smoke',
    'base_table' => $baseTable,
    'base_field' => 'id',
    'display' => [
      'default' => [
        'id' => 'default',
        'display_plugin' => 'default',
        'display_title' => 'Default',
        'position' => 0,
        'display_options' => [
          'access' => ['type' => 'none', 'options' => []],
          'cache' => ['type' => 'none', 'options' => []],
          'pager' => ['type' => 'none', 'options' => ['offset' => 0]],
          'style' => ['type' => 'default', 'options' => []],
          'row' => ['type' => 'fields', 'options' => []],
          'fields' => [],
          'filters' => $filters,
          'arguments' => $arguments,
        ],
      ],
    ],
  ]);
  $executable = $view->getExecutable();
  $executable->setDisplay('default');
  $executable->setArguments($args);
  if ($exposedInput) {
    $executable->setExposedInput($exposedInput);
  }
  $renderer->executeInRenderContext(new RenderContext(), static fn() => $executable->execute());
  $ids = array_map(static fn($row) => (int) $row->id, $executable->result);
  sort($ids);
  return $ids;
};
$compare = static function (array $got, array $expected, string $label) use ($check): void {
  sort($expected);
  $missing = array_diff($expected, $got);
  $extra = array_diff($got, $expected);
  $check($got === $expected, $label, count($got) . ' vs ' . count($expected) . ($missing ? '; missing ' . implode(',', array_slice($missing, 0, 5)) : '') . ($extra ? '; extra ' . implode(',', array_slice($extra, 0, 5)) : ''));
};

echo "\n2. filters and contextual filters, against PHP\n";
$rowsOf = [];
foreach ($lists as $list) {
  $rows = [];
  $dao = \CRM_Core_DAO::executeQuery("SELECT id, {$list['column']} v FROM {$list['table']}");
  while ($dao->fetch()) {
    $rows[(int) $dao->id] = $items($dao->v, $list['serialize']);
  }
  $rowsOf[$list['type'] . '.' . $list['column']] = $rows;
  $counts = array_count_values(array_merge(...array_values($rows) ?: [[]]));
  arsort($counts);
  $common = array_map('strval', array_slice(array_keys($counts), 0, 4));
  $name = "{$list['type']}.{$list['column']}";
  if (!$common) {
    $note("$name holds no items in " . count($rows) . ' rows; empty checks only');
  }
  $where = static fn(callable $keep) => array_keys(array_filter($rows, $keep));
  $filter = static fn(string $operator, array $values = []) => [
    $list['column'] => ['id' => $list['column'], 'table' => $list['type'], 'field' => $list['column'], 'operator' => $operator, 'value' => $values ? array_combine($values, $values) : []],
  ];
  $argument = static fn(bool $break, bool $not) => [
    $list['column'] => ['id' => $list['column'], 'table' => $list['type'], 'field' => $list['column'], 'default_action' => 'ignore', 'break_phrase' => $break, 'not' => $not],
  ];
  try {
    $compare($run($list['type'], $filter('empty')), $where(fn($i) => !$i), "$name is empty");
    $compare($run($list['type'], $filter('not empty')), $where(fn($i) => (bool) $i), "$name is not empty");
    foreach ($common as $k => $value) {
      $compare($run($list['type'], $filter('in', [$value])), $where(fn($i) => in_array($value, $i, TRUE)), "$name is one of [$value]");
      $compare($run($list['type'], $filter('not in', [$value])), $where(fn($i) => !in_array($value, $i, TRUE)), "$name is none of [$value]");
      $compare($run($list['type'], [], $argument(FALSE, FALSE), [$value]), $where(fn($i) => in_array($value, $i, TRUE)), "$name argument $value");
      $compare($run($list['type'], [], $argument(FALSE, TRUE), [$value]), $where(fn($i) => !in_array($value, $i, TRUE)), "$name argument $value, excluded");
      if ($k === 0 && isset($common[1])) {
        $other = $common[1];
        $compare($run($list['type'], $filter('and', [$value, $other])), $where(fn($i) => in_array($value, $i, TRUE) && in_array($other, $i, TRUE)), "$name is all of [$value, $other]");
        $compare($run($list['type'], $filter('in', [$value, $other])), $where(fn($i) => in_array($value, $i, TRUE) || in_array($other, $i, TRUE)), "$name is one of [$value, $other]");
        if (preg_match('/^[\w.-]+$/u', $value . $other)) {
          $compare($run($list['type'], [], $argument(TRUE, FALSE), ["$value+$other"]), $where(fn($i) => in_array($value, $i, TRUE) || in_array($other, $i, TRUE)), "$name argument $value+$other");
          $compare($run($list['type'], [], $argument(TRUE, FALSE), ["$value,$other"]), $where(fn($i) => in_array($value, $i, TRUE) && in_array($other, $i, TRUE)), "$name argument $value,$other");
        }
      }
    }
  }
  catch (\Throwable $e) {
    $check(FALSE, $name, get_class($e) . ': ' . $e->getMessage());
  }
}

echo "\n3. the display\n";
foreach ($lists as $list) {
  $rows = $rowsOf[$list['type'] . '.' . $list['column']];
  $id = array_key_first(array_filter($rows, static fn($i) => count($i) > 1));
  if ($id === NULL) {
    continue;
  }
  $name = "{$list['type']}.{$list['column']}";
  try {
    $view = View::create([
      'id' => 'j2_smoke_display',
      'base_table' => $list['type'],
      'base_field' => 'id',
      'display' => ['default' => ['id' => 'default', 'display_plugin' => 'default', 'display_title' => 'Default', 'position' => 0, 'display_options' => [
        'access' => ['type' => 'none', 'options' => []],
        'pager' => ['type' => 'none', 'options' => ['offset' => 0]],
        'style' => ['type' => 'default', 'options' => []],
        'row' => ['type' => 'fields', 'options' => []],
        'fields' => [$list['column'] => ['id' => $list['column'], 'table' => $list['type'], 'field' => $list['column'], 'group_rows' => FALSE, 'multi_type' => 'separator', 'separator' => ' | ']],
        'filters' => ['id' => ['id' => 'id', 'table' => $list['type'], 'field' => 'id', 'operator' => '=', 'value' => ['value' => $id], 'plugin_id' => 'numeric']],
      ]]],
    ]);
    $executable = $view->getExecutable();
    $executable->setDisplay('default');
    $renderer->executeInRenderContext(new RenderContext(), static fn() => $executable->execute());
    $executable->row_index = 0;
    $html = trim(strip_tags((string) $renderer->executeInRenderContext(new RenderContext(), static fn() => $executable->field[$list['column']]->advancedRender($executable->result[0]))));
    $check(count($executable->result) === 1 && substr_count($html, ' | ') === count($rows[$id]) - 1, "$name renders " . count($rows[$id]) . " items in one row for $id", $html);
  }
  catch (\Throwable $e) {
    $check(FALSE, "$name display", get_class($e) . ': ' . $e->getMessage());
  }
}

echo "\n4. Views saved in api3 (config set)\n";
$byTable = [];
foreach ($lists as $list) {
  $byTable[$list['type']][$list['column']] = $list;
}
$dir = DRUPAL_ROOT . '/modules/contrib/civicrm_entity/tests/fixtures/config-set/api3/portable';
foreach (glob("$dir/views.view.*.yml") as $file) {
  $config = Yaml::parseFile($file);
  if (!isset($byTable[$config['base_table']])) {
    continue;
  }
  $table = $config['base_table'];
  $config['id'] .= '_j2_smoke';
  $config['status'] = TRUE;
  // Handlers on fields this site does not describe (CiviCampaign is off, so
  // campaign_id; Event's bundle without civicrm_entity_bundles) are dropped,
  // with whatever uses a dropped relationship: none is a list field, and the
  // View would not run with them.
  $dropped = [];
  foreach ($config['display'] as $displayId => &$display) {
    $droppedRelationships = [];
    foreach (['relationships', 'arguments', 'fields', 'filters', 'sorts'] as $type) {
      foreach ($display['display_options'][$type] ?? [] as $hid => $info) {
        $known = !empty($info['table']) && isset($viewsData->get($info['table'])[$info['field'] ?? '']);
        if (!$known || in_array($info['relationship'] ?? 'none', $droppedRelationships, TRUE)) {
          unset($display['display_options'][$type][$hid]);
          $dropped["$type $hid"] = TRUE;
          if ($type === 'relationships') {
            $droppedRelationships[] = $hid;
          }
        }
      }
    }
  }
  unset($display);
  if ($dropped) {
    $note("{$config['id']}: dropped " . implode(', ', array_keys($dropped)));
  }
  $ids = static function ($executable) use ($renderer): array {
    $executable->setItemsPerPage(0);
    $renderer->executeInRenderContext(new RenderContext(), static fn() => $executable->execute());
    $ids = array_map(static fn($row) => (int) $row->id, $executable->result);
    sort($ids);
    return $ids;
  };
  foreach (array_keys($config['display']) as $displayId) {
    $executable = View::create($config)->getExecutable();
    try {
      $executable->setDisplay($displayId);
      $executable->initHandlers();
      $broken = [];
      $exposed = [];
      foreach (['field', 'filter', 'argument', 'sort'] as $type) {
        foreach ($executable->getHandlers($type) as $hid => $info) {
          if ($info['table'] === $table && isset($byTable[$table][$info['field']])) {
            $handler = $executable->display_handler->getHandler($type, $hid);
            if (!$handler || $handler->broken()) {
              $broken[] = "$type $hid";
            }
            elseif ($type === 'filter' && !empty($info['exposed'])) {
              $exposed[$info['field']] = $info['expose']['identifier'];
            }
          }
        }
      }
      $check(!$broken, "{$config['id']}:$displayId list-field handlers", $broken ? 'broken: ' . implode(', ', $broken) : 'none broken');
      $unfiltered = $ids($executable);
      $check(empty($executable->build_info['fail']), "{$config['id']}:$displayId runs", count($unfiltered) . ' rows');
      foreach ($exposed as $column => $identifier) {
        $rows = array_intersect_key($rowsOf["$table.$column"], array_flip($unfiltered));
        $counts = array_count_values(array_merge(...array_values($rows) ?: [[]]));
        arsort($counts);
        if (!$counts) {
          $note("{$config['id']}:$displayId: the rows it lists hold no $column");
          continue;
        }
        $value = (string) array_key_first($counts);
        $again = View::create($config)->getExecutable();
        $again->setDisplay($displayId);
        $again->setExposedInput([$identifier => $value]);
        $compare($ids($again), array_keys(array_filter($rows, static fn($i) => in_array($value, $i, TRUE))), "{$config['id']}:$displayId exposed $column = $value");
      }
    }
    catch (\Throwable $e) {
      $check(FALSE, "{$config['id']}:$displayId", get_class($e) . ': ' . $e->getMessage());
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
