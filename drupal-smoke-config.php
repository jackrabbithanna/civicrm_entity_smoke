<?php

/**
 * @file
 * Builds Drupal configuration on top of the smoke-test CiviCRM custom data.
 *
 * Run after civi-seed-custom-data.php and a cache rebuild:
 *   ddev drush --uri=https://drupal-civi-composer-exp-d11.ddev.site \
 *     php:script scripts/smoke/drupal-smoke-config.php
 *
 * Idempotent: displays are rebuilt in place, and every View, block and menu
 * link this script owns (prefix/marker `ce_smoke`) is deleted and recreated.
 *
 * Builds:
 * - View modes: civicrm_contact.ce_summary, civicrm_event.ce_summary, and
 *   <record type>.ce_compact for each multi-record group's record type.
 * - Form modes: <record type>.add (used by the record add form) and
 *   civicrm_contact.edit (used by the contact edit form).
 * - View/form displays for the record types, Contact, Event and Activity.
 * - Views of the record types (as base tables, through the parent's reverse
 *   relationship, through the parent's custom value tables, aggregated, and
 *   rendered in a view mode), with pages under /smoke and two blocks placed
 *   on contact and event pages.
 * - A "CE smoke" menu in the main menu linking every page.
 */

use Civi\Api4\CustomField;
use Drupal\block\Entity\Block;
use Drupal\civicrm_entity\CustomRecord\CustomRecordTypeRegistry;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityFormMode;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\views\Entity\View;
use Drupal\views\Views;

\Drupal::service('civicrm')->initialize();
$efm = \Drupal::service('entity_field.manager');
$viewsData = \Drupal::service('views.views_data');

// ---- CiviCRM metadata ---------------------------------------------------------

// Active custom fields, keyed by group name and field name, in weight order.
$custom = [];
$htmlType = [];
foreach (CustomField::get(FALSE)
  ->addSelect('id', 'name', 'label', 'html_type', 'column_name', 'custom_group_id:name', 'custom_group_id.table_name')
  ->addWhere('is_active', '=', TRUE)
  ->addWhere('custom_group_id.is_active', '=', TRUE)
  ->addOrderBy('custom_group_id')
  ->addOrderBy('weight')
  ->execute() as $field) {
  $field['drupal'] = 'custom_' . $field['id'];
  $custom[$field['custom_group_id:name']][$field['name']] = $field;
  $htmlType[$field['drupal']] = $field['html_type'];
}
// The Drupal field name of a custom field.
$cf = static function (string $group, string $name) use ($custom): string {
  return $custom[$group][$name]['drupal'] ?? throw new \RuntimeException("No custom field $group.$name");
};
// The Drupal field names of a group's fields, optionally limited to some.
$cfs = static function (string $group, ?array $names = NULL) use ($custom, $cf): array {
  return array_map(static fn(string $name): string => $cf($group, $name), $names ?? array_keys($custom[$group]));
};
// A custom field's column in its group's value table.
$column = static function (string $group, string $name) use ($custom): string {
  return $custom[$group][$name]['column_name'] ?? throw new \RuntimeException("No custom field $group.$name");
};
// A group's value table.
$valueTable = static fn(string $group): string => reset($custom[$group])['custom_group_id.table_name'];
$recordType = static fn(string $group): string => CustomRecordTypeRegistry::typeIdForName($group);

// ---- Formatters and widgets ---------------------------------------------------

/**
 * A formatter configuration suited to a field.
 */
$formatter = static function (FieldDefinitionInterface $definition): array {
  return match ($definition->getType()) {
    'string' => ['type' => 'string', 'settings' => ['link_to_entity' => FALSE]],
    'string_long' => ['type' => 'basic_string', 'settings' => []],
    'text_long', 'text', 'text_with_summary' => ['type' => 'text_default', 'settings' => []],
    'list_string', 'list_integer', 'list_float' => ['type' => 'list_default', 'settings' => []],
    'integer' => ['type' => 'number_integer', 'settings' => ['thousand_separator' => '', 'prefix_suffix' => TRUE]],
    'float', 'decimal' => ['type' => 'number_decimal', 'settings' => ['thousand_separator' => ',', 'decimal_separator' => '.', 'scale' => 2, 'prefix_suffix' => TRUE]],
    'boolean' => ['type' => 'boolean', 'settings' => ['format' => 'yes-no', 'format_custom_true' => '', 'format_custom_false' => '']],
    'datetime' => [
      'type' => 'datetime_custom',
      'settings' => [
        'date_format' => $definition->getSetting('datetime_type') === 'date' ? 'M j, Y' : 'M j, Y g:i a',
        'timezone_override' => '',
      ],
    ],
    'timestamp' => ['type' => 'timestamp', 'settings' => []],
    'entity_reference' => ['type' => 'entity_reference_label', 'settings' => ['link' => TRUE]],
    'email' => ['type' => 'basic_string', 'settings' => []],
    default => [],
  };
};

/**
 * A widget configuration suited to a field and its CiviCRM html type.
 */
