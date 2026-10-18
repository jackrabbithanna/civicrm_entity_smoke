<?php

/**
 * @file
 * Punch list J37: a location relationship's Views UI form shows the location
 * type it was saved with, and applying it unchanged keeps it.
 *
 *   ddev drush --uri=https://drupal-civi-composer-exp-d11.ddev.site \
 *     php:script scripts/smoke/j37-smoke.php
 *
 * Writes a View, `j37_smoke`, on contacts with three location relationships:
 * address with the default location type, email with Work (2), and phone
 * with Any (0) and phone type Mobile (2). In headless Chromium as admin, each
 * relationship's form (the nojs one) is opened: the checked radios are what
 * was saved. It is applied unchanged and the View saved, and the View's
 * configuration still holds what was saved. The View is deleted on shutdown.
 */

use Behat\Mink\Driver\Selenium2Driver;
use Behat\Mink\Session;
use Drupal\user\Entity\User;
use Drupal\views\Entity\View;
use Drupal\views\Views;

const BASE = 'https://drupal-civi-composer-exp-d11.ddev.site';

\Drupal::service('civicrm')->initialize();
$pass = 0;
$fail = [];
$check = static function (bool $ok, string $label, string $detail = '') use (&$pass, &$fail): void {
  $ok ? $pass++ : $fail[] = $label . ($detail ? " ($detail)" : '');
  printf("  [%s] %s%s\n", $ok ? 'ok' : 'FAIL', $label, $detail ? " -- $detail" : '');
};
$mode = \Drupal::config('civicrm_entity.settings')->get('api_backend');
echo "Mode: $mode\n";

$session = NULL;
register_shutdown_function(static function () use (&$pass, &$fail): void {
  printf("\nJ37: %d passed, %d failed\n", $pass, count($fail));
  foreach ($fail as $label) {
    echo "  FAIL: $label\n";
  }
});

$saved = [
  'reverse__civicrm_address__contact_id' => ['location_type' => 'default', 'is_primary' => TRUE],
  'reverse__civicrm_email__contact_id' => ['location_type' => '2'],
  'reverse__civicrm_phone__contact_id' => ['location_type' => '0', 'phone_type' => 2],
];
View::load('j37_smoke')?->delete();
$view = Views::executableFactory()->get(View::create(['id' => 'j37_smoke', 'label' => 'J37 smoke', 'base_table' => 'civicrm_contact']));
$view->setDisplay('default');
foreach ($saved as $field => $options) {
  $view->addHandler('default', 'relationship', 'civicrm_contact', $field, $options);
}
// A View with no field does not save from the Views UI.
$view->addHandler('default', 'field', 'civicrm_contact', 'display_name');
$view->storage->save();
$config = static fn(): array => \Drupal::configFactory()->reset('views.view.j37_smoke')->get('views.view.j37_smoke')->get('display.default.display_options.relationships');
echo "\n1. As saved\n";
foreach ($saved as $field => $options) {
  foreach ($options as $option => $value) {
    $check(($config()[$field][$option] ?? NULL) == $value, "$field: $option is " . json_encode($value), json_encode($config()[$field][$option] ?? NULL));
  }
}

try {
echo "\n2. The Views UI forms, in the browser (admin)\n";
$driver = new Selenium2Driver('chrome', [
  'browserName' => 'chrome',
  'goog:chromeOptions' => [
    'w3c' => FALSE,
    'args' => ['--headless', '--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu', '--ignore-certificate-errors', '--window-size=1400,1200'],
  ],
], 'http://selenium-chrome:4444/wd/hub');
$session = new Session($driver);
$session->start();
$session->visit(BASE . parse_url(user_pass_reset_url(User::load(1)) . '/login', PHP_URL_PATH));
$page = $session->getPage();
foreach ($saved as $field => $options) {
  $session->visit(BASE . "/admin/structure/views/nojs/handler/j37_smoke/default/relationship/$field");
  $session->wait(10000, 'document.readyState === "complete"');
  foreach ($options as $option => $value) {
    if (is_bool($value)) {
      $box = $page->find('css', "input[type=checkbox][name=\"options[$option]\"]");
      $check($box?->isChecked() === $value, "$field: $option shows " . json_encode($value), json_encode($box?->isChecked()));
      continue;
    }
    $checked = $page->find('css', "input[type=radio][name=\"options[$option]\"]:checked");
    $label = $checked ? trim($page->find('css', 'label[for="' . $checked->getAttribute('id') . '"]')?->getText() ?? '') : '';
    $check($checked?->getValue() === (string) $value, "$field: $option shows " . json_encode($value), json_encode($checked?->getValue()) . " ($label)");
  }
  $page->pressButton('Apply');
  $session->wait(10000, 'document.readyState === "complete"');
}
$session->visit(BASE . '/admin/structure/views/view/j37_smoke');
$session->wait(10000, 'document.readyState === "complete"');
$page->pressButton('Save');
$session->wait(10000, 'document.readyState === "complete"');
$status = trim(preg_replace('/\s+/', ' ', $page->find('css', '[data-drupal-messages]')?->getText() ?? ''));
$check(str_contains($status, 'has been saved'), 'the View saves', $status);

echo "\n3. Applied unchanged and saved\n";
foreach ($saved as $field => $options) {
  foreach ($options as $option => $value) {
    $check(($config()[$field][$option] ?? NULL) == $value, "$field: $option is still " . json_encode($value), json_encode($config()[$field][$option] ?? NULL));
  }
}
}
finally {
  $session?->stop();
  View::load('j37_smoke')?->delete();
  \Drupal::service('tempstore.shared')->get('views')->delete('j37_smoke');
  echo "\nDeleted View j37_smoke.\n";
}
