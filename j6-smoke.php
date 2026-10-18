<?php

/**
 * @file
 * Punch list J6 and J39: the text format of rich text from CiviCRM.
 *
 *   ddev drush --uri=https://drupal-civi-composer-exp-d11.ddev.site \
 *     php:script scripts/smoke/j6-smoke.php
 *
 * Writes and restores. The fixture sets `filter_format` to `basic_html`,
 * which has CKEditor 5 here.
 *
 * 1. J6, without the browser: with the setting empty, the status report says
 *    rich text shows its tags; civicrm_entity_install() then sets
 *    `basic_html`. The setting is put back.
 * 2. J39, in headless Chromium as admin, with `basic_html`: a rich text value
 *    holding markup Basic HTML does not allow (a heading, a table, a style, a
 *    `<b>`, a `<div>`) is edited without CKEditor, with a note, and saving
 *    the form unchanged leaves CiviCRM's value byte-identical: event 1's
 *    description, a `ce_event_sessions` record's description, and a named
 *    Individual's `ce_profile_all.rich_text` (the last two not in `api3`: no record
 *    types, and the contact form needs I3's required fields). The event uses
 *    the module's widget, on a display never saved; the record and contact
 *    core's `text_textarea`, on the fixture's displays. Edited as HTML, the
 *    edit is saved and the rest kept. A value Basic HTML allows keeps
 *    CKEditor.
 * 3. With `full_html`, which allows everything, CKEditor stays and keeps all
 *    the markup. With `plain_text`, there is no editor and the value is
 *    unchanged.
 *
 * In `api3` the event form fails to save (punch list J40: API3's Event.get
 * gives a ContactReference custom field's name, not its ID, and validating
 * the reference throws), so only the editor and the note are checked there.
 * A save counts only with Drupal's "has been updated"; the fields the browser
 * finds invalid are listed with a failed save.
 *
 * Values are put back through API4 and the setting restored, in `finally`.
 */

use Behat\Mink\Driver\Selenium2Driver;
use Behat\Mink\Session;
use Drupal\civicrm_entity\Hook\RequirementsHooks;
use Drupal\user\Entity\User;

const BASE = 'https://drupal-civi-composer-exp-d11.ddev.site';

\Drupal::service('civicrm')->initialize();
$pass = 0;
$fail = [];
$check = static function (bool $ok, string $label, string $detail = '') use (&$pass, &$fail): void {
  $ok ? $pass++ : $fail[] = $label . ($detail ? " ($detail)" : '');
  printf("  [%s] %s%s\n", $ok ? 'ok' : 'FAIL', $label, $detail ? " -- $detail" : '');
};
register_shutdown_function(static function () use (&$pass, &$fail): void {
  printf("\nJ6/J39: %d passed, %d failed\n", $pass, count($fail));
  foreach ($fail as $label) {
    echo "  FAIL: $label\n";
  }
});
$mode = \Drupal::config('civicrm_entity.settings')->get('api_backend');
echo "Mode: $mode\n";
$settings = static fn() => \Drupal::configFactory()->getEditable('civicrm_entity.settings');
$was = $settings()->get('filter_format');
echo "filter_format: '$was'\n";