$widget = static function (FieldDefinitionInterface $definition, string $name) use ($htmlType): array {
  $buttons = in_array($htmlType[$name] ?? '', ['Radio', 'CheckBox', 'Toggle'], TRUE);
  return match ($definition->getType()) {
    'string' => ['type' => 'string_textfield', 'settings' => ['size' => 60, 'placeholder' => '']],
    'string_long' => ['type' => 'string_textarea', 'settings' => ['rows' => 4, 'placeholder' => '']],
    'list_string', 'list_integer', 'list_float' => ['type' => $buttons ? 'options_buttons' : 'options_select', 'settings' => []],
    'integer', 'float', 'decimal' => ['type' => 'number', 'settings' => ['placeholder' => '']],
    'boolean' => ['type' => 'boolean_checkbox', 'settings' => ['display_label' => TRUE]],
    'datetime' => ['type' => 'datetime_default', 'settings' => []],
    'entity_reference' => ['type' => 'entity_reference_autocomplete', 'settings' => ['match_operator' => 'CONTAINS', 'match_limit' => 10, 'size' => 60, 'placeholder' => '']],
    // text_long and anything else: the field's own default widget.
    default => [],
  };
};

// ---- Display modes ------------------------------------------------------------

$ensureMode = static function (string $class, string $entityTypeId, string $mode, string $label): void {
  $id = "$entityTypeId.$mode";
  if (!$class::load($id)) {
    $class::create(['id' => $id, 'label' => $label, 'targetEntityType' => $entityTypeId, 'cache' => TRUE])->save();
    printf("  + %s %s\n", $class === EntityViewMode::class ? 'view mode' : 'form mode', $id);
  }
};

/**
 * Replaces a display's components with the given ones, in order.
 *
 * @param string $context
 *   'view' or 'form'.
 * @param array $components
 *   Field names, or field name => option overrides.
 */
$saveDisplay = static function (string $context, string $entityTypeId, string $mode, array $components) use ($efm, $formatter, $widget): void {
  $definitions = $efm->getFieldDefinitions($entityTypeId, $entityTypeId);
  $class = $context === 'view' ? EntityViewDisplay::class : EntityFormDisplay::class;
  $id = "$entityTypeId.$entityTypeId.$mode";
  $display = $class::load($id) ?? $class::create([
    'targetEntityType' => $entityTypeId,
    'bundle' => $entityTypeId,
    'mode' => $mode,
    'status' => TRUE,
  ]);
  foreach (array_keys($display->getComponents()) as $name) {
    $display->removeComponent($name);
  }
  $weight = 0;
  $skipped = [];
  foreach ($components as $name => $overrides) {
    if (is_int($name)) {
      [$name, $overrides] = [$overrides, []];
    }
    $definition = $definitions[$name] ?? NULL;
    if (!$definition) {
      $skipped[] = "$name (no such field)";
      continue;
    }
    if (!$definition->isDisplayConfigurable($context)) {
      $skipped[] = "$name (not $context-configurable)";
      continue;
    }
    $options = $context === 'view'
      ? ['label' => 'inline'] + $formatter($definition)
      : $widget($definition, $name);
    $display->setComponent($name, array_replace_recursive($options, $overrides, ['weight' => $weight++, 'region' => 'content']));
  }
  $display->setStatus(TRUE)->save();
  printf("  %-6s %-58s %2d components%s\n", $context, $id, $weight, $skipped ? '; skipped ' . implode(', ', $skipped) : '');
};

$above = ['label' => 'above'];
$hidden = ['label' => 'hidden'];

echo "display modes\n";
$ensureMode(EntityViewMode::class, 'civicrm_contact', 'ce_summary', 'Smoke summary');
$ensureMode(EntityViewMode::class, 'civicrm_event', 'ce_summary', 'Smoke summary');
$ensureMode(EntityFormMode::class, 'civicrm_contact', 'edit', 'Edit');

// Per record type: fields of the compact view mode and of the add form mode.
// Everything else gets every active field.
$records = [
  'ce_education' => ['compact' => ['school', 'degree', 'end_date'], 'add' => ['school', 'degree', 'field_of_study', 'end_date', 'graduated']],
  'ce_skills' => ['compact' => ['skill', 'proficiency'], 'add' => ['skill', 'proficiency', 'certified']],
  'ce_board_terms' => ['compact' => ['member', 'role', 'term_end'], 'add' => ['member', 'role', 'term_start', 'term_end']],
  'ce_vehicles' => ['compact' => ['make', 'model_year', 'plate', 'color'], 'add' => ['make', 'model_year', 'plate']],
  'ce_event_sessions' => ['compact' => ['session_title', 'starts_at', 'room'], 'add' => ['session_title', 'starts_at', 'room', 'speaker']],
  'ce_professional_memberships_history' => ['compact' => ['association', 'level'], 'add' => ['association', 'member_since', 'level']],
];

echo "\nrecord type displays\n";
foreach ($records as $group => $modes) {
  $type = $recordType($group);
  $ensureMode(EntityViewMode::class, $type, 'ce_compact', 'Compact');
  $ensureMode(EntityFormMode::class, $type, 'add', 'Add');
  $saveDisplay('view', $type, 'default', array_merge(['entity_id' => ['label' => 'inline']], $cfs($group)));
  $saveDisplay('view', $type, 'ce_compact', array_merge(['entity_id' => $hidden], array_fill_keys($cfs($group, $modes['compact']), $hidden)));
  $saveDisplay('form', $type, 'default', array_merge(['entity_id'], $cfs($group)));
  $saveDisplay('form', $type, 'add', array_merge(['entity_id'], $cfs($group, $modes['add'])));
}

echo "\nparent displays\n";
// Multi-record fields on the parent are computed lists of every record's
// value: shown with their label above, as a list.
$multi = static fn(string $group, array $names) => array_fill_keys($cfs($group, $names), $above);

