<?php

/**
 * @file
 * Exercises CiviCRM Entity's six submodules against the smoke-test data.
 *
 *   scripts/smoke/submodules-enable.sh     # enables them, then runs this
 *   ddev drush --uri=https://drupal-civi-composer-exp-d11.ddev.site \
 *     php:script scripts/smoke/submodules-smoke.php
 *
 * Expects the submodules to be enabled already; a section whose module is off
 * is reported as skipped. Idempotent: every piece of configuration it needs is
 * (re)built under the `ce_smoke_sub` prefix, and every record, user and group
 * membership it creates is removed before it ends. submodules-cleanup.php
 * removes the configuration; submodules-disable.sh runs it, then uninstalls.
 *
 * Sections, in this order because saving a reaction rule can rebuild the
 * container (services are always fetched through \Drupal:: below):
 *   1. Rules        civicrm_entity_rules: plugins, entity events on a record
 *                   type for Drupal and CiviCRM writes, conditions, actions,
 *                   typed data filters.
 *   2. Bundles      civicrm_entity_bundles: event/activity bundles, the
 *                   multi-record list under a bundle, add/field UI routes,
 *                   bundle create, form display hijack, a session record.
 *   3. DS           civicrm_entity_ds: a two-column layout on a record type,
 *                   a contact, and a bundled event (copied from the root).
 *   4. Field group  civicrm_entity_field_group: fieldsets on record, contact
 *                   and bundled event displays, on the record add form and
 *                   the bundled event edit form.
 *   5. Search API   civicrm_entity_search_api: tracking a record type the way
 *                   Search API does by default, a bundle-limited event
 *                   datasource, then a DB-server index over contacts and a
 *                   record type: counts, searches, Drupal and CiviCRM writes,
 *                   a parent queued by its records' changes and records by
 *                   their parent's (punch list J18).
 *   6. VBO          civicrm_entity_vbo: the bulk form on a record View, a
 *                   delete through it, and "Add Contact to Group".
 *   7. Site         pending entity updates and watchdog errors since start.
 */

use Civi\Api4\Contact;
use Civi\Api4\CustomField;
use Civi\Api4\CustomGroup;
use Civi\Api4\CustomValue;
use Civi\Api4\Group;
use Civi\Api4\GroupContact;
use Drupal\civicrm_entity\CustomRecord\CustomRecordTypeRegistry;
use Drupal\civicrm_entity\SupportedEntities;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityFormMode;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\Render\RenderContext;
use Drupal\rules\Context\ContextConfig;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\user\Entity\User;
use Drupal\views\Entity\View;
use Drupal\views\Views;
use Drupal\views_bulk_operations\ViewsBulkOperationsBatch;

