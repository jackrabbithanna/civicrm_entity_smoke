<?php

/**
 * @file
 * Punch list J34: post-update 5004 through a real `drush updb`.
 *
 *   ddev drush --uri=... php:script scripts/smoke/j34-updb.php -- inject
 *   ddev drush --uri=... updb -y
 *   ddev drush --uri=... php:script scripts/smoke/j34-updb.php -- check
 *
 * `inject` writes the contact edit form display as 4.0.x saved it: a `rows`
 * key beside the widget settings of the memo field, and the rich text memo
 * with a `civicrm_entity_textarea` widget whose `rows` is the string '5', as
 * a display saved before the widget had a schema holds it. It keeps the
 * original in `output/` and marks 5004 as not yet run. `check` compares the
 * result and puts the original back.
 */

\Drupal::service('civicrm')->initialize();
$name = 'core.entity_form_display.civicrm_contact.civicrm_contact.edit';
$file = __DIR__ . '/output/j34-original-display.json';
$storage = \Drupal::service('config.storage');
$fields = \Civi\Api4\CustomField::get(FALSE)
  ->addSelect('id', 'name')
  ->addWhere('custom_group_id.name', '=', 'ce_profile_all')
  ->addWhere('name', 'IN', ['memo', 'rich_text'])
  ->execute()
  ->column('id', 'name');
$memo = 'custom_' . $fields['memo'];
$rich = 'custom_' . $fields['rich_text'];
$action = $extra[0] ?? '';

if ($action === 'inject') {
  $data = $storage->read($name);
  file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
  $data['content'][$memo]['rows'] = 4;
  $data['content'][$rich] = [
    'type' => 'civicrm_entity_textarea',
    'weight' => 50,
    'region' => 'content',
    'rows' => 5,
    'settings' => ['rows' => '5', 'placeholder' => ''],
    'third_party_settings' => [],
  ];
  unset($data['hidden'][$rich]);
  $storage->write($name, $data);
  \Drupal::configFactory()->reset($name);
  $done = \Drupal::keyValue('post_update')->get('existing_updates', []);
  \Drupal::keyValue('post_update')->set('existing_updates', array_values(array_diff($done, ['civicrm_entity_post_update_5004_text_area_widget_settings'])));
  echo "Injected $memo.rows and $rich into $name; 5004 marked pending.\n";
  return;
}

if ($action === 'check') {
  $content = $storage->read($name)['content'];
  $results = [
    "$memo has no top-level rows" => !array_key_exists('rows', $content[$memo]),
    "$memo settings.rows still 4" => ($content[$memo]['settings']['rows'] ?? NULL) === 4,
    "$rich has no top-level rows" => !array_key_exists('rows', $content[$rich]),
    "$rich settings.rows cast to int 5" => ($content[$rich]['settings']['rows'] ?? NULL) === 5,
  ];
  foreach ($results as $label => $ok) {
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $label);
  }
  $original = json_decode(file_get_contents($file), TRUE);
  $storage->write($name, $original);
  \Drupal::configFactory()->reset($name);
  echo "Restored $name.\n";
  printf("%d passed, %d failed\n", count(array_filter($results)), count($results) - count(array_filter($results)));
  return;
}

echo "Usage: -- inject | check\n";