$saveDisplay('view', 'civicrm_contact', 'default', array_merge(
  ['display_name' => $hidden, 'contact_type', 'contact_sub_type', 'birth_date', 'gender_id'],
  $cfs('constituent_information'),
  $cfs('ce_profile_all'),
  $multi('ce_education', ['school', 'degree', 'end_date', 'graduated', 'honors', 'transcript']),
  $multi('ce_skills', ['skill', 'proficiency', 'certified', 'languages', 'endorsed_by']),
  $multi('ce_board_terms', ['member', 'role', 'term_end', 'stipend']),
  $multi('ce_vehicles', ['make', 'plate', 'color']),
  $multi('ce_professional_memberships_history', ['association', 'level', 'currently_active']),
));
$saveDisplay('view', 'civicrm_contact', 'ce_summary', array_merge(
  ['display_name' => $hidden, 'contact_type'],
  $cfs('ce_profile_all', ['select', 'radio', 'country', 'contact_ref']),
  $multi('ce_education', ['school']),
  $multi('ce_skills', ['skill']),
));
$saveDisplay('form', 'civicrm_contact', 'edit', array_merge(
  ['first_name', 'last_name', 'organization_name', 'household_name'],
  $cfs('constituent_information'),
  $cfs('ce_profile_all'),
));

$saveDisplay('view', 'civicrm_event', 'default', array_merge(
  ['title' => $hidden, 'event_type_id', 'start_date', 'end_date', 'summary'],
  $cfs('ce_event_logistics'),
  $multi('ce_event_sessions', ['session_title', 'starts_at', 'room', 'speaker', 'fee']),
));
$saveDisplay('view', 'civicrm_event', 'ce_summary', array_merge(
  ['title' => $hidden, 'start_date'],
  $cfs('ce_event_logistics', ['dress_code', 'minimum_age']),
  $multi('ce_event_sessions', ['session_title']),
));
$saveDisplay('view', 'civicrm_activity', 'default', array_merge(
  ['subject' => $hidden, 'activity_type_id', 'activity_date_time', 'status_id', 'details'],
  $cfs('ce_meeting_details'),
));

// ---- Views --------------------------------------------------------------------

/**
 * One Views handler, keyed by its ID, with its plugin from the Views data.
 */
$h = static function (string $type, string $table, string $field, array $options = [], ?string $id = NULL) use ($viewsData): array {
  $data = $viewsData->get($table);
  $handlerType = in_array($type, ['header', 'footer', 'empty'], TRUE) ? 'area' : $type;
  if (!isset($data[$field][$handlerType]['id'])) {
    throw new \RuntimeException("No $handlerType handler for $table.$field");
  }
  $id ??= $field;
  $item = ['id' => $id, 'table' => $table, 'field' => $field] + $options;
  if (isset($data['table']['entity type'])) {
    $item['entity_type'] = $data['table']['entity type'];
  }
  if (isset($data[$field]['entity field'])) {
    $item['entity_field'] = $data[$field]['entity field'];
  }
  $item['plugin_id'] = $data[$field][$handlerType]['id'];
  return [$id => $item];
};

/**
 * The Views data key of an entity field on a table.
 */
$key = static function (string $table, string $entityField) use ($viewsData): string {
  foreach ($viewsData->get($table) as $name => $info) {
    if (is_array($info) && ($info['entity field'] ?? NULL) === $entityField && isset($info['field'])) {
      return $name;
    }
  }
  throw new \RuntimeException("No Views field for $table.$entityField");
};

/**
 * A field handler for an entity field, with a formatter chosen for it.
 */
$field = static function (string $table, string $entityTypeId, string $entityField, array $options = [], ?string $id = NULL) use ($h, $key, $efm, $formatter): array {
  $definition = $efm->getFieldStorageDefinitions($entityTypeId)[$entityField] ?? NULL;
  $baseDefinition = $efm->getBaseFieldDefinitions($entityTypeId)[$entityField] ?? NULL;
  $defaults = ['exclude' => FALSE, 'element_label_colon' => FALSE];
  if ($baseDefinition) {
    $defaults += $formatter($baseDefinition) + ['label' => (string) $baseDefinition->getLabel()];
  }
  if ($definition) {
    $mainProperty = $definition->getMainPropertyName() ?? 'value';
    $defaults += ['click_sort_column' => $mainProperty, 'group_column' => $mainProperty, 'group_columns' => []];
    if ($definition->getCardinality() !== 1) {
      $defaults += ['group_rows' => TRUE, 'delta_limit' => 0, 'delta_offset' => 0, 'multi_type' => 'separator', 'separator' => ', '];
    }
  }
  return $h('field', $table, $key($table, $entityField), $options + $defaults, $id);
};

$expose = static function (string $identifier, string $label, array $overrides = []): array {
  return [
    'exposed' => TRUE,
    'expose' => $overrides + [
      'operator_id' => $identifier . '_op',
      'label' => $label,
      'description' => '',
      'use_operator' => FALSE,
      'operator' => $identifier . '_op',
      'operator_limit_selection' => FALSE,
      'operator_list' => [],
      'identifier' => $identifier,
      'required' => FALSE,
      'remember' => FALSE,
      'multiple' => FALSE,
      'remember_roles' => ['authenticated' => 'authenticated'],
      'reduce' => FALSE,
    ],
  ];
};

/**
 * An exposed "is one of" filter, whichever list plugin the column has.
 */
