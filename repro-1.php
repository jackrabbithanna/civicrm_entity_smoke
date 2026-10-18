<?php
// Finding 1 repro (reversible): deactivate a group out of band, then rebuild.
\Drupal::service('civicrm')->initialize();
\CRM_Core_DAO::executeQuery("UPDATE civicrm_custom_group SET is_active = 0 WHERE name = 'ce_vehicles'");
foreach ([1, 2] as $n) {
  try {
    \Civi::rebuild(['system' => TRUE, 'triggers' => TRUE])->execute();
    echo "rebuild $n: ok\n";
  }
  catch (\Throwable $e) {
    echo "rebuild $n: " . get_class($e) . ': ' . $e->getMessage() . "\n";
  }
}
\CRM_Core_DAO::executeQuery("UPDATE civicrm_custom_group SET is_active = 1 WHERE name = 'ce_vehicles'");
echo "restored is_active = 1\n";
