<?php

/**
 * @file
 * Punch list J35 and J36: the module's Views plugins.
 *
 *   ddev drush --uri=https://drupal-civi-composer-exp-d11.ddev.site \
 *     php:script scripts/smoke/j35-smoke.php [-- <plugin id>]
 *
 * Read-only; nothing is saved.
 *
 * 1. J35: every Views plugin of the module, with its default options as the
 *    Views UI saves them, cast as a save casts them, against the config
 *    schema. The View is built in memory on a base table that has the
 *    handler, or that joins its table.
 * 2. J36: an address View filtered by state (`civicrm_entity_civicrm_address_
 *    state_province`, which could not be created on Drupal 12) lists as many
 *    addresses as SQL counts.
 */

use Drupal\Core\Config\Schema\SchemaCheckTrait;
use Drupal\views\Entity\View;
use Drupal\views\Views;

\Drupal::service('civicrm')->initialize();
$typed = \Drupal::service('config.typed');
$checker = new class { use SchemaCheckTrait; };
$managers = \Drupal::service('views.plugin_managers');
$pass = 0;
$fail = [];
$only = $extra[0] ?? NULL;

// Handlers: every Views data entry whose plugin is the module's.
$entries = [];
foreach (\Drupal::service('views.views_data')->getAll() as $table => $data) {
  $base = $data['table']['base']['field'] ?? NULL;
  foreach ($data as $field => $info) {
    if ($field === 'table' || !is_array($info)) {
      continue;
    }
    foreach (['field', 'filter', 'sort', 'argument', 'relationship'] as $type) {
      $id = $info[$type]['id'] ?? NULL;
      $definition = $id ? $managers->get($type)->getDefinition($id, FALSE) : NULL;
      if ($definition && ($definition['provider'] ?? '') === 'civicrm_entity') {
        $entries[$type][$id][] = [$table, $field, $base];
      }
    }
  }
}

$bases = [];
foreach (\Drupal::service('views.views_data')->getAll() as $table => $data) {
  if (!empty($data['table']['base'])) {
    $bases[] = $table;
  }
}

// Casts the data as saving it would, so only what a save keeps is checked.
$cast = static function (string $name, array $data) use ($typed): array {
  $config = new \Drupal\Core\Config\Config($name, new \Drupal\Core\Config\MemoryStorage(), new \Symfony\Component\EventDispatcher\EventDispatcher(), $typed);
  $config->setData(json_decode(json_encode($data), TRUE));
  $config->save();
  return $config->getRawData();
};
$report = static function (string $label, string $name, array $data) use ($typed, $checker, $cast, &$pass, &$fail): void {
  $result = $checker->checkConfigSchema($typed, $name, $cast($name, $data));
  if ($result === TRUE) {
    $pass++;
    echo "  [ok] $label\n";
    return;
  }
  $fail[] = $label;
  $paths = [];
  foreach ($result as $path => $error) {
    $paths[] = preg_replace('/^.*?display_options\./', '', $path) . " ($error)";
  }
  echo "  [FAIL] $label\n       " . implode("\n       ", $paths) . "\n";
};

$newView = static function (string $base): array {
  $storage = View::create(['id' => 'j35_survey', 'label' => 'J35', 'base_table' => $base]);
  $view = Views::executableFactory()->get($storage);
  $view->setDisplay('default');
  return [$storage, $view];
};