$inFilter = static function (string $table, string $field, string $identifier, string $label, array $options = []) use ($h, $expose, $viewsData): array {
  $plugin = $viewsData->get($table)[$field]['filter']['id'] ?? '';
  return $h('filter', $table, $field, $options + [
    'operator' => $plugin === 'list_field' ? 'or' : 'in',
    'value' => [],
  ] + $expose($identifier, $label, ['multiple' => TRUE]), $identifier);
};

$table = static function (array $fields, string $default = '', array $grouping = []): array {
  $info = [];
  foreach (array_keys($fields) as $id) {
    $info[$id] = ['sortable' => TRUE, 'default_sort_order' => 'asc', 'align' => '', 'separator' => '', 'empty_column' => FALSE, 'responsive' => ''];
  }
  return [
    'type' => 'table',
    'options' => [
      'grouping' => $grouping,
      'row_class' => '',
      'default_row_class' => TRUE,
      'columns' => array_combine(array_keys($fields), array_keys($fields)),
      'default' => $default ?: '-1',
      'info' => $info,
      'override' => TRUE,
      'sticky' => FALSE,
      'summary' => '',
      'empty_table' => TRUE,
      'caption' => '',
      'description' => '',
      'order' => 'asc',
    ],
  ];
};

$entityIdArgument = static fn(string $recordTable) => $h('argument', $recordTable, 'entity_id', [
  'default_action' => 'default',
  'exception' => ['value' => 'all', 'title_enable' => FALSE, 'title' => 'All'],
  'title_enable' => FALSE,
  'title' => '',
  'default_argument_type' => 'raw',
  'default_argument_options' => ['index' => 1, 'use_alias' => FALSE],
  'summary_options' => ['base_path' => '', 'count' => TRUE, 'override' => FALSE, 'items_per_page' => 25],
  'summary' => ['sort_order' => 'asc', 'number_of_records' => 0, 'format' => 'default_summary'],
  'specify_validation' => TRUE,
  'validate' => ['type' => 'numeric', 'fail' => 'empty'],
  'validate_options' => [],
  'break_phrase' => FALSE,
  'not' => FALSE,
]);

$common = static fn(string $title, int $perPage = 25) => [
  'title' => $title,
  'access' => ['type' => 'perm', 'options' => ['perm' => 'access CiviCRM']],
  'cache' => ['type' => 'tag', 'options' => []],
  'exposed_form' => ['type' => 'basic', 'options' => ['submit_button' => 'Apply', 'reset_button' => TRUE, 'reset_button_label' => 'Reset', 'exposed_sorts_label' => 'Sort by', 'expose_sort_order' => TRUE, 'sort_asc_label' => 'Asc', 'sort_desc_label' => 'Desc']],
  'pager' => ['type' => 'full', 'options' => ['items_per_page' => $perPage, 'offset' => 0]],
  'header' => $h('header', 'views', 'result', ['empty' => FALSE, 'content' => 'Displaying @start - @end of @total']),
  'empty' => $h('empty', 'views', 'area_text_custom', ['empty' => TRUE, 'content' => 'No records match.', 'tokenize' => FALSE]),
  'filter_groups' => ['operator' => 'AND', 'groups' => [1 => 'AND']],
];

$page = static fn(string $path, int $position = 1, array $options = []) => [
  'id' => 'page_' . $position,
  'display_title' => 'Page',
  'display_plugin' => 'page',
  'position' => $position,
  'display_options' => $options + ['path' => $path, 'display_extenders' => []],
];

$owned = [];
$saveView = static function (string $id, string $label, string $baseTable, array $default, array $displays) use (&$owned): void {
  View::load($id)?->delete();
  $all = ['default' => ['id' => 'default', 'display_title' => 'Default', 'display_plugin' => 'default', 'position' => 0, 'display_options' => $default]];
  foreach ($displays as $display) {
    $all[$display['id']] = $display;
  }
  $view = View::create([
    'id' => $id,
    'label' => $label,
    'module' => 'views',
    'description' => 'CiviCRM Entity 5.0.x multi-record custom field smoke test.',
    'tag' => 'ce_smoke',
    'base_table' => $baseTable,
    'base_field' => 'id',
    'display' => $all,
  ]);
  $errors = $view->getExecutable()->validate();
  $view->save();
  $owned[] = $id;
  printf("  %-36s base %-34s displays %s%s\n", $id, $baseTable, implode(',', array_keys($all)),
    $errors ? "\n    VALIDATION: " . json_encode($errors) : '');
};

echo "\nviews\n";

// 1. Education records: the record type as base table, its parent through
// entity_id, exposed filters, click-sorted table, operations links; and a
// block of the current contact's records, placed on contact pages.
$t = $recordType('ce_education');
$f = $field($t, $t, 'id', ['label' => 'ID'])
  + $h('field', 'civicrm_contact', 'display_name', ['relationship' => 'contact', 'label' => 'Contact', 'type' => 'string', 'settings' => ['link_to_entity' => TRUE], 'entity_type' => 'civicrm_contact', 'entity_field' => 'display_name'])
  + $field($t, $t, $cf('ce_education', 'school'), [], 'school')
  + $field($t, $t, $cf('ce_education', 'degree'), [], 'degree')
  + $field($t, $t, $cf('ce_education', 'field_of_study'), [], 'field_of_study')
  + $field($t, $t, $cf('ce_education', 'start_date'), [], 'start_date')
  + $field($t, $t, $cf('ce_education', 'end_date'), [], 'end_date')
  + $field($t, $t, $cf('ce_education', 'gpa'), [], 'gpa')
  + $field($t, $t, $cf('ce_education', 'graduated'), [], 'graduated')
  + $h('field', $t, 'operations', ['label' => 'Operations']);
