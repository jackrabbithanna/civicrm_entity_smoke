<?php

/**
 * @file
 * Checks access to record types, their parents and fixture pages as non-admins.
 *
 *   ddev drush --uri=https://drupal-civi-composer-exp-d11.ddev.site \
 *     php:script scripts/smoke/access-smoke.php
 *
 * SMOKE_VERBOSE=1 (via `ddev exec`) prints passing checks too.
 *
 * Four phases: CiviCRM Entity's check_permissions off and on, each with
 *
 * - open custom data: the site's own permissions. CiviCRM's install grants
 *   anonymous and authenticated users "access all custom data", so every
 *   custom group is open and the parent alone decides;
 * - restricted custom data: "access all custom data" revoked from those two
 *   roles, and a CiviCRM ACL instead grants the smoke_acl user View on
 *   Education History and Edit on Skills.
 *
 * smoke_editor and smoke_acl hold the same Drupal roles; only the ACL tells
 * them apart, which is what the cache-order checks rely on.
 *
 * Users, roles, the ACL group and the (inactive) ACLs are kept between runs;
 * setup is idempotent. The revoked permissions, the ACLs' active flag and
 * check_permissions are restored on shutdown, whatever fails. Entity access
 * is asked of the access handlers in this process with check_permissions off
 * only: with it on, this process would load as drush's anonymous user. Pages
 * are fetched over HTTP as each user, so routing, CiviCRM's session contact
 * and check_permissions all apply as in a browser. Every cache that can hold
 * what one user's CiviCRM calls returned is emptied before each phase and
 * each cache-order check.
 */

use Drupal\civicrm_entity\CustomRecord\CustomRecordTypeRegistry;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use GuzzleHttp\Cookie\CookieJar;

const BASE = 'https://drupal-civi-composer-exp-d11.ddev.site';

\Drupal::service('civicrm')->initialize();
$etm = \Drupal::entityTypeManager();
$sql = static fn(string $query, array $params = []) => \CRM_Core_DAO::singleValueQuery($query, $params);
$pass = 0;
$fail = [];
$check = static function (bool $ok, string $label, string $detail = '') use (&$pass, &$fail): void {
  $ok ? $pass++ : $fail[] = $label . ($detail ? " ($detail)" : '');
  if (!$ok || getenv('SMOKE_VERBOSE')) {
    printf("  [%s] %s%s\n", $ok ? 'ok' : 'FAIL', $label, $detail ? " -- $detail" : '');
  }
};
$note = static fn(string $text) => printf("  [--] %s\n", $text);
$started = time();

// ---------------------------------------------------------------------------
// Restore on shutdown: a fatal error inside CiviCRM skips `finally`.
// ---------------------------------------------------------------------------
$restore = ['roles' => []];
register_shutdown_function(static function () use (&$restore): void {
  foreach ($restore['roles'] as $rid) {
    Role::load($rid)?->grantPermission('access all custom data')->save();
  }
  \Civi\Api4\ACL::update(FALSE)->addWhere('name', 'LIKE', 'CE smoke:%')->addValue('is_active', FALSE)->execute();
  \Drupal::configFactory()->getEditable('civicrm_entity.settings')->set('check_permissions', FALSE)->save();
  \CRM_ACL_BAO_Cache::resetCache();
  echo "\nrestored: access all custom data for " . (implode(', ', $restore['roles']) ?: 'nobody') . "; smoke ACLs inactive; check_permissions off\n";
});

// ---------------------------------------------------------------------------
// Setup: roles, users and their contacts, the ACL. Idempotent.
// ---------------------------------------------------------------------------
$roles = [
  'smoke_contact_viewer' => ['access CiviCRM', 'view all contacts'],
  'smoke_contact_editor' => ['access CiviCRM', 'view all contacts', 'edit all contacts', 'delete contacts', 'edit all events'],
];
foreach ($roles as $rid => $permissions) {
  $role = Role::load($rid) ?? Role::create(['id' => $rid, 'label' => $rid]);
  foreach ($permissions as $permission) {
    $role->grantPermission($permission);
  }
  $role->save();
}
$users = [
  'smoke_none' => [],
  'smoke_viewer' => ['smoke_contact_viewer'],
  'smoke_editor' => ['smoke_contact_editor'],
  'smoke_acl' => ['smoke_contact_editor'],
];
$accounts = ['anonymous' => new AnonymousUserSession(), 'admin' => User::load(1)];
foreach ($users as $name => $user_roles) {
  $user = user_load_by_name($name) ?: User::create(['name' => $name, 'mail' => "$name@example.org", 'status' => 1]);
  foreach ($user_roles as $rid) {
    $user->addRole($rid);
  }
  $user->save();
  $accounts[$name] = $user;
}
$contactOf = static fn(User $user) => \CRM_Core_BAO_UFMatch::getContactId($user->id());
foreach (array_slice($accounts, 2) as $name => $user) {
  if (!$contactOf($user)) {
    \CRM_Core_BAO_UFMatch::synchronizeUFMatch($user, $user->id(), $user->getEmail(), 'Drupal8', NULL, 'Individual', TRUE);
  }
  printf("user %s uid %d contact %s roles %s\n", $name, $user->id(), $contactOf($user) ?: 'NONE', implode(',', $user->getRoles(TRUE)));
}