\Drupal::service('civicrm')->initialize();
$startWid = (int) \Drupal::database()->query('SELECT MAX(wid) FROM {watchdog}')->fetchField();
$etm = static fn() => \Drupal::entityTypeManager();
$sql = static fn(string $query) => \CRM_Core_DAO::singleValueQuery($query);
$on = static fn(string $module): bool => \Drupal::moduleHandler()->moduleExists($module);
$pass = 0;
$fail = [];
$skipped = [];
$check = static function (bool $ok, string $label, string $detail = '') use (&$pass, &$fail): void {
  $ok ? $pass++ : $fail[] = $label . ($detail ? " ($detail)" : '');
  printf("  [%s] %s%s\n", $ok ? 'ok' : 'FAIL', $label, $detail !== '' ? " -- $detail" : '');
};
$info = static function (string $label, string $detail = ''): void {
  printf("  [info] %s%s\n", $label, $detail !== '' ? " -- $detail" : '');
};
// Runs one check; an exception fails it with where it came from.
$attempt = static function (string $label, callable $fn) use ($check): void {
  try {
    $result = $fn();
    $check((bool) $result[0], $label, (string) ($result[1] ?? ''));
  }
  catch (\Throwable $e) {
    $check(FALSE, $label, get_class($e) . ': ' . strtok($e->getMessage(), "\n") . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
  }
};
$skip = static function (string $module) use (&$skipped): void {
  $skipped[] = $module;
  echo "  [skip] $module is not enabled\n";
};
$render = static function (array $build): string {
  $renderer = \Drupal::service('renderer');
  return (string) $renderer->executeInRenderContext(new RenderContext(), static fn() => $renderer->renderInIsolation($build));
};
$cf = static function (string $group, string $name): string {
  $id = CustomField::get(FALSE)->addSelect('id')->addWhere('custom_group_id:name', '=', $group)->addWhere('name', '=', $name)->execute()->first()['id'] ?? NULL;
  return $id ? 'custom_' . $id : throw new \RuntimeException("No custom field $group.$name");
};
$column = static fn(string $group, string $name): string => CustomField::get(FALSE)->addSelect('column_name')->addWhere('custom_group_id:name', '=', $group)->addWhere('name', '=', $name)->execute()->first()['column_name'];
$valueTable = static fn(string $group): string => CustomGroup::get(FALSE)->addSelect('table_name')->addWhere('name', '=', $group)->execute()->first()['table_name'];
// Status messages equal to a text; Rules' "Show a message" is the probe.
$messages = static fn(string $text): int => count(array_filter(\Drupal::messenger()->messagesByType('status'), static fn($m) => (string) $m === $text));
$owned = \Drupal::state()->get('ce_smoke_sub.owned', []);
// Watchdog entries a check provokes on purpose, left out of section 7's count.
$expectedWids = [];

$eduType = CustomRecordTypeRegistry::typeIdForName('ce_education');
$sessionType = CustomRecordTypeRegistry::typeIdForName('ce_event_sessions');
$school = $cf('ce_education', 'school');
$degree = $cf('ce_education', 'degree');
$eduTable = $valueTable('ce_education');
$schoolColumn = $column('ce_education', 'school');
$sessionTitle = $cf('ce_event_sessions', 'session_title');
$sessionTable = $valueTable('ce_event_sessions');
$sessionColumn = $column('ce_event_sessions', 'session_title');
$admin = User::load(1);
// Records this run creates carry this prefix, so a run that dies midway is
// cleaned up by the next one.
$marker = 'CE Smoke Sub';
$purge = static function () use ($eduTable, $schoolColumn, $sessionTable, $sessionColumn, $marker): void {
  CustomValue::delete('ce_education', FALSE)->addWhere('school', 'LIKE', "$marker%")->execute();
  CustomValue::delete('ce_event_sessions', FALSE)->addWhere('session_title', 'LIKE', "$marker%")->execute();
};
$purge();

printf("api_backend: %s; record types under test: %s, %s\n\n", \Drupal::config('civicrm_entity.settings')->get('api_backend'), $eduType, $sessionType);

// ---- 1. Rules ------------------------------------------------------------------

echo "1. Rules (civicrm_entity_rules)\n";
if (!$on('civicrm_entity_rules')) {
  $skip('civicrm_entity_rules');
}
else {
  foreach (['civicrm_entity_contact_in_group', 'civicrm_entity_drupal_user_exists', 'civicrm_contact_id_drupal_user_exists'] as $id) {
    $check(\Drupal::service('plugin.manager.condition')->hasDefinition($id), "condition $id is defined");
  }
  foreach (['civicrm_entity_user_create', 'civicrm_entity_load_linked_user'] as $id) {
    $check(\Drupal::service('plugin.manager.rules_action')->hasDefinition($id), "action $id is defined");
  }
  foreach (['FirstLast', 'firstdotlast', 'initialdotlast'] as $id) {
    $check(\Drupal::service('plugin.manager.typed_data_filter')->hasDefinition($id), "typed data filter $id is defined");
  }
  foreach (['insert', 'update', 'delete'] as $op) {
    $check(\Drupal::service('plugin.manager.rules_event')->hasDefinition("rules_entity_$op:$eduType"), "Rules event rules_entity_$op:$eduType exists");
  }

  // One reaction rule per operation, each showing its own message.
  $attempt('reaction rules on the record type save', static function () use ($eduType): array {
    foreach (['insert', 'update', 'delete'] as $op) {
      $rule = \Drupal::service('plugin.manager.rules_expression')->createRule();
      $rule->addAction('rules_system_message', ContextConfig::create()
        ->setValue('message', "ce-smoke-sub rules $op")
        ->setValue('type', 'status')
        ->setValue('repeat', TRUE));
      $storage = \Drupal::entityTypeManager()->getStorage('rules_reaction_rule');
      $config = $storage->load("ce_smoke_sub_record_$op") ?? $storage->create(['id' => "ce_smoke_sub_record_$op"]);
      $config->set('label', "CE smoke: record $op");
      $config->set('events', [['event_name' => "rules_entity_$op:$eduType"]]);
      $config->setExpression($rule);
      // May rebuild the container when the registered events change.
      $config->save();
    }
    return [TRUE];
  });
  // Rules subscribes to the events in its state when the container is
  // compiled, and only when the state service is reachable then; a `drush cr`
  // compiles it without them, and a rule saved unchanged does not rebuild it.
  // Rules' own behaviour, not CiviCRM Entity's; rebuilt here so the checks
  // below test the record events.
  if (!\Drupal::service('event_dispatcher')->hasListeners("rules_entity_insert:$eduType")) {
    $info('Rules had no listener for its registered events (container compiled without them, e.g. by drush cr); rebuilding the container');
    \Drupal::service('kernel')->rebuildContainer();
  }

  $counts = static fn() => implode(', ', array_map(static fn($op) => "$op " . $messages("ce-smoke-sub rules $op"), ['insert', 'update', 'delete']));
  $attempt('Drupal create, update, delete of a record each fire their rule once', static function () use ($etm, $eduType, $school, $marker, $messages, $counts, $sql, $eduTable): array {
    $log = [];
    \Drupal::messenger()->deleteAll();
    $storage = $etm()->getStorage($eduType);
    $record = $storage->create(['entity_id' => 28, $school => "$marker Rules"]);
    $record->save();
    $id = $record->id();
    $log[] = 'create: ' . $counts();
    $ok = $messages('ce-smoke-sub rules insert') === 1 && $messages('ce-smoke-sub rules update') === 0;
    \Drupal::messenger()->deleteAll();
    $storage->loadUnchanged($id)->set($school, "$marker Rules renamed")->save();
    $log[] = 'update: ' . $counts();
    $ok = $ok && $messages('ce-smoke-sub rules update') === 1 && $messages('ce-smoke-sub rules insert') === 0;
    \Drupal::messenger()->deleteAll();
    $storage->loadUnchanged($id)->delete();
    $log[] = 'delete: ' . $counts();
    $ok = $ok && $messages('ce-smoke-sub rules delete') === 1 && (int) $sql("SELECT COUNT(*) FROM $eduTable WHERE id = $id") === 0;
    return [$ok, implode('; ', $log)];
  });
  $attempt('CiviCRM (API4) create, update, delete of a record each fire their rule once', static function () use ($marker, $messages, $counts): array {
    $log = [];
    \Drupal::messenger()->deleteAll();
    $id = CustomValue::create('ce_education', FALSE)->addValue('entity_id', 28)->addValue('school', "$marker Rules API4")->execute()->first()['id'];
    $log[] = 'create: ' . $counts();
    $ok = $messages('ce-smoke-sub rules insert') === 1;
    \Drupal::messenger()->deleteAll();
    CustomValue::update('ce_education', FALSE)->addWhere('id', '=', $id)->addValue('school', "$marker Rules API4 renamed")->execute();
    $log[] = 'update: ' . $counts();
    $ok = $ok && $messages('ce-smoke-sub rules update') === 1;
    \Drupal::messenger()->deleteAll();
    CustomValue::delete('ce_education', FALSE)->addWhere('id', '=', $id)->execute();
    $log[] = 'delete: ' . $counts();
    $ok = $ok && $messages('ce-smoke-sub rules delete') === 1;
    return [$ok, implode('; ', $log)];
  });
  \Drupal::messenger()->deleteAll();

  // Conditions.
  $row = \CRM_Core_DAO::executeQuery("SELECT gc.group_id, gc.contact_id FROM civicrm_group_contact gc JOIN civicrm_contact c ON c.id = gc.contact_id AND c.is_deleted = 0 WHERE gc.status = 'Added' ORDER BY gc.id LIMIT 1");
  if ($row->fetch()) {
    $groupId = (int) $row->group_id;
    $member = (int) $row->contact_id;
    $outsider = (int) $sql("SELECT id FROM civicrm_contact c WHERE is_deleted = 0 AND NOT EXISTS (SELECT 1 FROM civicrm_group_contact gc WHERE gc.contact_id = c.id AND gc.group_id = $groupId AND gc.status = 'Added') ORDER BY id LIMIT 1");
    foreach ([[$member, TRUE], [$outsider, FALSE]] as [$contactId, $expected]) {
      $attempt("condition civicrm_entity_contact_in_group: contact $contactId in group $groupId is " . var_export($expected, TRUE), static function () use ($etm, $contactId, $groupId, $expected): array {
        $condition = \Drupal::service('plugin.manager.condition')->createInstance('civicrm_entity_contact_in_group');
        $condition->setContextValue('civicrm_contact', $etm()->getStorage('civicrm_contact')->load($contactId));
        $condition->setContextValue('group', (string) $groupId);
        $result = $condition->evaluate();
        return [$result === $expected, var_export($result, TRUE)];
      });
    }
  }
  else {
    $info('no group membership in the sample data; contact_in_group not checked');
  }

  $linked = (int) $sql('SELECT contact_id FROM civicrm_uf_match WHERE uf_id = 1');
  $unlinked = (int) $sql('SELECT id FROM civicrm_contact c WHERE is_deleted = 0 AND NOT EXISTS (SELECT 1 FROM civicrm_uf_match u WHERE u.contact_id = c.id) ORDER BY id LIMIT 1');
  foreach ([[$linked, TRUE], [$unlinked, FALSE]] as [$contactId, $expected]) {
    $attempt("condition civicrm_entity_drupal_user_exists: contact $contactId is " . var_export($expected, TRUE), static function () use ($etm, $contactId, $expected): array {
      $condition = \Drupal::service('plugin.manager.condition')->createInstance('civicrm_entity_drupal_user_exists');
      $condition->setContextValue('civicrm_contact', $etm()->getStorage('civicrm_contact')->load($contactId));
      $result = $condition->evaluate();
      return [$result === $expected, var_export($result, TRUE)];
    });
    $attempt("condition civicrm_contact_id_drupal_user_exists: contact $contactId is " . var_export($expected, TRUE), static function () use ($contactId, $expected): array {
      $condition = \Drupal::service('plugin.manager.condition')->createInstance('civicrm_contact_id_drupal_user_exists');
      $condition->setContextValue('civicrm_contact_id', $contactId);
      $result = $condition->evaluate();
      return [$result === $expected, var_export($result, TRUE)];
    });
  }

  $attempt("action civicrm_entity_load_linked_user finds user 1 for contact $linked", static function () use ($linked): array {
    $action = \Drupal::service('plugin.manager.rules_action')->createInstance('civicrm_entity_load_linked_user');
    $action->setContextValue('contact_id', $linked);
    $action->execute();
    $user = $action->getProvidedContext('user_fetched')->getContextValue();
    return [$user && (int) $user->id() === 1, $user ? 'uid ' . $user->id() : 'none'];
  });

  // A contact with a primary email, no Drupal user, and an email no user has.
  // Users this action made in an earlier, interrupted run go first.
  foreach ($etm()->getStorage('user')->loadMultiple($etm()->getStorage('user')->getQuery()->accessCheck(FALSE)->condition('name', 'ce_smoke_sub_rules_user', 'STARTS_WITH')->execute()) as $stale) {
    $stale->delete();
  }
  $candidate = 0;
  $email = '';
  $rows = \CRM_Core_DAO::executeQuery("SELECT c.id, e.email FROM civicrm_contact c JOIN civicrm_email e ON e.contact_id = c.id AND e.is_primary = 1 WHERE c.is_deleted = 0 AND c.contact_type = 'Individual' AND NOT EXISTS (SELECT 1 FROM civicrm_uf_match u WHERE u.contact_id = c.id) ORDER BY c.id LIMIT 20");
  while ($rows->fetch()) {
    if (!$etm()->getStorage('user')->loadByProperties(['mail' => $rows->email])) {
      [$candidate, $email] = [(int) $rows->id, (string) $rows->email];
      break;
    }
  }
  if (!$candidate) {
    $info('no individual with a primary email and no Drupal user; civicrm_entity_user_create not checked');
  }
  else try {
    $attempt("action civicrm_entity_user_create runs for contact $candidate", static function () use ($candidate): array {
      $action = \Drupal::service('plugin.manager.rules_action')->createInstance('civicrm_entity_user_create');
      $action->setContextValue('contact_id', $candidate)
        ->setContextValue('is_active', TRUE)
        ->setContextValue('notify', FALSE)
        ->setContextValue('signin', FALSE)
        ->setContextValue('format', 'ce_smoke_sub_rules_user');
      $action->execute();
      // The name the action provides, as declared since J20.
      $user = $action->getProvidedContext('civicrm_user')->getContextValue();
      return [$user !== NULL, $user ? 'provided uid ' . $user->id() : 'no user provided'];
    });
    $attempt("civicrm_entity_user_create left a user linked to contact $candidate", static function () use ($etm, $email, $candidate, $sql): array {
      $users = $etm()->getStorage('user')->loadByProperties(['mail' => $email]);
      $user = reset($users);
      $match = $user ? (int) $sql('SELECT contact_id FROM civicrm_uf_match WHERE uf_id = ' . (int) $user->id()) : 0;
      return [$user && $match === $candidate, $user ? "uid {$user->id()} '{$user->getAccountName()}', uf_match contact $match" : "no user with $email"];
    });
  }
  finally {
    foreach ($email !== '' ? $etm()->getStorage('user')->loadByProperties(['mail' => $email]) : [] as $created) {
      if (str_starts_with($created->getAccountName(), 'ce_smoke_sub_rules_user')) {
        $created->delete();
      }
    }
  }

  $attempt('typed data filter FirstLast names contact 28', static function () use ($etm): array {
    $contact = $etm()->getStorage('civicrm_contact')->load(28);
    $filter = \Drupal::service('plugin.manager.typed_data_filter')->createInstance('FirstLast');
    $definition = EntityDataDefinition::create('civicrm_contact');
    $expected = filter_var(str_replace(' ', '', ucfirst(strtolower($contact->get('first_name')->getString()))) . ucfirst(strtolower($contact->get('last_name')->getString())), FILTER_SANITIZE_EMAIL);
    $out = $filter->canFilter($definition) ? $filter->filter($definition, $contact, []) : '(cannot filter)';
    return [$out === $expected && $expected !== '', "$out vs $expected"];
  });
}

// ---- 2. Bundles ----------------------------------------------------------------

echo "\n2. Bundles (civicrm_entity_bundles)\n";
$eventBundle = NULL;
if (!$on('civicrm_entity_bundles')) {
  $skip('civicrm_entity_bundles');
}
else {
  $api = \Drupal::service('civicrm_entity.api');
  $check($etm()->getDefinition('civicrm_event')->getKey('bundle') === 'bundle', 'civicrm_event has the bundle key');
  $check($etm()->getDefinition('civicrm_activity')->getKey('bundle') === 'bundle', 'civicrm_activity has the bundle key');
  $bundled = array_filter(array_keys(SupportedEntities::getInfo()), static fn($t) => str_starts_with($t, 'civicrm_cv_') && $etm()->hasDefinition($t) && $etm()->getDefinition($t)->hasKey('bundle'));
  $check(!$bundled, 'record types have no bundle key', implode(', ', $bundled));

  $attempt('civicrm_event bundles: the root plus one per event type', static function () use ($api): array {
    $bundles = \Drupal::service('entity_type.bundle.info')->getBundleInfo('civicrm_event');
    $options = $api->getOptions('Event', 'event_type_id');
    return [isset($bundles['civicrm_event']) && count($bundles) === count($options) + 1, count($bundles) . ' bundles, ' . count($options) . ' event types: ' . implode(', ', array_slice(array_keys($bundles), 0, 6))];
  });
  $event = $etm()->getStorage('civicrm_event')->loadUnchanged(1);
  $eventTypes = $api->getOptions('Event', 'event_type_id');
  $eventBundle = SupportedEntities::optionToMachineName($eventTypes[$event->get('event_type_id')->value], \Drupal::transliteration());
  $check($event->bundle() === $eventBundle, 'event 1 has the bundle of its event type', $event->bundle() . ' vs ' . $eventBundle);

  $attempt('event 1 lists every session record under its bundle', static function () use ($event, $sessionTitle, $sql, $sessionTable, $sessionColumn): array {
    $expected = (int) $sql("SELECT COUNT($sessionColumn) FROM $sessionTable WHERE entity_id = 1");
    $items = $event->get($sessionTitle);
    $values = array_column($items->getValue(), 'value');
    return [count($values) === $expected, count($values) . " vs $expected (" . get_class($items->getFieldDefinition()) . ', ' . get_class($items) . ')'];
  });
  $attempt('event 1 renders with the root display under its bundle', static function () use ($etm, $event, $render): array {
    $html = $render($etm()->getViewBuilder('civicrm_event')->view($event, 'default'));
    return [str_contains($html, (string) $event->label()), strlen($html) . ' bytes'];
  });
  $attempt('creating an event by bundle sets its event type', static function () use ($etm, $eventBundle, $event): array {
    $new = $etm()->getStorage('civicrm_event')->create(['bundle' => $eventBundle]);
    return [(string) $new->get('event_type_id')->value === (string) $event->get('event_type_id')->value, 'event_type_id ' . var_export($new->get('event_type_id')->value, TRUE)];
  });
  $attempt('the event edit form uses the root form display', static function () use ($event): array {
    $form = \Drupal::service('entity.form_builder')->getForm($event, 'edit');
    $root = \Drupal::service('entity_display.repository')->getFormDisplay('civicrm_event', 'civicrm_event', 'default');
    $components = array_keys($root->getComponents());
    $present = array_intersect($components, array_keys($form));
    return [$components && count($present) === count($components), count($present) . ' of ' . count($components) . ' root components on the form'];
  });
  // The root's display for the form's mode, not always its default (J22).
  $attempt('the event edit form follows the root edit form display', static function () use ($event): array {
    $mode = EntityFormMode::load('civicrm_event.edit');
    $createdMode = !$mode;
    if ($createdMode) {
      EntityFormMode::create(['id' => 'civicrm_event.edit', 'label' => 'Edit', 'targetEntityType' => 'civicrm_event'])->save();
    }
    if (EntityFormDisplay::load('civicrm_event.civicrm_event.edit')) {
      throw new \RuntimeException('civicrm_event.civicrm_event.edit exists already; not touched');
    }
    $root = \Drupal::service('entity_display.repository')->getFormDisplay('civicrm_event', 'civicrm_event', 'default');
    $others = array_diff(array_keys($root->getComponents()), ['title']);
    $display = EntityFormDisplay::create(['targetEntityType' => 'civicrm_event', 'bundle' => 'civicrm_event', 'mode' => 'edit', 'status' => TRUE]);
    foreach (array_keys($display->getComponents()) as $name) {
      $display->removeComponent($name);
    }
    $display->setComponent('title', $root->getComponent('title'))->save();
    try {
      $form = \Drupal::service('entity.form_builder')->getForm(\Drupal::entityTypeManager()->getStorage('civicrm_event')->loadUnchanged($event->id()), 'edit');
      $shown = array_values(array_intersect($others, array_keys($form)));
      return [isset($form['title']) && $others && !$shown, 'title ' . var_export(isset($form['title']), TRUE) . ', other root components shown: ' . json_encode($shown)];
    }
    finally {
      $display->delete();
      if ($createdMode) {
        EntityFormMode::load('civicrm_event.edit')?->delete();
      }
    }
  });
  $attempt('event add route takes a bundle and an add page exists', static function (): array {
    $routes = \Drupal::service('router.route_provider');
    $add = $routes->getRouteByName('entity.civicrm_event.add_form');
    $page = $routes->getRoutesByNames(['entity.civicrm_event.add_page']);
    return [str_contains($add->getPath(), '{bundle}') && $page, $add->getPath() . '; add page ' . ($page ? reset($page)->getPath() : 'missing')];
  });
  $attempt('Field UI routes of civicrm_event default to the root bundle', static function (): array {
    $route = \Drupal::service('router.route_provider')->getRouteByName('entity.civicrm_event.field_ui_fields');
    return [$route->getDefault('bundle') === 'civicrm_event', $route->getPath() . ' bundle=' . var_export($route->getDefault('bundle'), TRUE)];
  });
  $attempt('an event session record saves and deletes under bundles', static function () use ($etm, $sessionType, $sessionTitle, $marker, $sql, $sessionTable): array {
    $record = $etm()->getStorage($sessionType)->create(['entity_id' => 1, $sessionTitle => "$marker session"]);
    $violations = $record->validate();
    if (count($violations)) {
      return [FALSE, (string) $violations];
    }
    $record->save();
    $id = $record->id();
    $saved = (int) $sql("SELECT COUNT(*) FROM $sessionTable WHERE id = $id AND entity_id = 1");
    $etm()->getStorage($sessionType)->loadUnchanged($id)->delete();
    return [$saved === 1 && (int) $sql("SELECT COUNT(*) FROM $sessionTable WHERE id = $id") === 0, "id $id"];
  });
  $attempt('activity 2 has the bundle of its activity type', static function () use ($etm, $api): array {
    $activity = $etm()->getStorage('civicrm_activity')->load(2);
    $types = $api->getOptions('Activity', 'activity_type_id');
    $expected = SupportedEntities::optionToMachineName($types[$activity->get('activity_type_id')->value], \Drupal::transliteration());
    return [$activity->bundle() === $expected, $activity->bundle() . ' vs ' . $expected];
  });
}

// ---- Display helpers (sections 3 and 4) ----------------------------------------

$ensureViewMode = static function (string $entityTypeId, string $mode, string $label): void {
  if (!EntityViewMode::load("$entityTypeId.$mode")) {
    EntityViewMode::create(['id' => "$entityTypeId.$mode", 'label' => $label, 'targetEntityType' => $entityTypeId, 'status' => TRUE, 'cache' => TRUE])->save();
  }
};
// The root bundle's display for a view mode, holding $fields (with the
// formatters of the root default display) and nothing else.
$viewDisplay = static function (string $entityTypeId, string $mode, array $fields): EntityViewDisplay {
  $root = \Drupal::service('entity_display.repository')->getViewDisplay($entityTypeId, $entityTypeId, 'default');
  $display = EntityViewDisplay::load("$entityTypeId.$entityTypeId.$mode")
    ?? EntityViewDisplay::create(['targetEntityType' => $entityTypeId, 'bundle' => $entityTypeId, 'mode' => $mode, 'status' => TRUE]);
  foreach (array_keys($display->getComponents()) as $name) {
    $display->removeComponent($name);
  }
  $weight = 0;
  foreach ($fields as $name) {
    if ($component = $root->getComponent($name)) {
      $display->setComponent($name, ['weight' => $weight++] + $component);
    }
  }
  return $display->setStatus(TRUE);
};
// Up to $n components of the root default view display, $first first.
$pick = static function (string $entityTypeId, array $first, int $n = 3): array {
  $root = \Drupal::service('entity_display.repository')->getViewDisplay($entityTypeId, $entityTypeId, 'default');
  $names = array_keys($root->getComponents());
  return array_slice(array_values(array_unique(array_merge(array_intersect($first, $names), $names))), 0, $n);
};
$fieldGroup = static fn(array $children, string $class) => [
  'children' => array_values($children),
  'label' => 'CE smoke group',
  'parent_name' => '',
  'region' => 'content',
  'weight' => 20,
  'format_type' => 'fieldset',
  'format_settings' => ['classes' => $class, 'id' => '', 'description' => '', 'required_fields' => TRUE, 'label_as_html' => FALSE, 'show_empty_fields' => FALSE],
];
$eduRecord = $etm()->getStorage($eduType)->load($sql("SELECT MIN(id) FROM $eduTable WHERE $schoolColumn IS NOT NULL"));
$subjects = [
  [$eduType, $eduRecord, [$school, $degree, 'entity_id']],
  ['civicrm_contact', $etm()->getStorage('civicrm_contact')->load(28), ['display_name']],
  ['civicrm_event', $etm()->getStorage('civicrm_event')->load(1), ['title', $sessionTitle]],
];

// ---- 3. Display Suite ----------------------------------------------------------

echo "\n3. Display Suite (civicrm_entity_ds)\n";
if (!$on('civicrm_entity_ds')) {
  $skip('civicrm_entity_ds');
}
else {
  foreach ($subjects as [$entityTypeId, $entity, $first]) {
    $attempt("$entityTypeId {$entity->id()} renders the ds_2col layout of view mode ce_smoke_ds" . ($entityTypeId === 'civicrm_event' ? " (bundle {$entity->bundle()}, layout on the root)" : ''), static function () use ($ensureViewMode, $viewDisplay, $pick, $entityTypeId, $entity, $first, $etm, $render): array {
      $ensureViewMode($entityTypeId, 'ce_smoke_ds', 'CE smoke: DS');
      $fields = $pick($entityTypeId, $first);
      $display = $viewDisplay($entityTypeId, 'ce_smoke_ds', $fields);
      $left = array_slice($fields, 0, 1);
      $right = array_slice($fields, 1);
      foreach ($fields as $name) {
        $display->setComponent($name, ['region' => in_array($name, $left, TRUE) ? 'left' : 'right'] + $display->getComponent($name));
      }
      $display->setThirdPartySetting('ds', 'layout', [
        'id' => 'ds_2col',
        'library' => 'ds/ds_2col',
        'disable_css' => FALSE,
        'entity_classes' => 'all_classes',
        'settings' => [
          'classes' => ['layout_class' => []],
          'wrappers' => ['left' => 'div', 'right' => 'div'],
          'outer_wrapper' => 'div',
          'attributes' => '',
          'link_attribute' => '',
          'link_custom' => '',
          'label' => '',
        ],
      ]);
      $display->setThirdPartySetting('ds', 'regions', ['left' => $left, 'right' => $right]);
      $display->save();
      $html = $render($etm()->getViewBuilder($entityTypeId)->view($etm()->getStorage($entityTypeId)->loadUnchanged($entity->id()), 'ce_smoke_ds'));
      return [str_contains($html, 'ds-2col') && str_contains($html, 'group-left'), strlen($html) . ' bytes; fields ' . implode(', ', $fields)];
    });
  }
}

// ---- 4. Field group ------------------------------------------------------------

echo "\n4. Field group (civicrm_entity_field_group)\n";
if (!$on('civicrm_entity_field_group')) {
  $skip('civicrm_entity_field_group');
}
else {
  foreach ($subjects as [$entityTypeId, $entity, $first]) {
    $attempt("$entityTypeId {$entity->id()} renders a fieldset group in view mode ce_smoke_fg" . ($entityTypeId === 'civicrm_event' && $entity->bundle() !== 'civicrm_event' ? " (bundle {$entity->bundle()}, group on the root)" : ''), static function () use ($ensureViewMode, $viewDisplay, $pick, $fieldGroup, $entityTypeId, $entity, $first, $etm, $render): array {
      $ensureViewMode($entityTypeId, 'ce_smoke_fg', 'CE smoke: field group');
      $fields = $pick($entityTypeId, $first);
      $display = $viewDisplay($entityTypeId, 'ce_smoke_fg', $fields);
      $display->setThirdPartySetting('field_group', 'group_ce_smoke_sub', $fieldGroup(array_slice($fields, 0, 2), 'ce-smoke-sub-fg'));
      $display->save();
      $html = $render($etm()->getViewBuilder($entityTypeId)->view($etm()->getStorage($entityTypeId)->loadUnchanged($entity->id()), 'ce_smoke_fg'));
      return [str_contains($html, 'ce-smoke-sub-fg'), strlen($html) . ' bytes; group of ' . implode(', ', array_slice($fields, 0, 2))];
    });
  }

  $attempt("the $eduType add form shows a fieldset group from its add form display", static function () use ($etm, $eduType, $school, $degree, $fieldGroup, $render): array {
    $display = EntityFormDisplay::load("$eduType.$eduType.add") ?? throw new \RuntimeException("No $eduType add form display");
    $children = array_values(array_intersect([$school, $degree], array_keys($display->getComponents())));
    $display->setThirdPartySetting('field_group', 'group_ce_smoke_sub', $fieldGroup($children, 'ce-smoke-sub-fg-form'))->save();
    $form = \Drupal::service('entity.form_builder')->getForm($etm()->getStorage($eduType)->create(['entity_id' => 28]), 'add');
    $html = $render($form);
    return [isset($form['#fieldgroups']['group_ce_smoke_sub']) && str_contains($html, 'ce-smoke-sub-fg-form'), 'children ' . implode(', ', $children)];
  });

  $attempt('the event 1 edit form shows a fieldset group from the root form display' . ($eventBundle ? " (bundle $eventBundle)" : ''), static function () use ($etm, $fieldGroup, $render, &$owned): array {
    $display = EntityFormDisplay::load('civicrm_event.civicrm_event.default');
    if (!$display) {
      // None is saved on this site; save the runtime one, and remember to
      // delete it in submodules-cleanup.php.
      $display = \Drupal::service('entity_display.repository')->getFormDisplay('civicrm_event', 'civicrm_event', 'default');
      $owned['event_form_display'] = TRUE;
      \Drupal::state()->set('ce_smoke_sub.owned', $owned);
    }
    $children = array_slice(array_values(array_intersect(['title', 'event_type_id', 'start_date'], array_keys($display->getComponents()))), 0, 2);
    $display->setThirdPartySetting('field_group', 'group_ce_smoke_sub', $fieldGroup($children, 'ce-smoke-sub-fg-event-form'))->save();
    $form = \Drupal::service('entity.form_builder')->getForm($etm()->getStorage('civicrm_event')->loadUnchanged(1), 'edit');
    $html = $render($form);
    return [isset($form['#fieldgroups']['group_ce_smoke_sub']) && str_contains($html, 'ce-smoke-sub-fg-event-form'), 'children ' . implode(', ', $children)];
  });
}

// ---- 5. Search API -------------------------------------------------------------

echo "\n5. Search API (civicrm_entity_search_api)\n";
if (!$on('civicrm_entity_search_api') || !$on('search_api_db')) {
  $skip('civicrm_entity_search_api / search_api_db');
}
else {
  $attempt("entity:$eduType uses the CiviCRM Entity datasource", static function () use ($eduType): array {
    $class = \Drupal::service('plugin.manager.search_api.datasource')->getDefinition("entity:$eduType")['class'];
    return [ltrim($class, '\\') === 'Drupal\civicrm_entity_search_api\Plugin\search_api\datasource\CivicrmEntity', $class];
  });

  // Search API tracks an entity type with a base table by selecting its IDs
  // straight from that table. A record type's base table is the Views name
  // CivicrmSql maps to the group's table, so the datasource selects from the
  // group's table instead (J18).
  $probe = Index::create([
    'id' => 'ce_smoke_sub_probe',
    'name' => 'CE smoke: tracking probe (never saved)',
    'datasource_settings' => ["entity:$eduType" => []] + ($eventBundle ? ['entity:civicrm_event' => ['bundles' => ['default' => FALSE, 'selected' => [$eventBundle]]]] : []),
    'tracker_settings' => ['default' => []],
  ]);
  $attempt("default tracking (direct SQL on $eduTable) lists every $eduType record", static function () use ($probe, $eduType, $sql, $eduTable): array {
    $ids = $probe->getDatasource("entity:$eduType")->getItemIds() ?? [];
    $expected = (int) $sql("SELECT COUNT(*) FROM $eduTable");
    return [count($ids) === $expected, count($ids) . " vs $expected"];
  });
  if ($eventBundle) {
    $attempt("an event datasource limited to bundle $eventBundle tracks its events", static function () use ($probe, $eventBundle, $event, $sql): array {
      $ids = $probe->getDatasource('entity:civicrm_event')->getPartialItemIds(NULL, [$eventBundle]) ?? [];
      $expected = (int) $sql('SELECT COUNT(*) FROM civicrm_event WHERE event_type_id = ' . (int) $event->get('event_type_id')->value);
      return [count($ids) === $expected, count($ids) . " vs $expected"];
    });
  }

  $index = NULL;
  $attempt('a DB server and an index over contacts and education records save', static function () use ($eduType, $school, &$index): array {
    $server = Server::load('ce_smoke_sub_db') ?? Server::create([
      'id' => 'ce_smoke_sub_db',
      'name' => 'CE smoke: database',
      'backend' => 'search_api_db',
      'backend_config' => ['database' => 'default:default', 'min_chars' => 1, 'matching' => 'words', 'phrase' => 'bigram'],
    ]);
    $server->save();
    // Rebuilt every run, so the counts below start from nothing.
    Index::load('ce_smoke_sub_index')?->delete();
    $index = Index::create([
      'id' => 'ce_smoke_sub_index',
      'name' => 'CE smoke: contacts and education records',
      'server' => 'ce_smoke_sub_db',
      'status' => TRUE,
      'datasource_settings' => ['entity:civicrm_contact' => [], "entity:$eduType" => []],
      'tracker_settings' => ['default' => []],
      'options' => ['index_directly' => FALSE, 'cron_limit' => 50],
    ]);
    $helper = \Drupal::service('search_api.fields_helper');
    foreach ([
      'contact_name' => ['entity:civicrm_contact', 'display_name', 'text'],
      'contact_schools' => ['entity:civicrm_contact', $school, 'string'],
      'record_school' => ["entity:$eduType", $school, 'string'],
      'record_parent' => ["entity:$eduType", 'entity_id', 'integer'],
      'record_parent_name' => ["entity:$eduType", 'entity_id:entity:display_name', 'string'],
    ] as $id => [$datasource, $path, $type]) {
      $index->addField($helper->createField($index, $id, ['label' => $id, 'type' => $type, 'datasource_id' => $datasource, 'property_path' => $path]));
    }
    $index->save();
    return [TRUE, implode(', ', array_keys($index->getFields()))];
  });

  if ($index) {
    $tracker = static fn() => $index->getTrackerInstance();
    $attempt('tracking: every contact and every education record', static function () use ($index, $tracker, $eduType, $sql, $eduTable): array {
      \Drupal::service('search_api.index_task_manager')->addItemsAll($index);
      $contacts = $tracker()->getTotalItemsCount('entity:civicrm_contact');
      $records = $tracker()->getTotalItemsCount("entity:$eduType");
      $allContacts = (int) $sql('SELECT COUNT(*) FROM civicrm_contact');
      $liveContacts = (int) $sql('SELECT COUNT(*) FROM civicrm_contact WHERE is_deleted = 0');
      $expectedRecords = (int) $sql("SELECT COUNT(*) FROM $eduTable");
      return [in_array($contacts, [$allContacts, $liveContacts], TRUE) && $records === $expectedRecords, "contacts $contacts (SQL $allContacts, $liveContacts not deleted); records $records vs $expectedRecords"];
    });
    $attempt('indexing: everything tracked is indexed', static function () use ($index, $tracker): array {
      $indexed = $index->indexItems(-1);
      return [$tracker()->getRemainingItemsCount() === 0 && $indexed === $tracker()->getTotalItemsCount(), "$indexed indexed, " . $tracker()->getRemainingItemsCount() . ' remaining of ' . $tracker()->getTotalItemsCount()];
    });

    $top = \CRM_Core_DAO::executeQuery("SELECT $schoolColumn AS v, COUNT(*) AS n, COUNT(DISTINCT entity_id) AS p FROM $eduTable WHERE $schoolColumn IS NOT NULL GROUP BY $schoolColumn ORDER BY n DESC, v LIMIT 1");
    $top->fetch();
    $search = static function (string $field, string $value, string $datasource) use ($index): int {
      return (int) $index->query()->addCondition($field, $value)->addCondition('search_api_datasource', $datasource)->execute()->getResultCount();
    };
    $attempt("search: records with school '$top->v'", static fn() => [($n = $search('record_school', $top->v, "entity:$eduType")) === (int) $top->n, "$n vs {$top->n}"]);
    $attempt("search: contacts whose multi-record school list holds '$top->v'", static fn() => [($n = $search('contact_schools', $top->v, 'entity:civicrm_contact')) === (int) $top->p, "$n vs {$top->p}"]);

    $attempt('a record created, renamed and deleted in Drupal is tracked, reindexed and removed', static function () use ($etm, $eduType, $school, $marker, $index, $tracker, $search): array {
      $total = $tracker()->getTotalItemsCount("entity:$eduType");
      $record = $etm()->getStorage($eduType)->create(['entity_id' => 28, $school => "$marker SAPI"]);
      $record->save();
      $id = $record->id();
      $tracked = $tracker()->getTotalItemsCount("entity:$eduType") === $total + 1;
      $index->indexItems(-1);
      $found = $search('record_school', "$marker SAPI", "entity:$eduType");
      $etm()->getStorage($eduType)->loadUnchanged($id)->set($school, "$marker SAPI renamed")->save();
      $index->indexItems(-1);
      $renamed = [$search('record_school', "$marker SAPI", "entity:$eduType"), $search('record_school', "$marker SAPI renamed", "entity:$eduType")];
      $etm()->getStorage($eduType)->loadUnchanged($id)->delete();
      $gone = $search('record_school', "$marker SAPI renamed", "entity:$eduType");
      $back = $tracker()->getTotalItemsCount("entity:$eduType") === $total;
      return [$tracked && $found === 1 && $renamed === [0, 1] && $gone === 0 && $back, sprintf('tracked %s, found %d, after rename old/new %d/%d, after delete %d, tracker restored %s', var_export($tracked, TRUE), $found, $renamed[0], $renamed[1], $gone, var_export($back, TRUE))];
    });
    $attempt('a record created and deleted through API4 is tracked, indexed and removed', static function () use ($eduType, $marker, $index, $tracker, $search): array {
      $total = $tracker()->getTotalItemsCount("entity:$eduType");
      $id = CustomValue::create('ce_education', FALSE)->addValue('entity_id', 28)->addValue('school', "$marker SAPI API4")->execute()->first()['id'];
      $tracked = $tracker()->getTotalItemsCount("entity:$eduType") === $total + 1;
      $index->indexItems(-1);
      $found = $search('record_school', "$marker SAPI API4", "entity:$eduType");
      CustomValue::delete('ce_education', FALSE)->addWhere('id', '=', $id)->execute();
      $gone = $search('record_school', "$marker SAPI API4", "entity:$eduType");
      return [$tracked && $found === 1 && $gone === 0 && $tracker()->getTotalItemsCount("entity:$eduType") === $total, sprintf('tracked %s, found %d, after delete %d', var_export($tracked, TRUE), $found, $gone)];
    });
    // A contact's multi-record list is computed from its records, so each
    // record change queues the contact again (J18).
    $contact28 = 'entity:civicrm_contact/28:' . $etm()->getStorage('civicrm_contact')->load(28)->language()->getId();
    $check(in_array($contact28, $tracker()->getRemainingItems(), TRUE), 'contact 28 queued for reindexing after its records changed');
    $index->indexItems(-1);
    $attempt("a record's insert and delete, in Drupal and through API4, queue its parent, whose indexed school list follows", static function () use ($etm, $eduType, $school, $marker, $index, $tracker, $search, $contact28): array {
      $queued = static fn(): bool => in_array($contact28, $tracker()->getRemainingItems(), TRUE);
      $steps = [];
      $record = $etm()->getStorage($eduType)->create(['entity_id' => 28, $school => "$marker SAPI parent"]);
      $record->save();
      $steps[] = $queued();
      $index->indexItems(-1);
      $steps[] = $search('contact_schools', "$marker SAPI parent", 'entity:civicrm_contact') === 1;
      $etm()->getStorage($eduType)->loadUnchanged($record->id())->delete();
      $steps[] = $queued();
      $index->indexItems(-1);
      $steps[] = $search('contact_schools', "$marker SAPI parent", 'entity:civicrm_contact') === 0;
      $id = CustomValue::create('ce_education', FALSE)->addValue('entity_id', 28)->addValue('school', "$marker SAPI parent API4")->execute()->first()['id'];
      $steps[] = $queued();
      $index->indexItems(-1);
      $steps[] = $search('contact_schools', "$marker SAPI parent API4", 'entity:civicrm_contact') === 1;
      CustomValue::delete('ce_education', FALSE)->addWhere('id', '=', $id)->execute();
      $steps[] = $queued();
      $index->indexItems(-1);
      $steps[] = $search('contact_schools', "$marker SAPI parent API4", 'entity:civicrm_contact') === 0;
      return [!in_array(FALSE, $steps, TRUE), 'queued/found per step: ' . implode(' ', array_map(static fn($ok) => $ok ? 'y' : 'n', $steps))];
    });
    // The records index their parent's name, so a rename queues them (J18).
    $attempt("renaming contact 28 in Drupal queues its $eduType records", static function () use ($etm, $eduType, $eduTable, $index, $tracker): array {
      $first = Contact::get(FALSE)->addSelect('first_name')->addWhere('id', '=', 28)->execute()->first()['first_name'];
      try {
        $contact = $etm()->getStorage('civicrm_contact')->loadUnchanged(28);
        $contact->set('first_name', "$first CE")->save();
        $queued = array_map(static fn(string $id) => (int) explode(':', explode('/', $id, 2)[1])[0], $tracker()->getRemainingItems(-1, "entity:$eduType"));
        sort($queued);
      }
      finally {
        Contact::update(FALSE)->addWhere('id', '=', 28)->addValue('first_name', $first)->execute();
        $index->indexItems(-1);
      }
      $records = array_map('intval', array_column(\CRM_Core_DAO::executeQuery("SELECT id FROM $eduTable WHERE entity_id = 28 ORDER BY id")->fetchAll(), 'id'));
      return [$records && $queued === $records, 'queued ' . json_encode($queued) . ' vs ' . json_encode($records)];
    });
  }
}

// ---- 6. Views Bulk Operations ---------------------------------------------------

echo "\n6. Views Bulk Operations (civicrm_entity_vbo)\n";
if (!$on('civicrm_entity_vbo')) {
  $skip('civicrm_entity_vbo');
}
else {
  $definition = \Drupal::service('plugin.manager.views_bulk_operations_action')->getDefinition('civicrm_contact_add_to_group', FALSE);
  $check($definition && $definition['type'] === 'civicrm_contact', 'action civicrm_contact_add_to_group is defined for civicrm_contact');

  $vd = \Drupal::service('views.views_data');
  $h = static function (string $type, string $table, string $field, array $options = []) use ($vd): array {
    $data = $vd->get($table);
    if (!isset($data[$field][$type]['id'])) {
      throw new \RuntimeException("No $type handler for $table.$field");
    }
    $item = ['id' => $field, 'table' => $table, 'field' => $field, 'plugin_id' => $data[$field][$type]['id']] + $options;
    if (isset($data['table']['entity type'])) {
      $item['entity_type'] = $data['table']['entity type'];
    }
    if (isset($data[$field]['entity field'])) {
      $item['entity_field'] = $data[$field]['entity field'];
    }
    return [$field => $item];
  };
  $bulkForm = static fn(array $actions) => ['views_bulk_operations_bulk_form' => [
    'id' => 'views_bulk_operations_bulk_form',
    'table' => 'views',
    'field' => 'views_bulk_operations_bulk_form',
    'plugin_id' => 'views_bulk_operations_bulk_form',
    'label' => 'Bulk',
    'batch' => TRUE,
    'batch_size' => 10,
    'form_step' => TRUE,
    'ajax_loader' => FALSE,
    'buttons' => FALSE,
    'action_title' => 'Action',
    'clear_on_exposed' => TRUE,
    'show_multipage_selection_box' => 'default',
    'show_select_all' => 'default',
    'selected_actions' => $actions,
  ]];
  $idArgument = static fn(string $table) => $h('argument', $table, 'id', [
    'default_action' => 'ignore',
    'specify_validation' => TRUE,
    'validate' => ['type' => 'numeric', 'fail' => 'not found'],
    'break_phrase' => FALSE,
    'not' => FALSE,
  ]);
  $saveView = static function (string $id, string $baseTable, array $fields, array $arguments): void {
    View::load($id)?->delete();
    View::create([
      'id' => $id,
      'label' => "CE smoke: $id",
      'module' => 'views',
      'tag' => 'ce_smoke_sub',
      'base_table' => $baseTable,
      'base_field' => 'id',
      'display' => ['default' => [
        'id' => 'default',
        'display_title' => 'Default',
        'display_plugin' => 'default',
        'position' => 0,
        'display_options' => [
          'title' => $id,
          'access' => ['type' => 'perm', 'options' => ['perm' => 'administer civicrm entity']],
          'cache' => ['type' => 'none', 'options' => []],
          'pager' => ['type' => 'some', 'options' => ['items_per_page' => 10, 'offset' => 0]],
          'style' => ['type' => 'table', 'options' => []],
          'row' => ['type' => 'fields', 'options' => []],
          'fields' => $fields,
          'arguments' => $arguments,
        ],
      ]],
    ])->save();
  };
  // As `drush views:bulk-operations:execute` runs an action, as user 1.
  $runAction = static function (string $viewId, string $actionId, array $args, array $configuration = []): array {
    $view = Views::getView($viewId);
    $view->setDisplay('default');
    $view->setArguments($args);
    $view->get_total_rows = TRUE;
    $view->execute();
    $data = [
      'list' => [],
      'view_id' => $viewId,
      'display_id' => 'default',
      'action_id' => $actionId,
      'preconfiguration' => $configuration,
      'batch' => TRUE,
      'arguments' => $args,
      'exposed_input' => [],
      'batch_size' => 10,
      'relationship_id' => 'none',
      'clear_on_exposed' => TRUE,
      'exclude_mode' => FALSE,
    ];
    $viewData = \Drupal::service('views_bulk_operations.data');
    $viewData->init($view, $view->getDisplay(), 'none');
    $data['total_results'] = $viewData->getTotalResults(TRUE);
    if ($data['total_results'] === 0) {
      throw new \RuntimeException("View $viewId returned no rows for " . implode('/', $args));
    }
    $data['action_label'] = (string) \Drupal::service('plugin.manager.views_bulk_operations_action')->getDefinition($actionId)['label'];
    $context = [];
    do {
      $context['finished'] = 1;
      ViewsBulkOperationsBatch::getList($data, $context);
    } while ($context['finished'] < 1);
    $data = $context['results'];
    $context = [];
    do {
      $context['finished'] = 1;
      ViewsBulkOperationsBatch::operation($data, $context);
    } while ($context['finished'] < 1);
    return $context['results']['operations'] ?? [];
  };

  // Fetched here: saving a reaction rule may have rebuilt the container.
  $switcher = \Drupal::service('account_switcher');
  $switcher->switchTo($admin);
  try {
    $attempt("a View of $eduType with a bulk form saves and renders the form", static function () use ($saveView, $h, $bulkForm, $idArgument, $eduType, $render): array {
      $saveView('ce_smoke_sub_vbo_education', $eduType, $bulkForm([['action_id' => 'views_bulk_operations_delete_entity', 'preconfiguration' => []]]) + $h('field', $eduType, 'id', ['label' => 'ID']), $idArgument($eduType));
      // The first render after the View is created in this process lacks
      // the bulk form; the next one has it, as does a fresh process. VBO's,
      // not CiviCRM Entity's: the first render is discarded.
      $render(Views::getView('ce_smoke_sub_vbo_education')->preview('default'));
      $view = Views::getView('ce_smoke_sub_vbo_education');
      $html = $render($view->preview('default'));
      return [str_contains($html, 'views_bulk_operations_bulk_form'), strlen($html) . ' bytes, ' . count($view->result) . ' rows'];
    });
    $attempt('VBO deletes an education record', static function () use ($etm, $eduType, $school, $marker, $runAction, $sql, $eduTable): array {
      $record = $etm()->getStorage($eduType)->create(['entity_id' => 28, $school => "$marker VBO"]);
      $record->save();
      $id = $record->id();
      $operations = $runAction('ce_smoke_sub_vbo_education', 'views_bulk_operations_delete_entity', [$id]);
      $left = (int) $sql("SELECT COUNT(*) FROM $eduTable WHERE id = $id");
      return [$left === 0, "record $id rows left $left; " . json_encode($operations)];
    });

    $group = Group::get(FALSE)->addWhere('name', '=', 'ce_smoke_sub_vbo')->execute()->first()
      ?? Group::create(FALSE)->addValue('name', 'ce_smoke_sub_vbo')->addValue('title', 'CE smoke: VBO')->execute()->first();
    GroupContact::delete(FALSE)->addWhere('group_id', '=', $group['id'])->execute();
    $attempt('the add-to-group action lists the smoke group among its options', static function () use ($group): array {
      $action = \Drupal::service('plugin.manager.views_bulk_operations_action')->createInstance('civicrm_contact_add_to_group');
      $form = $action->buildPreConfigurationForm([], [], new \Drupal\Core\Form\FormState());
      $options = $form['allowed_groups']['#options'] ?? [];
      return [isset($options[$group['id']]), count($options) . ' groups'];
    });
    $attempt("VBO adds contact 28 to group {$group['id']}", static function () use ($saveView, $h, $bulkForm, $idArgument, $runAction, $group): array {
      $saveView('ce_smoke_sub_vbo_contacts', 'civicrm_contact', $bulkForm([['action_id' => 'civicrm_contact_add_to_group', 'preconfiguration' => ['allowed_groups' => [$group['id']]]]]) + $h('field', 'civicrm_contact', 'display_name', ['label' => 'Name']), $idArgument('civicrm_contact'));
      $operations = $runAction('ce_smoke_sub_vbo_contacts', 'civicrm_contact_add_to_group', ['28'], ['selected_group' => $group['id']]);
      $in = GroupContact::get(FALSE)->addWhere('group_id', '=', $group['id'])->addWhere('contact_id', '=', 28)->addWhere('status', '=', 'Added')->execute()->count();
      return [$in === 1, "memberships $in; " . json_encode($operations)];
    });
    // A failed add was silent (J23): now shown and logged.
    $attempt('VBO reports and logs a failed add to a group that does not exist', static function () use ($runAction, &$expectedWids): array {
      $missing = (int) Group::get(FALSE)->addSelect('MAX(id) AS max')->execute()->first()['max'] + 1000;
      $before = (int) \Drupal::database()->query('SELECT MAX(wid) FROM {watchdog}')->fetchField();
      \Drupal::messenger()->deleteAll();
      $runAction('ce_smoke_sub_vbo_contacts', 'civicrm_contact_add_to_group', ['28'], ['selected_group' => $missing]);
      $errors = array_map('strval', \Drupal::messenger()->messagesByType('error'));
      // The action's entry, and the API gateway's own for the failed write.
      $logged = \Drupal::database()->select('watchdog', 'w')->fields('w', ['wid', 'message'])->condition('wid', $before, '>')->condition('type', 'civicrm_entity')->execute()->fetchAllKeyed();
      $expectedWids = array_merge($expectedWids, array_keys($logged));
      $logged = array_filter($logged, static fn(string $message) => str_starts_with($message, 'Could not add contact'));
      $in = GroupContact::get(FALSE)->addWhere('group_id', '=', $missing)->execute()->count();
      return [$in === 0 && count($errors) === 1 && str_contains($errors[0], 'Could not add') && count($logged) === 1, json_encode($errors) . '; logged ' . count($logged)];
    });
    GroupContact::delete(FALSE)->addWhere('group_id', '=', $group['id'])->execute();
  }
  finally {
    $switcher->switchBack();
  }
}

// ---- 7. Site -------------------------------------------------------------------

echo "\n7. Site\n";
$purge();
\Drupal::state()->set('ce_smoke_sub.owned', $owned);
$changes = \Drupal::entityDefinitionUpdateManager()->getChangeSummary();
$check(!$changes, 'no pending entity definition updates', $changes ? json_encode(array_map(static fn($list) => array_map('strval', $list), $changes)) : '');
$errors = \Drupal::database()->select('watchdog', 'w')
  ->fields('w', ['wid', 'type', 'severity', 'message', 'variables'])
  ->condition('wid', $startWid, '>')
  ->condition('wid', $expectedWids ?: [0], 'NOT IN')
  ->condition((new \Drupal\Core\Database\Query\Condition('OR'))->condition('type', 'php')->condition('severity', RfcLogLevel::ERROR, '<='))
  ->orderBy('wid')
  ->execute()
  ->fetchAll();
$check(!$errors, 'no PHP or error-level watchdog entries since the start', count($errors) . ' entries');
foreach (array_slice($errors, 0, 8) as $error) {
  $variables = @unserialize($error->variables, ['allowed_classes' => FALSE]) ?: [];
  $text = strip_tags(strtr((string) $error->message, array_map(static fn($v) => is_scalar($v) ? (string) $v : json_encode($v), $variables)));
  printf("      #%d %s/%d: %s\n", $error->wid, $error->type, $error->severity, mb_substr(preg_replace('/\s+/', ' ', $text), 0, 300));
}

printf("\n%d passed, %d failed%s\n", $pass, count($fail), $skipped ? ', skipped: ' . implode(', ', $skipped) : '');
foreach ($fail as $f) {
  echo "  - $f\n";
}
echo "\nAlso run verify-smoke.php: the submodules must leave its 77 checks passing.\n";