$blockFields = array_intersect_key($f, array_flip(['school', 'degree', 'field_of_study', 'end_date', 'graduated', 'operations']));
$saveView('ce_smoke_education', 'Smoke: Education records', $t, $common('Education records') + [
  'relationships' => $h('relationship', $t, 'entity_id', ['required' => TRUE, 'admin_label' => 'Contact'], 'contact'),
  'fields' => $f,
  'filters' => $h('filter', $t, $key($t, $cf('ce_education', 'school')), ['operator' => 'contains', 'value' => ''] + $expose('school', 'School contains'), 'school')
    + $inFilter($t, $key($t, $cf('ce_education', 'degree')), 'degree', 'Degree')
    + $h('filter', $t, $key($t, $cf('ce_education', 'graduated')), ['operator' => '=', 'value' => 'All'] + $expose('graduated', 'Graduated'), 'graduated')
    + $h('filter', 'civicrm_contact', 'display_name', ['relationship' => 'contact', 'operator' => 'contains', 'value' => ''] + $expose('contact', 'Contact name contains'), 'contact_name'),
  'sorts' => $h('sort', $t, $key($t, $cf('ce_education', 'end_date')), ['order' => 'DESC', 'granularity' => 'second', 'exposed' => FALSE], 'end_date'),
  'style' => $table($f, 'end_date'),
  'row' => ['type' => 'fields', 'options' => []],
], [
  $page('smoke/education'),
  [
    'id' => 'block_contact',
    'display_title' => 'Contact block',
    'display_plugin' => 'block',
    'position' => 2,
    'display_options' => [
      'defaults' => ['title' => FALSE, 'fields' => FALSE, 'arguments' => FALSE, 'filters' => FALSE, 'filter_groups' => FALSE, 'relationships' => FALSE, 'pager' => FALSE, 'header' => FALSE, 'style' => FALSE, 'row' => FALSE],
      'title' => 'Education history',
      'relationships' => [],
      'fields' => $blockFields,
      'arguments' => $entityIdArgument($t),
      'filters' => [],
      'filter_groups' => ['operator' => 'AND', 'groups' => []],
      'pager' => ['type' => 'some', 'options' => ['items_per_page' => 10, 'offset' => 0]],
      'header' => [],
      'style' => $table($blockFields),
      'row' => ['type' => 'fields', 'options' => []],
      'block_description' => 'Smoke: education records of the current contact',
      'block_hide_empty' => TRUE,
      'display_extenders' => [],
    ],
  ],
]);

// 2. Skills by contact: Contact as base table, records through the reverse
// relationship, a second contact through the records' ContactReference.
$t = $recordType('ce_skills');
$f = $h('field', 'civicrm_contact', 'display_name', ['label' => 'Contact', 'type' => 'string', 'settings' => ['link_to_entity' => TRUE]])
  + $field('civicrm_contact', 'civicrm_contact', 'contact_type', ['label' => 'Type'])
  + $field($t, $t, $cf('ce_skills', 'skill'), ['relationship' => 'skills'], 'skill')
  + $field($t, $t, $cf('ce_skills', 'proficiency'), ['relationship' => 'skills'], 'proficiency')
  + $field($t, $t, $cf('ce_skills', 'years_experience'), ['relationship' => 'skills'], 'years_experience')
  + $field($t, $t, $cf('ce_skills', 'certified'), ['relationship' => 'skills'], 'certified')
  + $field($t, $t, $cf('ce_skills', 'cert_url'), ['relationship' => 'skills'], 'cert_url')
  + $field($t, $t, $cf('ce_skills', 'last_used'), ['relationship' => 'skills'], 'last_used')
  + $h('field', 'civicrm_contact', 'display_name', ['relationship' => 'endorser', 'label' => 'Endorsed by', 'type' => 'string', 'settings' => ['link_to_entity' => TRUE]], 'endorser');
$saveView('ce_smoke_skills', 'Smoke: Skills by contact', 'civicrm_contact', $common('Skills by contact') + [
  'relationships' => $h('relationship', 'civicrm_contact', "reverse__{$t}__entity_id", ['required' => TRUE, 'admin_label' => 'Skills'], 'skills')
    + $h('relationship', $t, $key($t, $cf('ce_skills', 'endorsed_by')), ['relationship' => 'skills', 'required' => FALSE, 'admin_label' => 'Endorser'], 'endorser'),
  'fields' => $f,
  'filters' => $inFilter($t, $key($t, $cf('ce_skills', 'proficiency')), 'proficiency', 'Proficiency', ['relationship' => 'skills'])
    + $inFilter('civicrm_contact', 'contact_type', 'contact_type', 'Contact type')
    + $h('filter', $t, $key($t, $cf('ce_skills', 'certified')), ['relationship' => 'skills', 'operator' => '=', 'value' => 'All'] + $expose('certified', 'Certified'), 'certified'),
  'sorts' => $h('sort', 'civicrm_contact', 'sort_name', ['order' => 'ASC'], 'sort_name')
    + $h('sort', $t, $key($t, $cf('ce_skills', 'proficiency')), ['relationship' => 'skills', 'order' => 'DESC'], 'proficiency'),
  'style' => $table($f),
  'row' => ['type' => 'fields', 'options' => []],
], [$page('smoke/skills')]);