$groupId = static fn(string $name) => (int) $sql('SELECT id FROM civicrm_custom_group WHERE name = %1', [1 => [$name, 'String']]);
$aclGroup = \Civi\Api4\Group::save(FALSE)->setMatch(['name'])->addRecord(['name' => 'ce_smoke_acl', 'title' => 'CE smoke ACL users', 'group_type' => [1]])->execute()->first()['id'];
\Civi\Api4\GroupContact::save(FALSE)->setMatch(['group_id', 'contact_id'])->addRecord(['group_id' => $aclGroup, 'contact_id' => $contactOf($accounts['smoke_acl']), 'status' => 'Added'])->execute();
$aclRole = \Civi\Api4\OptionValue::save(FALSE)->setMatch(['option_group_id', 'name'])->addRecord(['option_group_id.name' => 'acl_role', 'name' => 'ce_smoke_acl_role', 'label' => 'CE smoke ACL role'])->execute()->first();
$aclRoleValue = \Civi\Api4\OptionValue::get(FALSE)->addWhere('id', '=', $aclRole['id'])->addSelect('value')->execute()->first()['value'];
\Civi\Api4\ACLEntityRole::save(FALSE)->setMatch(['acl_role_id', 'entity_table', 'entity_id'])->addRecord(['acl_role_id' => $aclRoleValue, 'entity_table' => 'civicrm_group', 'entity_id' => $aclGroup, 'is_active' => TRUE])->execute();
$acls = [
  'CE smoke: view Education History' => ['View', $groupId('ce_education')],
  'CE smoke: edit Skills' => ['Edit', $groupId('ce_skills')],
];
foreach ($acls as $acl_name => [$operation, $object_id]) {
  \Civi\Api4\ACL::save(FALSE)->setMatch(['name'])->addRecord([
    'name' => $acl_name,
    'entity_table' => 'civicrm_acl_role',
    'entity_id' => $aclRoleValue,
    'operation' => $operation,
    'object_table' => 'civicrm_custom_group',
    'object_id' => $object_id,
    'deny' => FALSE,
    // Active only during the restricted phases.
    'is_active' => FALSE,
  ])->execute();
}

// The records under test, each with a value its parent's page shows.
$valueOf = static fn(string $group, string $field, int $record): string => (string) \Civi\Api4\CustomValue::get($group, FALSE)->addSelect($field)->addWhere('id', '=', $record)->execute()->first()[$field];
$records = [
  'education' => ['ce_education', 47, 'civicrm_contact', 28, $valueOf('ce_education', 'school', 47)],
  'skills' => ['ce_skills', 1, 'civicrm_contact', 28, $valueOf('ce_skills', 'skill', 1)],
  'board' => ['ce_board_terms', 23, 'civicrm_contact', 47, $valueOf('ce_board_terms', 'role:label', 23)],
  'session' => ['ce_event_sessions', 1, 'civicrm_event', 1, $valueOf('ce_event_sessions', 'session_title', 1)],
];
$paths = [];
foreach ($records as $key => [$group, $id, $parent_type, $parent_id, $marker]) {
  $type = CustomRecordTypeRegistry::typeIdForName($group);
  $record = $etm->getStorage($type)->load($id);
  $paths[$key] = [
    'view' => $record->toUrl()->toString(),
    'update' => $record->toUrl('edit-form')->toString(),
    'delete' => $record->toUrl('delete-form')->toString(),
    'create' => $etm->getDefinition($type)->getLinkTemplate('add-form') . "?entity_id=$parent_id",
    'parent' => '/' . str_replace('_', '-', $parent_type) . "/$parent_id",
  ];
  printf("record %s: %s #%d of %s, marker '%s'\n", $key, $group, $id, $paths[$key]['parent'], $marker);
}