// Where the values live, and how to read and write them.
$fieldId = static fn(string $group, string $name): string => 'custom_' . \Civi\Api4\CustomField::get(FALSE)->addWhere('custom_group_id:name', '=', $group)->addWhere('name', '=', $name)->execute()->first()['id'];
$targets = [
  'event 1' => [
    'path' => '/civicrm-event/1/edit',
    'field' => 'description',
    'get' => static fn() => \Civi\Api4\Event::get(FALSE)->addSelect('description')->addWhere('id', '=', 1)->execute()->first()['description'],
    'set' => static fn(?string $value) => \Civi\Api4\Event::update(FALSE)->addWhere('id', '=', 1)->addValue('description', $value)->execute(),
  ],
];
if ($mode !== 'api3') {
  $targets['session record 1'] = [
    'path' => '/civicrm-cv-ce-event-sessions/1/edit',
    'field' => $fieldId('ce_event_sessions', 'description'),
    'get' => static fn() => \Civi\Api4\CustomValue::get('ce_event_sessions', FALSE)->addSelect('description')->addWhere('id', '=', 1)->execute()->first()['description'],
    'set' => static fn(?string $value) => \Civi\Api4\CustomValue::update('ce_event_sessions', FALSE)->addWhere('id', '=', 1)->addValue('description', $value)->execute(),
  ];
  // A named Individual: the contact form requires first and last names.
  $contactId = (int) \Civi\Api4\Contact::get(FALSE)
    ->addSelect('id')
    ->addWhere('contact_type', '=', 'Individual')
    ->addWhere('first_name', 'IS NOT EMPTY')
    ->addWhere('last_name', 'IS NOT EMPTY')
    ->addWhere('ce_profile_all.rich_text', 'IS NOT EMPTY')
    ->addOrderBy('id')
    ->setLimit(1)
    ->execute()
    ->first()['id'];
  $targets["contact $contactId"] = [
    'path' => "/civicrm-contact/$contactId/edit",
    'field' => $fieldId('ce_profile_all', 'rich_text'),
    'get' => static fn() => \Civi\Api4\Contact::get(FALSE)->addSelect('ce_profile_all.rich_text')->addWhere('id', '=', $contactId)->execute()->first()['ce_profile_all.rich_text'],
    'set' => static fn(?string $value) => \Civi\Api4\Contact::update(FALSE)->addWhere('id', '=', $contactId)->addValue('ce_profile_all.rich_text', $value)->execute(),
  ];
}
$originals = array_map(static fn(array $target) => $target['get'](), $targets);

$rich = '<h1>Heading one</h1><p>Plain <span style="color:red">red</span> <u>under</u> <b>bold</b></p><table><tbody><tr><td>Cell</td></tr></tbody></table><div class="note">A div</div>';
$fits = '<p>Plain <strong>bold</strong> and <a href="https://example.org/">a link</a></p>';