// 3. Skills summary: aggregation over the record type.
$saveView('ce_smoke_skills_summary', 'Smoke: Skills summary (aggregated)', $t, $common('Skills per contact') + [
  'group_by' => TRUE,
  'relationships' => $h('relationship', $t, 'entity_id', ['required' => TRUE, 'admin_label' => 'Contact'], 'contact'),
  // Grouped by contact ID as well as name: the sample data has two contacts
  // named "Omar Terrell Jr.".
  'fields' => $h('field', 'civicrm_contact', 'id', ['relationship' => 'contact', 'label' => 'Contact ID', 'exclude' => TRUE, 'type' => 'number_integer', 'settings' => ['thousand_separator' => '', 'prefix_suffix' => FALSE], 'group_type' => 'group', 'group_column' => 'value'], 'contact_id')
    + $h('field', 'civicrm_contact', 'display_name', ['relationship' => 'contact', 'label' => 'Contact', 'type' => 'string', 'settings' => ['link_to_entity' => FALSE], 'group_type' => 'group', 'group_column' => 'value'])
    + $h('field', $t, 'id', ['label' => 'Skills', 'group_type' => 'count', 'type' => 'number_integer', 'settings' => ['thousand_separator' => '', 'prefix_suffix' => TRUE]], 'skill_count')
    + $h('field', $t, $key($t, $cf('ce_skills', 'years_experience')), ['label' => 'Total years of experience', 'group_type' => 'sum', 'type' => 'number_integer', 'settings' => ['thousand_separator' => ',', 'prefix_suffix' => TRUE]], 'years_total'),
  'sorts' => $h('sort', 'civicrm_contact', 'sort_name', ['relationship' => 'contact', 'order' => 'ASC', 'group_type' => 'group'], 'sort_name'),
  'style' => ['type' => 'table', 'options' => ['columns' => ['display_name' => 'display_name', 'skill_count' => 'skill_count', 'years_total' => 'years_total'], 'default' => '-1', 'info' => []]],
  'row' => ['type' => 'fields', 'options' => []],
], [$page('smoke/skills/summary')]);

// 4. Board terms: an Organization's records, the parent and a second contact
// through the ContactReference, grouped by organization.
$t = $recordType('ce_board_terms');
$f = $h('field', 'civicrm_contact', 'display_name', ['relationship' => 'organization', 'label' => 'Organization', 'type' => 'string', 'settings' => ['link_to_entity' => TRUE], 'exclude' => TRUE], 'organization')
  + $h('field', 'civicrm_contact', 'display_name', ['relationship' => 'member', 'label' => 'Board member', 'type' => 'string', 'settings' => ['link_to_entity' => TRUE]], 'member')
  + $field($t, $t, $cf('ce_board_terms', 'role'), [], 'role')
  + $field($t, $t, $cf('ce_board_terms', 'term_start'), [], 'term_start')
  + $field($t, $t, $cf('ce_board_terms', 'term_end'), [], 'term_end')
  + $field($t, $t, $cf('ce_board_terms', 'stipend'), [], 'stipend')
  + $field($t, $t, $cf('ce_board_terms', 'country'), [], 'country')
  + $field($t, $t, $cf('ce_board_terms', 'state'), [], 'state')
  + $field($t, $t, $cf('ce_board_terms', 'bio'), [], 'bio');
$saveView('ce_smoke_board_terms', 'Smoke: Board terms', $t, $common('Board terms', 100) + [
  'relationships' => $h('relationship', $t, 'entity_id', ['required' => TRUE, 'admin_label' => 'Organization'], 'organization')
    + $h('relationship', $t, $key($t, $cf('ce_board_terms', 'member')), ['required' => FALSE, 'admin_label' => 'Board member'], 'member'),
  'fields' => $f,
  'filters' => $inFilter($t, $key($t, $cf('ce_board_terms', 'role')), 'role', 'Role'),
  'sorts' => $h('sort', 'civicrm_contact', 'sort_name', ['relationship' => 'organization', 'order' => 'ASC'], 'organization_sort')
    + $h('sort', $t, $key($t, $cf('ce_board_terms', 'term_start')), ['order' => 'DESC', 'granularity' => 'day'], 'term_start'),
  'style' => $table($f, '', [['field' => 'organization', 'rendered' => TRUE, 'rendered_strip' => FALSE]]),
  'row' => ['type' => 'fields', 'options' => []],
], [$page('smoke/board-terms')]);

// 5. Contact directory: the 4.0.x shape -- the parent's custom value tables
// joined to Contact, single-record and multi-record alike -- and a second
// page rendering contacts in the ce_summary view mode.
$profile = $valueTable('ce_profile_all');
$profileField = static fn(string $name, array $options = []) => $h('field', $profile, $column('ce_profile_all', $name), $options + ['group_rows' => TRUE, 'delta_limit' => 0, 'multi_type' => 'separator', 'separator' => ', '], $name);
$f = $h('field', 'civicrm_contact', 'display_name', ['label' => 'Contact', 'type' => 'string', 'settings' => ['link_to_entity' => TRUE]])
  + $field('civicrm_contact', 'civicrm_contact', 'contact_type', ['label' => 'Type'])
  + $profileField('text') + $profileField('select') + $profileField('checkboxes') + $profileField('multiselect')
  + $profileField('integer') + $profileField('money') + $profileField('date') + $profileField('boolean_radio')
  + $profileField('country') + $profileField('countries') + $profileField('contact_ref') + $profileField('file')
  + $h('field', $valueTable('ce_education'), $column('ce_education', 'school'), ['label' => 'Schools (multi-record via value table)', 'group_rows' => TRUE, 'delta_limit' => 0, 'multi_type' => 'separator', 'separator' => '; '], 'schools');
