<?php
\Drupal::service('civicrm')->initialize();
$f = \Civi\Api4\CustomField::get(FALSE)->addWhere('custom_group_id.name', '=', 'ce_profile_all')->addWhere('name', '=', 'autocomplete')->execute()->first();
echo json_encode(array_intersect_key($f, array_flip(['id', 'name', 'data_type', 'html_type', 'option_group_id', 'serialize', 'fk_entity', 'filter']))), "\n";
$api4 = \Civi\Api4\Contact::getFields(FALSE)->setLoadOptions(TRUE)->addWhere('name', '=', 'ce_profile_all.autocomplete')->execute()->first();
echo 'api4: ', json_encode(array_intersect_key($api4, array_flip(['input_type', 'fk_entity', 'options', 'suffixes', 'data_type', 'serialize']))), "\n";
$api4b = \Civi\Api4\Contact::getFields(FALSE)->setLoadOptions(['id', 'name', 'label'])->addWhere('name', '=', 'ce_profile_all.autocomplete')->execute()->first();
echo 'api4 options (full): ', json_encode($api4b['options'] ?? NULL), "\n";
echo 'api3: ', json_encode(civicrm_api3('Contact', 'getoptions', ['field' => 'custom_' . $f['id']])['values']), "\n";
echo 'mode: ', \Drupal::config('civicrm_entity.settings')->get('api_backend'), "\n";
