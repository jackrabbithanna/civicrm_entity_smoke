<?php

/**
 * @file
 * Punch list J1 and J29: a multi-record group gone and back.
 *
 * Run by j1-smoke.sh in five phases, each its own process and named by the
 * script's argument (`drush php:script j1-smoke.php gone`), with a `drush cr`
 * between `back` and `check`:
 *
 * - gone: deactivates ce_vehicles in SQL, runs CiviCRM's system rebuild twice
 *   (it rebuilds Drupal's routes), and checks pages while the group is gone.
 * - back: reactivates it in SQL; CiviCRM's cache still has it inactive.
 * - check: after one `drush cr`, the type, its routes and its pages are back.
 * - off (J29): disables the group through API4, as CiviCRM's UI does, so
 *   the routes stay. The type's pages, its own and Field UI's, are not found,
 *   and the owner's cached page drops the add link.
 * - on (J29): enables it again, which brings them back without a cache
 *   rebuild. A process of its own, as a second request in CiviCRM's UI would
 *   be: one process invalidates a tag only once (J30).
 *
 * The group is reactivated on shutdown if `gone` or `off` fails part-way.
 */

use Drupal\user\Entity\User;
use GuzzleHttp\Cookie\CookieJar;

const BASE = 'https://drupal-civi-composer-exp-d11.ddev.site';
const GROUP = 'ce_vehicles';
const TYPE = 'civicrm_cv_ce_vehicles';

$phase = $extra[0] ?? '';
\Drupal::service('civicrm')->initialize();
$etm = \Drupal::entityTypeManager();
$pass = 0;
$fail = [];
$check = static function (bool $ok, string $label, string $detail = '') use (&$pass, &$fail): void {
  $ok ? $pass++ : $fail[] = $label . ($detail ? " ($detail)" : '');
  printf("  [%s] %s%s\n", $ok ? 'ok' : 'FAIL', $label, $detail ? " -- $detail" : '');
};
$note = static fn(string $text) => printf("  [--] %s\n", $text);
$setActive = static fn(bool $active) => \CRM_Core_DAO::executeQuery(
  'UPDATE civicrm_custom_group SET is_active = %1 WHERE name = %2',
  [1 => [(int) $active, 'Integer'], 2 => [GROUP, 'String']],
);
$civiCacheActive = static function (): ?bool {
  $active = array_column(\CRM_Core_BAO_CustomGroup::getAll(['is_multiple' => TRUE]), 'is_active', 'name');
  return isset($active[GROUP]) ? (bool) $active[GROUP] : NULL;
};
$setApi4 = static fn(bool $active) => \Civi\Api4\CustomGroup::update(FALSE)
  ->addWhere('name', '=', GROUP)
  ->addValue('is_active', $active)
  ->execute();
$hasRoute = static fn(): bool => \Drupal::service('router.route_provider')->getRoutesByNames(['entity.' . TYPE . '.canonical']) !== [];

// A vehicle record and its owner, an education record as a control.
$table = static fn(string $group) => \CRM_Core_DAO::singleValueQuery('SELECT table_name FROM civicrm_custom_group WHERE name = %1', [1 => [$group, 'String']]);
[$vehicle, $owner] = array_values(\CRM_Core_DAO::executeQuery('SELECT id, entity_id FROM ' . $table(GROUP) . ' ORDER BY id LIMIT 1')->fetchAll()[0]);
[$education] = array_values(\CRM_Core_DAO::executeQuery('SELECT id FROM ' . $table('ce_education') . ' ORDER BY id LIMIT 1')->fetchAll()[0]);
$paths = [
  'vehicle' => "/civicrm-cv-ce-vehicles/$vehicle",
  'owner' => "/civicrm-contact/$owner",
  'education' => "/civicrm-cv-ce-education/$education",
  'status' => '/admin/reports/status',
  'vehicles view' => '/smoke/vehicles',
  'add form' => "/civicrm-cv-ce-vehicles/add?entity_id=$owner",
  'collection' => '/admin/structure/civicrm-entity/civicrm-cv-ce-vehicles',
  'field ui' => '/admin/structure/civicrm-entity/civicrm-cv-ce-vehicles/fields',
];
$addLink = '/civicrm-cv-ce-vehicles/add';

// HTTP as uid 1.
$client = \Drupal::httpClient();
$jar = new CookieJar();
$login = parse_url(user_pass_reset_url(User::load(1)) . '/login', PHP_URL_PATH);
$client->get(BASE . $login, ['cookies' => $jar, 'allow_redirects' => FALSE, 'http_errors' => FALSE, 'verify' => FALSE]);
$fetch = static function (string $path) use ($client, $jar): array {
  $response = $client->get(BASE . $path, ['cookies' => $jar, 'allow_redirects' => FALSE, 'http_errors' => FALSE, 'verify' => FALSE, 'timeout' => 120]);
  // BigPipe streams placeholders as JSON, with its slashes escaped.
  return [$response->getStatusCode(), str_replace('\\/', '/', (string) $response->getBody())];
};

