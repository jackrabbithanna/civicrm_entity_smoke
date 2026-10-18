<?php

/**
 * @file
 * Removes the configuration submodules-smoke.php builds.
 *
 *   ddev drush --uri=https://drupal-civi-composer-exp-d11.ddev.site \
 *     php:script scripts/smoke/submodules-cleanup.php
 *
 * Run while the submodules are still enabled (submodules-disable.sh does).
 * Each part is skipped when its module is already gone. Leaves the fixture's
 * own displays as they were, and deletes the event form display only if the
 * smoke script was the one to save it.
 */

use Civi\Api4\CustomValue;
use Civi\Api4\Group;
use Drupal\civicrm_entity\CustomRecord\CustomRecordTypeRegistry;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewMode;

\Drupal::service('civicrm')->initialize();
$etm = \Drupal::entityTypeManager();
$owned = \Drupal::state()->get('ce_smoke_sub.owned', []);
$eduType = CustomRecordTypeRegistry::typeIdForName('ce_education');
$done = [];

// Configuration entities, by type: deleted when present.
$configs = [
  'rules_reaction_rule' => ['ce_smoke_sub_record_insert', 'ce_smoke_sub_record_update', 'ce_smoke_sub_record_delete'],
  'search_api_index' => ['ce_smoke_sub_index'],
  'search_api_server' => ['ce_smoke_sub_db'],
  'view' => ['ce_smoke_sub_vbo_education', 'ce_smoke_sub_vbo_contacts'],
];
foreach ($configs as $type => $ids) {
  if (!$etm->hasDefinition($type)) {
    continue;
  }
  foreach ($etm->getStorage($type)->loadMultiple($ids) as $entity) {
    $entity->delete();
    $done[] = "$type {$entity->id()}";
  }
}

// View modes; their displays depend on them and go with them.
foreach ([$eduType, 'civicrm_contact', 'civicrm_event'] as $entityTypeId) {
  foreach (['ce_smoke_ds', 'ce_smoke_fg'] as $mode) {
    if ($viewMode = EntityViewMode::load("$entityTypeId.$mode")) {
      $viewMode->delete();
      $done[] = "view mode $entityTypeId.$mode";
    }
  }
}

// Field groups added to displays the smoke script does not own.
foreach (["$eduType.$eduType.add", 'civicrm_event.civicrm_event.default'] as $id) {
  $display = EntityFormDisplay::load($id);
  if (!$display) {
    continue;
  }
  if ($id === 'civicrm_event.civicrm_event.default' && !empty($owned['event_form_display'])) {
    $display->delete();
    $done[] = "form display $id (saved by the smoke script)";
    continue;
  }
  if ($display->getThirdPartySetting('field_group', 'group_ce_smoke_sub') !== NULL) {
    $display->unsetThirdPartySetting('field_group', 'group_ce_smoke_sub')->save();
    $done[] = "field group on form display $id";
  }
}

// CiviCRM data: the VBO group (its memberships go with it) and any record or
// user an interrupted run left behind.
foreach (Group::get(FALSE)->addSelect('id')->addWhere('name', '=', 'ce_smoke_sub_vbo')->execute() as $group) {
  Group::delete(FALSE)->addWhere('id', '=', $group['id'])->execute();
  $done[] = "CiviCRM group ce_smoke_sub_vbo ({$group['id']})";
}
foreach (['ce_education' => 'school', 'ce_event_sessions' => 'session_title'] as $group => $field) {
  $count = CustomValue::delete($group, FALSE)->addWhere($field, 'LIKE', 'CE Smoke Sub%')->execute()->count();
  if ($count) {
    $done[] = "$count $group records";
  }
}
$users = $etm->getStorage('user');
foreach ($users->loadMultiple($users->getQuery()->accessCheck(FALSE)->condition('name', 'ce_smoke_sub_', 'STARTS_WITH')->execute()) as $user) {
  $user->delete();
  $done[] = "user {$user->getAccountName()}";
}

\Drupal::state()->delete('ce_smoke_sub.owned');
echo $done ? "Removed:\n  " . implode("\n  ", $done) . "\n" : "Nothing to remove.\n";