$saveView('ce_smoke_contact_directory', 'Smoke: Contact directory (custom value tables)', 'civicrm_contact', $common('Contact directory') + [
  'query' => ['type' => 'views_query', 'options' => ['distinct' => TRUE, 'query_comment' => '', 'disable_sql_rewrite' => FALSE, 'replica' => FALSE, 'query_tags' => []]],
  'fields' => $f,
  // Until punch list J4 (2026-10-16) this was `longerthan 0`: the value
  // tables' filters lacked `allow empty`, so StringFilter dropped `not empty`
  // and the page listed every contact. verify-smoke counts its rows.
  'filters' => $h('filter', $profile, $column('ce_profile_all', 'text'), ['operator' => 'not empty', 'value' => ''], 'has_profile')
    + $inFilter($profile, $column('ce_profile_all', 'select'), 'size', 'Select (size)')
    + $inFilter('civicrm_contact', 'contact_type', 'contact_type', 'Contact type'),
  'sorts' => $h('sort', 'civicrm_contact', 'sort_name', ['order' => 'ASC'], 'sort_name'),
  'style' => $table($f),
  'row' => ['type' => 'fields', 'options' => []],
], [
  $page('smoke/directory'),
  $page('smoke/directory/cards', 2, [
    'defaults' => ['style' => FALSE, 'row' => FALSE, 'pager' => FALSE, 'title' => FALSE],
    'title' => 'Contact cards (ce_summary view mode)',
    'style' => ['type' => 'grid', 'options' => ['grouping' => [], 'columns' => 3, 'automatic_width' => TRUE, 'alignment' => 'horizontal', 'row_class_custom' => '', 'row_class_default' => TRUE, 'col_class_custom' => '', 'col_class_default' => TRUE]],
    'row' => ['type' => 'entity:civicrm_contact', 'options' => ['relationship' => 'none', 'view_mode' => 'ce_summary']],
    'pager' => ['type' => 'mini', 'options' => ['items_per_page' => 12, 'offset' => 0]],
  ]),
]);

// 6. Event sessions: a non-contact parent, with ContactReference and
// EntityReference relationships; and a block on event pages.
$t = $recordType('ce_event_sessions');
$f = $h('field', 'civicrm_event', 'title', ['relationship' => 'event', 'label' => 'Event', 'type' => 'string', 'settings' => ['link_to_entity' => TRUE], 'exclude' => TRUE], 'event')
  + $field($t, $t, $cf('ce_event_sessions', 'session_title'), [], 'session_title')
  + $field($t, $t, $cf('ce_event_sessions', 'starts_at'), [], 'starts_at')
  + $field($t, $t, $cf('ce_event_sessions', 'duration_minutes'), [], 'duration')
  + $field($t, $t, $cf('ce_event_sessions', 'room'), [], 'room')
  + $field($t, $t, $cf('ce_event_sessions', 'capacity'), [], 'capacity')
  + $field($t, $t, $cf('ce_event_sessions', 'fee'), [], 'fee')
  + $h('field', 'civicrm_contact', 'display_name', ['relationship' => 'speaker', 'label' => 'Speaker', 'type' => 'string', 'settings' => ['link_to_entity' => TRUE]], 'speaker')
  + $h('field', 'civicrm_activity', 'subject', ['relationship' => 'activity', 'label' => 'Related activity', 'type' => 'string', 'settings' => ['link_to_entity' => TRUE]], 'activity');
$sessionsBlockFields = array_diff_key($f, ['event' => TRUE]);
$saveView('ce_smoke_event_sessions', 'Smoke: Event sessions', $t, $common('Event sessions', 50) + [
  'relationships' => $h('relationship', $t, 'entity_id', ['required' => TRUE, 'admin_label' => 'Event'], 'event')
    + $h('relationship', $t, $key($t, $cf('ce_event_sessions', 'speaker')), ['required' => FALSE, 'admin_label' => 'Speaker'], 'speaker')
    + $h('relationship', $t, $key($t, $cf('ce_event_sessions', 'related_activity')), ['required' => FALSE, 'admin_label' => 'Related activity'], 'activity'),
  'fields' => $f,
  'filters' => $inFilter($t, $key($t, $cf('ce_event_sessions', 'room')), 'room', 'Room'),
  'sorts' => $h('sort', $t, $key($t, $cf('ce_event_sessions', 'starts_at')), ['order' => 'ASC', 'granularity' => 'minute'], 'starts_at'),
  'style' => $table($f, '', [['field' => 'event', 'rendered' => TRUE, 'rendered_strip' => FALSE]]),
  'row' => ['type' => 'fields', 'options' => []],
], [
  $page('smoke/event-sessions'),
  [
    'id' => 'block_event',
    'display_title' => 'Event block',
    'display_plugin' => 'block',
    'position' => 2,
    'display_options' => [
      'defaults' => ['title' => FALSE, 'fields' => FALSE, 'arguments' => FALSE, 'filters' => FALSE, 'filter_groups' => FALSE, 'pager' => FALSE, 'header' => FALSE, 'style' => FALSE, 'row' => FALSE],
      'title' => 'Sessions',
      'fields' => $sessionsBlockFields,
      'arguments' => $entityIdArgument($t),
      'filters' => [],
      'filter_groups' => ['operator' => 'AND', 'groups' => []],
      'pager' => ['type' => 'none', 'options' => ['offset' => 0]],
      'header' => [],
      'style' => $table($sessionsBlockFields),
      'row' => ['type' => 'fields', 'options' => []],
      'block_description' => 'Smoke: sessions of the current event',
      'block_hide_empty' => TRUE,
      'display_extenders' => [],
    ],
  ],
]);