echo "1. Handlers\n";
foreach ($entries as $type => $plugins) {
  ksort($plugins);
  foreach ($plugins as $id => $uses) {
    if ($only && $only !== $id) {
      continue;
    }
    // Prefer an entry on a base table, so the View is built on it.
    usort($uses, static fn($a, $b) => ($b[2] !== NULL) <=> ($a[2] !== NULL));
    [$table, $field, $base] = $uses[0];
    $baseTable = $base ? $table : NULL;
    if (!$baseTable) {
      // A field on a joined table: a base that joins it.
      foreach ($bases as $candidate) {
        if (isset(Views::viewsData()->get($table)['table']['join'][$candidate])) {
          $baseTable = $candidate;
          break;
        }
      }
    }
    $label = sprintf('%-13s %-48s %s.%s (%d uses)', $type, $id, $table, $field, count($uses));
    try {
      [$storage, $view] = $newView($baseTable ?? $table);
      $handlerId = $view->addHandler('default', $type, $table, $field);
      $view->build();
      $handler = $view->display_handler->getHandler($type, $handlerId);
      $view->setHandler('default', $type, $handlerId, $handler->options);
      $report($label, 'views.view.j35_survey', $storage->toArray());
    }
    catch (\Throwable $e) {
      $fail[] = $label;
      echo "  [FAIL] $label -- " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
  }
}

echo "\n1. Display plugins\n";
foreach (['access', 'query', 'argument_default', 'argument_validator'] as $type) {
  foreach ($managers->get($type)->getDefinitions() as $id => $definition) {
    $ours = ($definition['provider'] ?? '') === 'civicrm_entity' || str_starts_with($definition['class'] ?? '', 'Drupal\\civicrm_entity\\');
    if (!$ours || ($only && $only !== $id)) {
      continue;
    }
    $label = sprintf('%-18s %s', $type, $id);
    try {
      [$storage, $view] = $newView('civicrm_contact');
      $display = $view->display_handler;
      $plugin = $managers->get($type)->createInstance($id);
      $none = [];
      $plugin->init($view, $display, $none);
      $options = $plugin->options;
      $displayOptions = &$storage->getDisplay('default')['display_options'];
      if ($type === 'access' || $type === 'query') {
        $displayOptions[$type] = ['type' => $id, 'options' => $options];
      }
      else {
        // On a contextual filter.
        $displayOptions['arguments']['id'] = [
          'id' => 'id',
          'table' => 'civicrm_contact',
          'field' => 'id',
          'plugin_id' => 'numeric',
        ] + ($type === 'argument_default'
          ? ['default_action' => 'default', 'default_argument_type' => $id, 'default_argument_options' => $options]
          : ['specify_validation' => TRUE, 'validate' => ['type' => $id, 'fail' => 'not found'], 'validate_options' => $options]);
      }
      $report($label, 'views.view.j35_survey', $storage->toArray());
    }
    catch (\Throwable $e) {
      $fail[] = $label;
      echo "  [FAIL] $label -- " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
  }
}

echo "\n2. State filter\n";
$check = static function (bool $ok, string $label, string $detail = '') use (&$pass, &$fail): void {
  $ok ? $pass++ : $fail[] = $label . ($detail ? " ($detail)" : '');
  printf("  [%s] %s%s\n", $ok ? 'ok' : 'FAIL', $label, $detail ? " -- $detail" : '');
};
$states = \CRM_Core_DAO::executeQuery('SELECT state_province_id, COUNT(*) n FROM civicrm_address WHERE state_province_id IS NOT NULL GROUP BY state_province_id ORDER BY n DESC LIMIT 2')->fetchAll();
if (count($states) < 2) {
  $check(FALSE, 'two states with addresses');
}
else {
  $ids = array_column($states, 'state_province_id');
  $expected = (int) array_sum(array_column($states, 'n'));
  try {
    [$storage, $view] = $newView('civicrm_address');
    $view->addHandler('default', 'field', 'civicrm_address', 'id');
    $view->addHandler('default', 'filter', 'civicrm_address', 'state_province_id', [
      'operator' => 'or',
      'value' => array_combine($ids, $ids),
    ]);
    $view->setItemsPerPage(0);
    $view->execute();
    $filter = $view->filter['state_province_id'];
    $check(get_class($filter) === 'Drupal\\civicrm_entity\\Plugin\\views\\filter\\StateProvince', 'the filter is the module\'s', get_class($filter));
    $check(count($filter->getValueOptions() ?? []) > 1000, 'it offers CiviCRM\'s states', (string) count($filter->getValueOptions() ?? []));
    $check(count($view->result) === $expected, 'addresses in two states', count($view->result) . " vs SQL $expected");
  }
  catch (\Throwable $e) {
    $check(FALSE, 'address View filtered by state', get_class($e) . ': ' . $e->getMessage());
  }
}

printf("\n%d passed, %d failed\n", $pass, count($fail));
foreach ($fail as $f) {
  echo "  - $f\n";
}
if ($fail) {
  exit(1);
}