$session = NULL;
try {
  echo "\n1. J6: no format chosen\n";
  $settings()->set('filter_format', '')->save();
  $requirements = \Drupal::service(RequirementsHooks::class)->runtimeRequirements();
  $entry = $requirements['civicrm_entity_filter_format'] ?? NULL;
  $check($entry !== NULL, 'the status report has an entry for it');
  $check(str_contains((string) ($entry['description'] ?? ''), 'Plain text'), 'it names the fallback format', strip_tags((string) ($entry['description'] ?? '')));
  \Drupal::moduleHandler()->loadInclude('civicrm_entity', 'install');
  civicrm_entity_install(FALSE);
  $check(\Drupal::config('civicrm_entity.settings')->get('filter_format') === 'basic_html', 'civicrm_entity_install() chooses Basic HTML', json_encode(\Drupal::config('civicrm_entity.settings')->get('filter_format')));
  $requirements = \Drupal::service(RequirementsHooks::class)->runtimeRequirements();
  $check(!isset($requirements['civicrm_entity_filter_format']), 'then the status report has no entry');
  $settings()->set('filter_format', $was)->save();

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

  // Opens a form, says whether CKEditor is on the field, and saves it. Not in
  // `api3`, where the event form fails to save (punch list J40: API3's
  // Event.get gives a ContactReference custom field's name, not its ID);
  // `-- --api3-saves` tries anyway.
  $saves = $mode !== 'api3' || in_array('--api3-saves', $extra ?? [], TRUE);
  $edit = static function (array $target, ?string $replace = NULL) use ($session, $page, $saves): array {
    \Drupal::entityTypeManager()->getStorage('civicrm_event')->resetCache();
    $session->visit(BASE . $target['path']);
    $session->wait(10000, 'document.readyState === "complete"');
    $textarea = 'textarea[name="' . $target['field'] . '[0][value]"]';
    $editor = $session->wait(4000, "document.querySelector('$textarea') && document.querySelector('$textarea').nextElementSibling && document.querySelector('$textarea').nextElementSibling.classList.contains('ck-editor')");
    $wrapper = $page->find('css', $textarea)?->getParent()?->getParent()?->getParent()?->getText() ?? '';
    $note = str_contains($wrapper, 'Shown as HTML');
    $invalid = $session->evaluateScript("return Array.from(document.querySelectorAll('form.civicrm-entity-form :invalid, form[id*=\"civicrm\"] :invalid')).map(e => e.name).filter(n => n).join(' ')");
    // Under CKEditor, the textarea is hidden: there is no HTML to edit.
    if ($replace !== NULL && !$editor) {
      $page->find('css', $textarea)?->setValue($replace);
    }
    $said = '';
    if ($saves) {
      $page->pressButton('Save');
      $session->wait(10000, 'document.readyState === "complete"');
      $said = trim(preg_replace('/\s+/', ' ', $page->find('css', '[data-drupal-messages]')?->getText() ?? ''));
    }
    return [(bool) $editor, $note, $said, str_contains($said, 'has been updated'), (string) $invalid];
  };

  echo "\n2. J39: Basic HTML, CKEditor 5\n";
  foreach ($targets as $name => $target) {
    $target['set']($rich);
    [$editor, $note, $said, $saved, $invalid] = $edit($target);
    $after = $target['get']();
    $check(!$editor, "$name: markup Basic HTML does not allow is edited without CKEditor");
    $check($note, "$name: with a note saying so");
    if (!$saves) {
      echo "  api3: not saved (J40)\n";
      continue;
    }
    $check($saved && $after === $rich, "$name: saved unchanged, CiviCRM's value is unchanged", ($after === $rich ? "said: $said" : "now: $after") . ($invalid ? "; invalid: $invalid" : ""));
  }
  $target = $targets['event 1'];
  if ($saves) {
    $target['set']($rich);
    [$editor, , $said, $saved] = $edit($target, $rich . '<p>Added</p>');
    $check(!$editor && $saved && $target['get']() === $rich . '<p>Added</p>', 'event 1: edited as HTML, the edit is saved and the rest kept', ($editor ? 'CKEditor, no HTML to edit; ' : '') . $said . ' ' . $target['get']());
  }
  $target['set']($fits);
  [$editor, $note, $said, $saved] = $edit($target);
  $after = $target['get']();
  $check($editor && !$note, 'event 1: markup Basic HTML allows keeps CKEditor, with no note');
  if ($saves) {
    $check($saved && $after === $fits, 'event 1: saved unchanged through CKEditor, it is unchanged', $after === $fits ? $said : "now: $after");
  }

  echo "\n3. Other formats\n";
  $settings()->set('filter_format', 'full_html')->save();
  $target['set']($rich);
  [$editor, $note, , $saved] = $edit($target);
  $after = $target['get']();
  $check($editor && !$note, 'full_html: CKEditor stays');
  if ($saves) {
    $kept = array_filter(['<h1>', '<table', 'color:red', '<u>', '<div class="note">'], static fn(string $markup): bool => !str_contains($after, $markup));
    $check($saved && !$kept, 'full_html: saved unchanged, all the markup is kept', $kept ? 'lost: ' . implode(' ', $kept) : "now: $after");
  }

  $settings()->set('filter_format', 'plain_text')->save();
  $target['set']($rich);
  [$editor, $note, , $saved] = $edit($target);
  $check(!$editor && !$note, 'plain_text: no editor, and no note');
  if ($saves) {
    $check($saved && $target['get']() === $rich, 'plain_text: saved unchanged, the value is unchanged');
  }
}
finally {
  $session?->stop();
  $settings()->set('filter_format', $was)->save();
  foreach ($targets as $name => $target) {
    $target['set']($originals[$name]);
  }
  echo "\nRestored filter_format '$was' and the values.\n";
}