// 7. Staff vehicles: records rendered in their ce_compact view mode.
$t = $recordType('ce_vehicles');
$saveView('ce_smoke_vehicles', 'Smoke: Staff vehicles (rendered entities)', $t, $common('Staff vehicles') + [
  'fields' => [],
  'sorts' => $h('sort', $t, 'entity_id', ['order' => 'ASC'], 'entity_id'),
  'style' => ['type' => 'default', 'options' => ['grouping' => [], 'row_class' => '', 'default_row_class' => TRUE]],
  'row' => ['type' => "entity:$t", 'options' => ['relationship' => 'none', 'view_mode' => 'ce_compact']],
], [$page('smoke/vehicles')]);

// 8. Professional memberships: the record type whose ID is hashed.
$t = $recordType('ce_professional_memberships_history');
$f = $h('field', 'civicrm_contact', 'display_name', ['relationship' => 'contact', 'label' => 'Contact', 'type' => 'string', 'settings' => ['link_to_entity' => TRUE]])
  + $field($t, $t, $cf('ce_professional_memberships_history', 'association'), [], 'association')
  + $field($t, $t, $cf('ce_professional_memberships_history', 'member_since'), [], 'member_since')
  + $field($t, $t, $cf('ce_professional_memberships_history', 'annual_dues'), [], 'annual_dues')
  + $field($t, $t, $cf('ce_professional_memberships_history', 'currently_active'), [], 'currently_active')
  + $field($t, $t, $cf('ce_professional_memberships_history', 'level'), [], 'level');
$saveView('ce_smoke_memberships_history', 'Smoke: Professional memberships (hashed type ID)', $t, $common('Professional memberships history') + [
  'relationships' => $h('relationship', $t, 'entity_id', ['required' => TRUE, 'admin_label' => 'Contact'], 'contact'),
  'fields' => $f,
  'filters' => $h('filter', $t, $key($t, $cf('ce_professional_memberships_history', 'currently_active')), ['operator' => '=', 'value' => 'All'] + $expose('active', 'Currently active'), 'active')
    + $inFilter($t, $key($t, $cf('ce_professional_memberships_history', 'level')), 'level', 'Level'),
  'sorts' => $h('sort', $t, $key($t, $cf('ce_professional_memberships_history', 'member_since')), ['order' => 'ASC', 'granularity' => 'day'], 'member_since'),
  'style' => $table($f),
  'row' => ['type' => 'fields', 'options' => []],
], [$page('smoke/memberships-history')]);

// ---- Blocks and menu ----------------------------------------------------------

echo "\nblocks\n";
$theme = \Drupal::config('system.theme')->get('default');
foreach ([
  'ce_smoke_education_contact' => ['views_block:ce_smoke_education-block_contact', '/civicrm-contact/*'],
  'ce_smoke_event_sessions_event' => ['views_block:ce_smoke_event_sessions-block_event', '/civicrm-event/*'],
] as $id => [$plugin, $pages]) {
  Block::load($id)?->delete();
  Block::create([
    'id' => $id,
    'theme' => $theme,
    'region' => 'content',
    'weight' => 20,
    'plugin' => $plugin,
    'settings' => ['id' => $plugin, 'label' => '', 'label_display' => 'visible', 'provider' => 'views', 'views_label' => '', 'items_per_page' => 'none'],
    'visibility' => ['request_path' => ['id' => 'request_path', 'negate' => FALSE, 'pages' => $pages]],
  ])->save();
  printf("  %-36s %s on %s\n", $id, $plugin, $pages);
}

echo "\nmenu\n";
$storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');
$storage->delete($storage->loadByProperties(['menu_name' => 'main', 'description' => 'ce_smoke']));
$parent = MenuLinkContent::create(['title' => 'CE smoke', 'link' => ['uri' => 'internal:/smoke/education'], 'menu_name' => 'main', 'expanded' => TRUE, 'weight' => 50, 'description' => 'ce_smoke']);
$parent->save();
$weight = 0;
foreach ([
  'Education records' => '/smoke/education',
  'Skills by contact' => '/smoke/skills',
  'Skills summary' => '/smoke/skills/summary',
  'Board terms' => '/smoke/board-terms',
  'Contact directory' => '/smoke/directory',
  'Contact cards' => '/smoke/directory/cards',
  'Event sessions' => '/smoke/event-sessions',
  'Staff vehicles' => '/smoke/vehicles',
  'Professional memberships' => '/smoke/memberships-history',
  'CiviCRM Entity settings' => '/admin/structure/civicrm-entity/settings',
] as $title => $path) {
  MenuLinkContent::create(['title' => $title, 'link' => ['uri' => "internal:$path"], 'menu_name' => 'main', 'parent' => 'menu_link_content:' . $parent->uuid(), 'weight' => $weight++, 'description' => 'ce_smoke'])->save();
}
printf("  %d links under \"CE smoke\"\n", $weight);

echo "\ndone\n";