// ---------------------------------------------------------------------------
// HTTP as a user. Sessions are opened once and kept: a one-time login link
// is spent by its first use.
// ---------------------------------------------------------------------------
$client = \Drupal::httpClient();
$jars = [];
$login = static function (string $name) use (&$jars, $accounts, $client): CookieJar {
  if (!isset($jars[$name])) {
    $jars[$name] = new CookieJar();
    if ($name !== 'anonymous') {
      $path = parse_url(user_pass_reset_url(User::load($accounts[$name]->id())) . '/login', PHP_URL_PATH);
      $client->get(BASE . $path, ['cookies' => $jars[$name], 'allow_redirects' => FALSE, 'http_errors' => FALSE, 'verify' => FALSE]);
    }
  }
  return $jars[$name];
};
$request = static function (string $method, string $name, string $path, array $form = []) use ($login, $client): array {
  $options = ['cookies' => $login($name), 'allow_redirects' => FALSE, 'http_errors' => FALSE, 'verify' => FALSE, 'timeout' => 120];
  if ($form) {
    $options['form_params'] = $form;
  }
  $response = $client->request($method, BASE . $path, $options);
  return [$response->getStatusCode(), (string) $response->getBody(), $response->getHeaderLine('Location')];
};
$fetch = static fn(string $name, string $path) => $request('GET', $name, $path);
$shows = static fn(string $html, string $marker) => $marker !== '' && str_contains($html, htmlspecialchars($marker, ENT_QUOTES));

// Every cache that can hold what one user's CiviCRM calls returned.
$resetCaches = static function () use ($etm): void {
  foreach (['civicrm_entity', 'entity', 'render', 'page', 'dynamic_page_cache'] as $bin) {
    \Drupal::cache($bin)->deleteAll();
  }
  $etm->getStorage('user_role')->resetCache();
  $etm->getStorage('user')->resetCache();
  foreach (array_keys($etm->getDefinitions()) as $type) {
    if (str_starts_with($type, 'civicrm_')) {
      $etm->getAccessControlHandler($type)->resetCache();
      $etm->getStorage($type)->resetCache();
    }
  }
  \CRM_ACL_BAO_Cache::resetCache();
};

$expect = static function (string $user, string $record, string $op, bool $restricted): bool {
  if ($user === 'admin') {
    return TRUE;
  }
  $contact_edit = in_array($user, ['smoke_editor', 'smoke_acl'], TRUE);
  $contact_view = $contact_edit || $user === 'smoke_viewer';
  // Everyone has "view event info"; the editors have "edit all events".
  $parent = $record === 'session' ? ($op === 'view' || $contact_edit) : ($op === 'view' ? $contact_view : $contact_edit);
  $group = !$restricted || ($user === 'smoke_acl' && ($record === 'skills' || ($record === 'education' && $op === 'view')));
  return $parent && $group;
};

// The contact autocomplete a record add form offers, and its selection key.
[, $add_form] = $fetch('admin', $paths['education']['create']);
preg_match('#data-autocomplete-path="(/entity_reference_autocomplete/civicrm_contact/[^"]+)"#', $add_form, $m);
$autocomplete = html_entity_decode($m[1] ?? '');
$note("contact autocomplete: $autocomplete");
$suggest = static function (string $name, string $q) use ($fetch, $autocomplete): array {
  [$status, $body] = $fetch($name, "$autocomplete?q=" . rawurlencode($q));
  return [$status, json_decode($body, TRUE) ?: []];
};

$educationRows = static fn() => (int) $sql('SELECT COUNT(*) FROM civicrm_value_education_his_4 WHERE entity_id = 28');