switch ($phase) {
  case 'gone':
    echo "== gone: " . GROUP . " deactivated in SQL, CiviCRM system rebuild x2\n";
    $check($etm->hasDefinition(TYPE) && $hasRoute(), 'precondition: type defined and routed');
    // Cached as it would be on a live site.
    [, $html] = $fetch($paths['owner']);
    $check(str_contains($html, $addLink), 'precondition: owner page offers the add-vehicle link');
    $restore = TRUE;
    register_shutdown_function(static function () use (&$restore, $setActive): void {
      if ($restore) {
        $setActive(TRUE);
        echo "  [--] shutdown: reactivated " . GROUP . " in SQL\n";
      }
    });
    $setActive(FALSE);
    foreach ([1, 2] as $n) {
      try {
        // As cv flush / "Cleanup caches": 6.19 rebuilds Drupal's routes under
        // `triggers`, 6.21 under `router`.
        \Civi::rebuild(['system' => TRUE, 'triggers' => TRUE, 'router' => TRUE])->execute();
        $check(TRUE, "CiviCRM system rebuild $n");
      }
      catch (\Throwable $e) {
        $check(FALSE, "CiviCRM system rebuild $n", get_class($e) . ': ' . $e->getMessage());
      }
    }
    $check(!$etm->hasDefinition(TYPE), 'type no longer defined');
    $check(!$hasRoute(), 'type no longer routed');
    [$status] = $fetch($paths['vehicle']);
    $check($status === 404, 'record page is 404', (string) $status);
    [$status, $html] = $fetch($paths['owner']);
    $check($status === 200, 'owner page is 200', (string) $status);
    $check(!str_contains($html, $addLink), 'owner page no longer offers the add-vehicle link');
    foreach (['education', 'status'] as $key) {
      [$status] = $fetch($paths[$key]);
      $check($status === 200, "$key page is 200", (string) $status);
    }
    [$status] = $fetch($paths['vehicles view']);
    $note("vehicles View over the gone type: $status (Views on a vanished type are J16)");
    $restore = FALSE;
    break;

  case 'back':
    echo "== back: " . GROUP . " reactivated in SQL\n";
    $setActive(TRUE);
    $check($civiCacheActive() === FALSE, "CiviCRM's cache still has the group inactive");
    break;

  case 'check':
    echo "== check: after one drush cr\n";
    $check($civiCacheActive() === TRUE, "CiviCRM's cache has the group active");
    $check($etm->hasDefinition(TYPE), 'type defined');
    $check(\Drupal::service('entity.last_installed_schema.repository')->getLastInstalledDefinition(TYPE) !== NULL, 'type installed');
    $check(\Drupal::entityDefinitionUpdateManager()->getChangeList() === [], 'no entity updates pending');
    $check($hasRoute(), 'type routed');
    foreach (['vehicle', 'owner', 'education', 'status', 'vehicles view'] as $key) {
      [$status, $html] = $fetch($paths[$key]);
      $check($status === 200, "$key page is 200", (string) $status);
      if ($key === 'owner') {
        $check(str_contains($html, $addLink), 'owner page offers the add-vehicle link');
      }
    }
    break;

  case 'off':
    echo "== off: " . GROUP . " disabled through API4\n";
    $check($etm->hasDefinition(TYPE) && $hasRoute(), 'precondition: type defined and routed');
    // Cached as it would be on a live site.
    [, $html] = $fetch($paths['owner']);
    $check(str_contains($html, $addLink), 'precondition: owner page offers the add-vehicle link');
    $restore = TRUE;
    register_shutdown_function(static function () use (&$restore, $setApi4): void {
      if ($restore) {
        $setApi4(TRUE);
        echo "  [--] shutdown: re-enabled " . GROUP . " through API4\n";
      }
    });
    $setApi4(FALSE);
    $check(!$etm->hasDefinition(TYPE), 'type no longer defined');
    $check($hasRoute(), 'type still routed');
    foreach (['vehicle', 'add form', 'collection', 'field ui'] as $key) {
      [$status] = $fetch($paths[$key]);
      $check($status === 404, "$key page is 404", (string) $status);
    }
    [$status, $html] = $fetch($paths['owner']);
    $check($status === 200, 'owner page is 200', (string) $status);
    $check(!str_contains($html, $addLink), 'owner page no longer offers the add-vehicle link');
    [$status] = $fetch($paths['vehicles view']);
    $note("vehicles View over the gone type: $status (Views on a vanished type are J16)");
    $restore = FALSE;
    break;

  case 'on':
    echo "== on: " . GROUP . " enabled through API4\n";
    $setApi4(TRUE);
    $check($etm->hasDefinition(TYPE), 'type defined again');
    foreach (['vehicle', 'add form', 'collection', 'field ui', 'vehicles view'] as $key) {
      [$status] = $fetch($paths[$key]);
      $check($status === 200, "$key page is 200 again", (string) $status);
    }
    [, $html] = $fetch($paths['owner']);
    $check(str_contains($html, $addLink), 'owner page offers the add-vehicle link again');
    $check(isset(\Drupal::service('views.views_data')->get(TYPE)['table']['base']), 'Views data describes the type again');
    break;

  default:
    throw new \InvalidArgumentException('Pass the phase: gone, back, check, off or on.');
}

printf("%s: %d passed, %d failed\n", $phase, $pass, count($fail));
if ($fail) {
  exit(1);
}
