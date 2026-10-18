<?php

/**
 * @file
 * Re-runs CiviCRM's hook_install steps with the generated sample data.
 *
 * Usage: drush --uri=URL php:script scripts/smoke/civi-reinstall-sample.php
 */

\Drupal::moduleHandler()->loadInclude('civicrm', 'install');
$setup = _civicrm_setup();
$setup->getModel()->loadGenerated = TRUE;
$installed = $setup->checkInstalled();
printf("settings installed: %s, database installed: %s\n",
  var_export($installed->isSettingInstalled(), TRUE),
  var_export($installed->isDatabaseInstalled(), TRUE));
if ($installed->isDatabaseInstalled()) {
  throw new \RuntimeException('CiviCRM database already installed.');
}
if (!$installed->isSettingInstalled()) {
  $setup->installFiles();
}
try {
  $setup->installDatabase();
}
catch (\CRM_Core_Exception $e) {
  // API3 (used by Setup's InstallExtensions step) wraps the real exception.
  $data = $e->getErrorData();
  fwrite(STDERR, "installDatabase failed: " . $e->getMessage() . "\n");
  foreach (['error_code', 'exception', 'trace'] as $key) {
    if (!empty($data[$key])) {
      fwrite(STDERR, "$key: " . (is_string($data[$key]) ? $data[$key] : print_r($data[$key], TRUE)) . "\n");
    }
  }
  for ($previous = $e->getPrevious(); $previous; $previous = $previous->getPrevious()) {
    fwrite(STDERR, 'previous: ' . get_class($previous) . ': ' . $previous->getMessage() . "\n" . $previous->getTraceAsString() . "\n");
  }
  throw $e;
}
echo "done\n";