// ---------------------------------------------------------------------------
// One phase.
// ---------------------------------------------------------------------------
$phase = static function (bool $restricted, bool $checkPermissions) use ($accounts, $records, $paths, $etm, $check, $note, $expect, $fetch, $request, $shows, $suggest, $resetCaches, $educationRows): void {
  $label = ($restricted ? 'restricted' : 'open') . ' custom data, check_permissions ' . ($checkPermissions ? 'on' : 'off');
  echo "\n== $label\n";
  \Drupal::configFactory()->getEditable('civicrm_entity.settings')->set('check_permissions', $checkPermissions)->save();
  $resetCaches();

  foreach ($accounts as $name => $account) {
    if ($account instanceof User) {
      $account = User::load($account->id());
    }
    foreach ($records as $key => [$group, $id, $parent_type, $parent_id, $marker]) {
      $type = CustomRecordTypeRegistry::typeIdForName($group);
      $record = $checkPermissions ? NULL : $etm->getStorage($type)->loadUnchanged($id);
      $summary = [];
      foreach (['view', 'update', 'delete', 'create'] as $op) {
        $expected = $expect($name, $key, $op === 'view' ? 'view' : 'update', $restricted);
        $entity_access = NULL;
        if (!$checkPermissions) {
          $entity_access = $op === 'create'
            ? $etm->getAccessControlHandler($type)->createAccess(NULL, $account, ['entity_id' => $parent_id])
            : $record->access($op, $account);
        }
        [$status] = $fetch($name, $paths[$key][$op]);
        $summary[] = sprintf('%s %s/%d', $op, $entity_access === NULL ? '-' : ($entity_access ? 'Y' : 'n'), $status);
        // The add form opens on the group's rule alone, by design; the parent
        // is checked on submit (see the POST checks below).
        // With check_permissions on, CiviCRM may refuse a read Drupal would
        // allow (Event.get also wants "access CiviEvent"): the page is then
        // not found, or forbidden when only the parent is refused. Never an
        // error.
        $allowed_codes = $checkPermissions ? [200, 403, 404] : [200];
        $denied_codes = $checkPermissions ? [403, 404, 302] : [403, 302];
        // The add form opens for whom may update a parent of the type and
        // edit the group (punch list J9), as creating the record needs.
        $ok = in_array($status, $expected ? $allowed_codes : $denied_codes, TRUE) && ($entity_access === NULL || $entity_access === $expected);
        $check($ok, "$name $op $key #$id", $ok ? "HTTP $status" : sprintf('expected %s, entity access %s, HTTP %d at %s', $expected ? 'allow' : 'deny', $entity_access === NULL ? 'not asked' : ($entity_access ? 'allow' : 'deny'), $status, $paths[$key][$op]));
      }

      // The parent's page lists the group's values in a computed field.
      [$status, $html] = $fetch($name, $paths[$key]['parent']);
      $may_view = $expect($name, $key, 'view', $restricted);
      $summary[] = sprintf('parent %d%s', $status, $status === 200 ? ($shows($html, $marker) ? ' shows values' : ' no values') : '');
      $check($status !== 500, "$name: {$paths[$key]['parent']} is no error", "HTTP $status");
      if ($status === 200) {
        $check(!$shows($html, $marker) || $may_view, "$name: {$paths[$key]['parent']} shows $key values only to who may view the record", $shows($html, $marker) ? 'shows them; record view denied' : '');
        // CiviCRM, deciding, may show less than Drupal would.
        if ($may_view && !$checkPermissions) {
          $check($shows($html, $marker), "$name: {$paths[$key]['parent']} shows the $key values the user may view", $shows($html, $marker) ? '' : 'missing');
        }
      }
      printf("  -- %-12s %-9s %s\n", $name, $key, implode('  ', $summary));
    }

    // Contact names through the add form's autocomplete.
    [$status, $suggestions] = $suggest($name, 'Terrell');
    $may_list = in_array($name, ['admin', 'smoke_viewer', 'smoke_editor', 'smoke_acl'], TRUE);
    $check($may_list || count($suggestions) === 0, "$name: contact autocomplete lists no contacts to a user who may not view them", sprintf('HTTP %d, %d suggestions%s', $status, count($suggestions), $suggestions ? ': ' . implode('; ', array_slice(array_column($suggestions, 'value'), 0, 3)) : ''));
    if ($name === 'admin') {
      $check(count($suggestions) > 0, 'admin: contact autocomplete finds "Terrell"', count($suggestions) . ' suggestions');
    }

    // Fixture Views pages need "access CiviCRM" and show every row.
    $views = [];
    foreach (['/smoke/education', '/smoke/skills', '/smoke/event-sessions', '/smoke/directory'] as $path) {
      [$status, $html] = $fetch($name, $path);
      $views[] = sprintf('%s %d (%d edit links)', $path, $status, preg_match_all('#/civicrm-cv-[a-z0-9-]+/\d+/edit#', $html));
    }
    $note("$name views: " . implode(', ', $views));
  }

  // Submitting the add form: the parent is checked here. Only those who may
  // update contact 28 and edit Education History add a record.
  foreach (array_keys($accounts) as $name) {
    $allowed = $expect($name, 'education', 'update', $restricted);
    [$status, $html] = $fetch($name, $paths['education']['create']);
    if ($status !== 200) {
      $check(!$allowed, "$name: education add form opens", "HTTP $status");
      continue;
    }
    preg_match('#name="form_build_id" value="([^"]+)"#', $html, $build);
    preg_match('#name="form_token" value="([^"]+)"#', $html, $token);
    preg_match('#name="form_id" value="([^"]+)"#', $html, $form_id);
    $before = $educationRows();
    $marker = "Smoke POST $name " . time();
    $form = [
      'form_build_id' => $build[1] ?? '',
      'form_id' => $form_id[1] ?? '',
      'custom_7[0][value]' => $marker,
      'op' => 'Save',
    ];
    if (isset($token[1])) {
      $form['form_token'] = $token[1];
    }
    [$status, $body, $location] = $request('POST', $name, $paths['education']['create'], $form);
    $after = $educationRows();
    $added = $after > $before;
    $error = preg_match('#data-drupal-messages[^>]*>(.*?)</main>#s', $body, $e) ? trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('#<h2[^>]*visually-hidden[^>]*>.*?</h2>#s', '', $e[1])))) : '';
    $check($added === $allowed, "$name: submitting the education add form for contact 28 " . ($allowed ? 'adds a record' : 'is refused'), sprintf('HTTP %d%s, rows %d -> %d%s', $status, $location ? " -> $location" : '', $before, $after, $error ? ", error: " . mb_strimwidth($error, 0, 400, '...') : ''));
    if ($added) {
      \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_value_education_his_4 WHERE entity_id = 28 AND school_7 = %1', [1 => [$marker, 'String']]);
    }
  }

  if (!$checkPermissions) {
    return;
  }

  // Cache order: does what one user's CiviCRM calls returned reach another?
  if ($restricted) {
    // smoke_editor and smoke_acl share their roles, so Drupal's caches treat
    // them alike; only CiviCRM's ACL tells them apart.
    $marker = $records['education'][4];
    foreach ([['smoke_acl', 'smoke_editor'], ['smoke_editor', 'smoke_acl']] as [$first, $second]) {
      $resetCaches();
      [, $html1] = $fetch($first, '/civicrm-contact/28');
      [, $html2] = $fetch($second, '/civicrm-contact/28');
      $shows1 = $shows($html1, $marker);
      $shows2 = $shows($html2, $marker);
      $check($shows1 === ($first === 'smoke_acl') && $shows2 === ($second === 'smoke_acl'), "cache order: contact 28 as $first, then $second", sprintf('%s %s the education values, then %s %s them', $first, $shows1 ? 'sees' : 'does not see', $second, $shows2 ? 'sees' : 'does not see'));
    }
  }
  else {
    foreach ([['anonymous', 'admin'], ['admin', 'anonymous']] as [$first, $second]) {
      $resetCaches();
      [, $s1] = $suggest($first, 'Terrell');
      [, $s2] = $suggest($second, 'Terrell');
      $admin = $first === 'admin' ? $s1 : $s2;
      $anonymous = $first === 'anonymous' ? $s1 : $s2;
      $check(count($admin) > 0 && count($anonymous) === 0, "cache order: autocomplete as $first, then $second", sprintf('%s gets %d, then %s gets %d', $first, count($s1), $second, count($s2)));
    }
  }
};

$phase(FALSE, FALSE);
$phase(FALSE, TRUE);

foreach (['anonymous', 'authenticated'] as $rid) {
  $role = Role::load($rid);
  if ($role->hasPermission('access all custom data')) {
    $restore['roles'][] = $rid;
    $role->revokePermission('access all custom data')->save();
  }
}
\Civi\Api4\ACL::update(FALSE)->addWhere('name', 'LIKE', 'CE smoke:%')->addValue('is_active', TRUE)->execute();

$phase(TRUE, FALSE);
$phase(TRUE, TRUE);

// Errors logged while the pages were served.
$errors = \Drupal::database()->query("SELECT type, message, variables FROM {watchdog} WHERE timestamp >= :t AND severity <= 3 ORDER BY wid", [':t' => $started])->fetchAll();
$check(count($errors) === 0, 'no errors logged', count($errors) . ' errors');
$seen = [];
foreach ($errors as $error) {
  $variables = @unserialize($error->variables, ['allowed_classes' => FALSE]) ?: [];
  $text = $error->type . ': ' . mb_strimwidth(strip_tags(strtr($error->message, array_map('strval', array_filter($variables, 'is_scalar')))), 0, 240, '...');
  $seen[$text] = ($seen[$text] ?? 0) + 1;
}
foreach (array_slice($seen, 0, 20, TRUE) as $text => $n) {
  printf("    %dx %s\n", $n, $text);
}

printf("\n%d passed, %d failed\n", $pass, count($fail));
