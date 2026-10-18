<?php

/**
 * @file
 * Seeds CiviCRM custom groups and values for smoke testing CiviCRM Entity.
 *
 * Run after loading CiviCRM's generated sample data:
 *   ddev drush --uri=https://drupal-civi-composer-exp-d11.ddev.site \
 *     php:script scripts/smoke/civi-seed-custom-data.php
 *
 * Idempotent: a group or field that already exists (by name) is left alone,
 * and values are written only when the group has none yet. Values come from a
 * fixed seed so a rebuild produces the same data.
 *
 * Coverage:
 * - Multi-record groups extending Individual, Contact, Organization, an
 *   Individual subtype (Staff, max_multiple 2) and Event, plus one whose name
 *   is long enough that its entity type ID is hashed.
 * - Edge cases that must NOT produce an entity type: an inactive multi-record
 *   group, an active one with no fields, and an inactive field in an active
 *   group.
 * - Single-record groups on Contact (every data type / html type pairing
 *   CiviCRM allows), on Activity restricted to the Meeting type, and on Event.
 */

use Civi\Api4\Activity;
use Civi\Api4\Contact;
use Civi\Api4\CustomField;
use Civi\Api4\CustomGroup;
use Civi\Api4\Event;
use Civi\Api4\File;
use Civi\Api4\OptionValue;

\Drupal::service('civicrm')->initialize();
mt_srand(20261015);

$meetingType = (string) OptionValue::get(FALSE)
  ->addSelect('value')
  ->addWhere('option_group_id:name', '=', 'activity_type')
  ->addWhere('name', '=', 'Meeting')
  ->execute()
  ->first()['value'];

$date = ['data_type' => 'Date', 'html_type' => 'Select Date', 'date_format' => 'yy-mm-dd'];
$datetime = ['data_type' => 'Date', 'html_type' => 'Select Date', 'date_format' => 'yy-mm-dd', 'time_format' => 2];
$text = ['data_type' => 'String', 'html_type' => 'Text'];

$definitions = [
  // ---- Multi-record groups ------------------------------------------------
  'ce_education' => [
    'group' => [
      'title' => 'Education History',
      'extends' => 'Individual',
      'is_multiple' => TRUE,
      'style' => 'Tab with table',
    ],
    'fields' => [
      'school' => ['label' => 'School', 'is_required' => TRUE, 'is_searchable' => TRUE] + $text,
      'degree' => [
        'label' => 'Degree',
        'data_type' => 'String',
        'html_type' => 'Select',
        'is_searchable' => TRUE,
        'option_values' => ['AA' => 'Associate', 'BA' => 'Bachelor of Arts', 'BS' => 'Bachelor of Science', 'MA' => 'Master of Arts', 'MS' => 'Master of Science', 'PhD' => 'Doctorate', 'CERT' => 'Certificate'],
      ],
      'field_of_study' => ['label' => 'Field of Study'] + $text,
      'start_date' => ['label' => 'Start Date'] + $date,
      'end_date' => ['label' => 'End Date'] + $date,
      'gpa' => ['label' => 'GPA', 'data_type' => 'Float', 'html_type' => 'Text'],
      'graduated' => ['label' => 'Graduated', 'data_type' => 'Boolean', 'html_type' => 'Radio'],
      'honors' => [
        'label' => 'Honors',
        'data_type' => 'String',
        'html_type' => 'CheckBox',
        'option_values' => ['cum_laude' => 'Cum Laude', 'magna' => 'Magna Cum Laude', 'summa' => 'Summa Cum Laude', 'deans_list' => "Dean's List"],
      ],
      'notes' => ['label' => 'Notes', 'data_type' => 'Memo', 'html_type' => 'TextArea'],
      'transcript' => ['label' => 'Transcript', 'data_type' => 'File', 'html_type' => 'File'],
      // Inactive: must not become a field of the record type.
      'legacy_code' => ['label' => 'Legacy Code (inactive)', 'is_active' => FALSE] + $text,
    ],
  ],
  'ce_skills' => [
    'group' => [
      'title' => 'Skills',
      'extends' => 'Contact',
      'is_multiple' => TRUE,
      'max_multiple' => 5,
      'style' => 'Tab',
    ],
    'fields' => [
      'skill' => ['label' => 'Skill', 'is_required' => TRUE, 'is_searchable' => TRUE] + $text,
      'proficiency' => [
        'label' => 'Proficiency',
        'data_type' => 'Int',
        'html_type' => 'Radio',
        'option_values' => [1 => 'Novice', 2 => 'Beginner', 3 => 'Competent', 4 => 'Proficient', 5 => 'Expert'],
      ],
      'years_experience' => ['label' => 'Years of Experience', 'data_type' => 'Int', 'html_type' => 'Text'],
      'certified' => ['label' => 'Certified', 'data_type' => 'Boolean', 'html_type' => 'Toggle'],
      'cert_url' => ['label' => 'Certificate URL', 'data_type' => 'Link', 'html_type' => 'Link'],
      'languages' => [
        'label' => 'Languages Used',
        'data_type' => 'String',
        'html_type' => 'Select',
        'serialize' => 1,
        'option_values' => ['en' => 'English', 'es' => 'Spanish', 'fr' => 'French', 'de' => 'German', 'zh' => 'Chinese', 'ar' => 'Arabic'],
      ],
      'last_used' => ['label' => 'Last Used'] + $datetime,
      'endorsed_by' => ['label' => 'Endorsed By', 'data_type' => 'ContactReference', 'html_type' => 'Autocomplete-Select', 'filter' => 'action=get&contact_type=Individual'],
    ],
  ],
  'ce_board_terms' => [
    'group' => [
      'title' => 'Board Terms',
      'extends' => 'Organization',
      'is_multiple' => TRUE,
      'style' => 'Tab with table',
    ],
    'fields' => [
      'member' => ['label' => 'Board Member', 'data_type' => 'ContactReference', 'html_type' => 'Autocomplete-Select', 'is_required' => TRUE, 'filter' => 'action=get&contact_type=Individual'],
      'role' => [
        'label' => 'Role',
        'data_type' => 'String',
        'html_type' => 'Select',
        'option_values' => ['chair' => 'Chair', 'vice_chair' => 'Vice Chair', 'treasurer' => 'Treasurer', 'secretary' => 'Secretary', 'member' => 'Member at Large'],
      ],
      'term_start' => ['label' => 'Term Start'] + $date,
      'term_end' => ['label' => 'Term End'] + $date,
      'stipend' => ['label' => 'Annual Stipend', 'data_type' => 'Money', 'html_type' => 'Text'],
      'country' => ['label' => 'Seat Country', 'data_type' => 'Country', 'html_type' => 'Select'],
      'state' => ['label' => 'Seat State/Province', 'data_type' => 'StateProvince', 'html_type' => 'Select'],
      'bio' => ['label' => 'Board Bio', 'data_type' => 'Memo', 'html_type' => 'RichTextEditor'],
    ],
  ],
  'ce_vehicles' => [
    'group' => [
      'title' => 'Staff Vehicles',
      'extends' => 'Individual',
      'extends_entity_column_value' => ['Staff'],
      'is_multiple' => TRUE,
      'max_multiple' => 2,
      'style' => 'Tab',
    ],
    'fields' => [
      'make' => ['label' => 'Make', 'is_required' => TRUE] + $text,
      'model_year' => ['label' => 'Model Year', 'data_type' => 'Int', 'html_type' => 'Text'],
      'plate' => ['label' => 'License Plate'] + $text,
      'color' => [
        'label' => 'Color',
        'data_type' => 'String',
        'html_type' => 'Radio',
        'option_values' => ['black' => 'Black', 'white' => 'White', 'silver' => 'Silver', 'red' => 'Red', 'blue' => 'Blue'],
      ],
      'parking_permit' => ['label' => 'Parking Permit', 'data_type' => 'Boolean', 'html_type' => 'Radio'],
      'registered_until' => ['label' => 'Registered Until'] + $date,
    ],
  ],
  'ce_event_sessions' => [
    'group' => [
      'title' => 'Event Sessions',
      'extends' => 'Event',
      'is_multiple' => TRUE,
      'style' => 'Tab with table',
    ],
    'fields' => [
      'session_title' => ['label' => 'Session Title', 'is_required' => TRUE] + $text,
      'starts_at' => ['label' => 'Starts At'] + $datetime,
      'duration_minutes' => ['label' => 'Duration (minutes)', 'data_type' => 'Int', 'html_type' => 'Text'],
      'room' => [
        'label' => 'Room',
        'data_type' => 'String',
        'html_type' => 'Select',
        'option_values' => ['main_hall' => 'Main Hall', 'room_a' => 'Room A', 'room_b' => 'Room B', 'online' => 'Online'],
      ],
      'speaker' => ['label' => 'Speaker', 'data_type' => 'ContactReference', 'html_type' => 'Autocomplete-Select'],
      'capacity' => ['label' => 'Capacity', 'data_type' => 'Int', 'html_type' => 'Text'],
      'fee' => ['label' => 'Session Fee', 'data_type' => 'Money', 'html_type' => 'Text'],
      'materials_url' => ['label' => 'Materials', 'data_type' => 'Link', 'html_type' => 'Link'],
      'description' => ['label' => 'Description', 'data_type' => 'Memo', 'html_type' => 'RichTextEditor'],
      'related_activity' => ['label' => 'Related Activity', 'data_type' => 'EntityReference', 'html_type' => 'Autocomplete-Select', 'fk_entity' => 'Activity'],
    ],
  ],
  // Long enough that the record type ID is cut and hashed.
  'ce_professional_memberships_history' => [
    'group' => [
      'title' => 'Professional Memberships History',
      'extends' => 'Individual',
      'is_multiple' => TRUE,
      'style' => 'Tab',
    ],
    'fields' => [
      'association' => ['label' => 'Association', 'is_required' => TRUE] + $text,
      'member_since' => ['label' => 'Member Since'] + $date,
      'annual_dues' => ['label' => 'Annual Dues', 'data_type' => 'Money', 'html_type' => 'Text'],
      'currently_active' => ['label' => 'Currently Active', 'data_type' => 'Boolean', 'html_type' => 'Toggle'],
      'level' => [
        'label' => 'Level',
        'data_type' => 'Int',
        'html_type' => 'Select',
        'option_values' => [1 => 'Bronze', 2 => 'Silver', 3 => 'Gold'],
      ],
    ],
  ],
  // Inactive group: must not become a record type.
  'ce_archived_notes' => [
    'group' => [
      'title' => 'Archived Notes (inactive group)',
      'extends' => 'Contact',
      'is_multiple' => TRUE,
      'is_active' => FALSE,
      'style' => 'Tab',
    ],
    'fields' => [
      'note' => ['label' => 'Note'] + $text,
    ],
  ],
  // Active but no fields: must not become a record type.
  'ce_empty_multi' => [
    'group' => [
      'title' => 'Empty Multi (no fields)',
      'extends' => 'Contact',
      'is_multiple' => TRUE,
      'style' => 'Tab',
    ],
    'fields' => [],
  ],

  // ---- Single-record groups -----------------------------------------------
  'ce_profile_all' => [
    'group' => [
      'title' => 'Profile (all field types)',
      'extends' => 'Contact',
      'is_multiple' => FALSE,
      'style' => 'Inline',
    ],
    'fields' => [
      'text' => ['label' => 'Text'] + $text,
      'select' => [
        'label' => 'Select',
        'data_type' => 'String',
        'html_type' => 'Select',
        'option_values' => ['small' => 'Small', 'medium' => 'Medium', 'large' => 'Large'],
      ],
      'radio' => [
        'label' => 'Radio',
        'data_type' => 'String',
        'html_type' => 'Radio',
        'option_values' => ['email' => 'Email', 'phone' => 'Phone', 'mail' => 'Postal Mail'],
      ],
      'checkboxes' => [
        'label' => 'Checkboxes',
        'data_type' => 'String',
        'html_type' => 'CheckBox',
        'option_values' => ['news' => 'Newsletter', 'events' => 'Events', 'volunteer' => 'Volunteering', 'advocacy' => 'Advocacy'],
      ],
      'multiselect' => [
        'label' => 'Multi-select',
        'data_type' => 'String',
        'html_type' => 'Select',
        'serialize' => 1,
        'option_values' => ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday'],
      ],
      'autocomplete' => [
        'label' => 'Autocomplete Select',
        'data_type' => 'String',
        'html_type' => 'Autocomplete-Select',
        'option_values' => ['arts' => 'Arts', 'health' => 'Health', 'education' => 'Education', 'environment' => 'Environment', 'housing' => 'Housing'],
      ],
      'integer' => ['label' => 'Integer', 'data_type' => 'Int', 'html_type' => 'Text'],
      'int_radio' => [
        'label' => 'Integer Radio',
        'data_type' => 'Int',
        'html_type' => 'Radio',
        'option_values' => [1 => 'One', 2 => 'Two', 3 => 'Three'],
      ],
      'float' => ['label' => 'Float', 'data_type' => 'Float', 'html_type' => 'Text'],
      'money' => ['label' => 'Money', 'data_type' => 'Money', 'html_type' => 'Text'],
      'memo' => ['label' => 'Memo (textarea)', 'data_type' => 'Memo', 'html_type' => 'TextArea'],
      'rich_text' => ['label' => 'Memo (rich text)', 'data_type' => 'Memo', 'html_type' => 'RichTextEditor'],
      'date' => ['label' => 'Date'] + $date,
      'datetime' => ['label' => 'Date and Time'] + $datetime,
      'boolean_radio' => ['label' => 'Boolean (radio)', 'data_type' => 'Boolean', 'html_type' => 'Radio'],
      'boolean_toggle' => ['label' => 'Boolean (toggle)', 'data_type' => 'Boolean', 'html_type' => 'Toggle'],
      'state' => ['label' => 'State/Province', 'data_type' => 'StateProvince', 'html_type' => 'Select'],
      'country' => ['label' => 'Country', 'data_type' => 'Country', 'html_type' => 'Select'],
      'countries' => ['label' => 'Countries (multi)', 'data_type' => 'Country', 'html_type' => 'Select', 'serialize' => 1],
      'file' => ['label' => 'File', 'data_type' => 'File', 'html_type' => 'File'],
      'link' => ['label' => 'Link', 'data_type' => 'Link', 'html_type' => 'Link'],
      'contact_ref' => ['label' => 'Contact Reference', 'data_type' => 'ContactReference', 'html_type' => 'Autocomplete-Select'],
      'event_ref' => ['label' => 'Event Reference', 'data_type' => 'EntityReference', 'html_type' => 'Autocomplete-Select', 'fk_entity' => 'Event'],
      'hidden' => ['label' => 'Hidden', 'data_type' => 'String', 'html_type' => 'Hidden'],
      // Added 2026-10-16 (punch list J31); last, so no other field's ID moves.
      'states' => ['label' => 'States/Provinces (multi)', 'data_type' => 'StateProvince', 'html_type' => 'Select', 'serialize' => 1],
    ],
  ],
  'ce_meeting_details' => [
    'group' => [
      'title' => 'Meeting Details',
      'extends' => 'Activity',
      'extends_entity_column_value' => [$meetingType],
      'is_multiple' => FALSE,
      'style' => 'Inline',
    ],
    'fields' => [
      'agenda' => ['label' => 'Agenda', 'data_type' => 'Memo', 'html_type' => 'RichTextEditor'],
      'outcome' => [
        'label' => 'Outcome',
        'data_type' => 'String',
        'html_type' => 'Select',
        'option_values' => ['decided' => 'Decision made', 'deferred' => 'Deferred', 'follow_up' => 'Needs follow-up'],
      ],
      'follow_up_on' => ['label' => 'Follow Up On'] + $date,
      'attendee_count' => ['label' => 'Attendee Count', 'data_type' => 'Int', 'html_type' => 'Text'],
    ],
  ],
  'ce_event_logistics' => [
    'group' => [
      'title' => 'Event Logistics',
      'extends' => 'Event',
      'is_multiple' => FALSE,
      'style' => 'Inline',
    ],
    'fields' => [
      'dress_code' => [
        'label' => 'Dress Code',
        'data_type' => 'String',
        'html_type' => 'Select',
        'option_values' => ['casual' => 'Casual', 'business' => 'Business', 'formal' => 'Formal'],
      ],
      'parking_info' => ['label' => 'Parking Info', 'data_type' => 'Memo', 'html_type' => 'TextArea'],
      'presenting_sponsor' => ['label' => 'Presenting Sponsor', 'data_type' => 'ContactReference', 'html_type' => 'Autocomplete-Select', 'filter' => 'action=get&contact_type=Organization'],
      'livestream_url' => ['label' => 'Livestream URL', 'data_type' => 'Link', 'html_type' => 'Link'],
      'minimum_age' => ['label' => 'Minimum Age', 'data_type' => 'Int', 'html_type' => 'Text'],
    ],
  ],
];

echo "custom groups\n";
foreach ($definitions as $name => $definition) {
  $group = CustomGroup::get(FALSE)->addWhere('name', '=', $name)->execute()->first();
  if (!$group) {
    $group = CustomGroup::create(FALSE)
      ->setValues(['name' => $name] + $definition['group'] + ['is_active' => TRUE, 'collapse_display' => FALSE])
      ->execute()
      ->first();
    printf("  %-38s created (id %d)\n", $name, $group['id']);
  }
  else {
    printf("  %-38s present (id %d)\n", $name, $group['id']);
  }
  $existing = CustomField::get(FALSE)
    ->addSelect('name')
    ->addWhere('custom_group_id', '=', $group['id'])
    ->execute()
    ->column('name');
  $weight = 0;
  foreach ($definition['fields'] as $fieldName => $field) {
    $weight++;
    if (in_array($fieldName, $existing, TRUE)) {
      continue;
    }
    CustomField::create(FALSE)
      ->setValues(['custom_group_id' => $group['id'], 'name' => $fieldName, 'weight' => $weight] + $field + ['is_active' => TRUE])
      ->execute();
    printf("    + %s (%s/%s)\n", $fieldName, $field['data_type'], $field['html_type']);
  }
}

// ---- Values -----------------------------------------------------------------

$pick = static fn(array $items) => $items[mt_rand(0, count($items) - 1)];
$pickSome = static function (array $items, int $min, int $max): array {
  shuffle($items);
  return array_slice($items, 0, mt_rand($min, min($max, count($items))));
};
$randomDate = static fn(int $fromYear, int $toYear): string => sprintf('%04d-%02d-%02d', mt_rand($fromYear, $toYear), mt_rand(1, 12), mt_rand(1, 28));
$contactIds = static fn(string $type, int $limit) => Contact::get(FALSE)
  ->addSelect('id')
  ->addWhere('contact_type', '=', $type)
  ->addWhere('is_deleted', '=', FALSE)
  ->addOrderBy('id')
  ->setLimit($limit)
  ->execute()
  ->column('id');
$rowCount = static fn(string $group): int => (int) civicrm_api4('Custom_' . $group, 'get', ['checkPermissions' => FALSE, 'select' => ['row_count']])->countMatched();
$createRecord = static fn(string $group, array $values) => civicrm_api4('Custom_' . $group, 'create', ['checkPermissions' => FALSE, 'values' => $values])->first();

// A plain-text file in CiviCRM's custom upload directory.
$makeFile = static fn(string $filename, string $content): int => (int) File::create(FALSE)
  ->setValues(['file_name' => $filename, 'content' => $content, 'mime_type' => 'text/plain', 'description' => $filename])
  ->execute()
  ->first()['id'];

$individuals = $contactIds('Individual', 500);
$organizations = $contactIds('Organization', 100);
$events = Event::get(FALSE)->addSelect('id', 'title', 'start_date')->addOrderBy('id')->execute()->getArrayCopy();
$activities = Activity::get(FALSE)->addSelect('id')->addOrderBy('id')->setLimit(50)->execute()->column('id');
$states = \CRM_Core_PseudoConstant::stateProvinceForCountry(1228);
$countries = [1228, 1039, 1226, 1082, 1076, 1101];

echo "\nvalues\n";

if ($rowCount('ce_education') === 0) {
  $schools = ['Springfield University', 'Lakeside College', 'Northern State', 'Riverbend Institute', 'Pine Valley Community College', 'Metro Tech', 'Hillcrest University', 'Coastal Arts Academy'];
  $studies = ['History', 'Biology', 'Computer Science', 'Economics', 'Nursing', 'Fine Arts', 'Mechanical Engineering', 'Public Policy', 'Education'];
  $degrees = ['AA', 'BA', 'BS', 'MA', 'MS', 'PhD', 'CERT'];
  $honors = ['cum_laude', 'magna', 'summa', 'deans_list'];
  $n = 0;
  foreach (array_slice($individuals, 0, 60) as $i => $cid) {
    $year = mt_rand(1985, 2016);
    foreach (range(1, mt_rand(1, 4)) as $r) {
      $start = $year;
      $year += mt_rand(2, 4);
      $graduated = $year < 2026 && mt_rand(0, 4) > 0;
      $values = [
        'entity_id' => $cid,
        'school' => $pick($schools),
        'degree' => $pick($degrees),
        'field_of_study' => $pick($studies),
        'start_date' => sprintf('%04d-09-01', $start),
        'end_date' => $graduated ? sprintf('%04d-05-%02d', $year, mt_rand(10, 28)) : NULL,
        'gpa' => round(mt_rand(250, 400) / 100, 2),
        'graduated' => $graduated,
        'honors' => $graduated ? $pickSome($honors, 0, 2) : [],
        'notes' => mt_rand(0, 2) ? NULL : "Record $r for contact $cid.\nSecond line of notes.",
      ];
      // A handful of transcripts, so the File field has values to render.
      if ($i % 15 === 0 && $r === 1) {
        $values['transcript'] = $makeFile("transcript-$cid.txt", "Transcript for contact $cid\n");
      }
      $createRecord('ce_education', $values);
      $n++;
    }
  }
  printf("  %-38s created %d records\n", 'ce_education', $n);
}
else {
  printf("  %-38s present, skipped\n", 'ce_education');
}

if ($rowCount('ce_skills') === 0) {
  $skills = ['Grant Writing', 'Public Speaking', 'Event Planning', 'Bookkeeping', 'Graphic Design', 'Python', 'Fundraising', 'Translation', 'Social Media', 'Carpentry', 'First Aid', 'Data Analysis'];
  $languages = ['en', 'es', 'fr', 'de', 'zh', 'ar'];
  $n = 0;
  // Individuals and organizations: the group extends Contact. Every fifth
  // contact is filled to max_multiple (5).
  $holders = array_merge(array_slice($individuals, 20, 60), array_slice($organizations, 0, 10));
  foreach ($holders as $i => $cid) {
    $count = $i % 5 === 0 ? 5 : mt_rand(1, 4);
    foreach ($pickSome($skills, $count, $count) as $skill) {
      $certified = (bool) mt_rand(0, 1);
      $createRecord('ce_skills', [
        'entity_id' => $cid,
        'skill' => $skill,
        'proficiency' => mt_rand(1, 5),
        'years_experience' => mt_rand(0, 25),
        'certified' => $certified,
        'cert_url' => $certified ? 'https://certs.example.org/' . strtolower(str_replace(' ', '-', $skill)) . "/$cid" : NULL,
        'languages' => $pickSome($languages, 1, 3),
        'last_used' => sprintf('%s %02d:%02d:00', $randomDate(2022, 2026), mt_rand(8, 18), $pick([0, 15, 30, 45])),
        'endorsed_by' => mt_rand(0, 2) ? $pick($individuals) : NULL,
      ]);
      $n++;
    }
  }
  printf("  %-38s created %d records\n", 'ce_skills', $n);
}
else {
  printf("  %-38s present, skipped\n", 'ce_skills');
}

if ($rowCount('ce_board_terms') === 0) {
  $roles = ['chair', 'vice_chair', 'treasurer', 'secretary', 'member', 'member', 'member'];
  $n = 0;
  foreach (array_slice($organizations, 0, 15) as $oid) {
    foreach (range(1, mt_rand(2, 6)) as $r) {
      $start = mt_rand(2015, 2025);
      $country = $pick([1228, 1228, 1228, 1039]);
      $createRecord('ce_board_terms', [
        'entity_id' => $oid,
        'member' => $pick($individuals),
        'role' => $r === 1 ? 'chair' : $pick($roles),
        'term_start' => sprintf('%04d-01-01', $start),
        'term_end' => sprintf('%04d-12-31', $start + mt_rand(1, 3)),
        'stipend' => $pick([0, 0, 500, 1000, 2500, 5000.50]),
        'country' => $country,
        'state' => $country === 1228 ? array_rand($states) : NULL,
        'bio' => sprintf('<p>Board seat %d. <strong>Term %d.</strong></p>', $r, $start),
      ]);
      $n++;
    }
  }
  printf("  %-38s created %d records\n", 'ce_board_terms', $n);
}
else {
  printf("  %-38s present, skipped\n", 'ce_board_terms');
}

// The vehicles group extends Individual subtype Staff: make sure there are
// staff to hang records off.
$staff = Contact::get(FALSE)
  ->addSelect('id')
  ->addWhere('contact_sub_type', 'CONTAINS', 'Staff')
  ->addWhere('is_deleted', '=', FALSE)
  ->execute()
  ->column('id');
if (count($staff) < 12) {
  foreach (array_slice(array_diff($individuals, $staff), 0, 12 - count($staff)) as $cid) {
    Contact::update(FALSE)->addWhere('id', '=', $cid)->addValue('contact_sub_type', ['Staff'])->execute();
    $staff[] = $cid;
  }
  printf("  %-38s now %d staff contacts\n", 'Staff subtype', count($staff));
}
if ($rowCount('ce_vehicles') === 0) {
  $makes = ['Toyota', 'Honda', 'Ford', 'Subaru', 'Tesla', 'Kia', 'Volvo'];
  $n = 0;
  foreach ($staff as $i => $cid) {
    // 0, 1 or 2 (= max_multiple) vehicles.
    foreach (range(0, $i % 3) as $r) {
      if ($r === 0 && $i % 3 === 0) {
        continue;
      }
      $createRecord('ce_vehicles', [
        'entity_id' => $cid,
        'make' => $pick($makes),
        'model_year' => mt_rand(2008, 2026),
        'plate' => strtoupper(substr(md5("$cid-$r"), 0, 7)),
        'color' => $pick(['black', 'white', 'silver', 'red', 'blue']),
        'parking_permit' => (bool) mt_rand(0, 1),
        'registered_until' => $randomDate(2026, 2028),
      ]);
      $n++;
    }
  }
  printf("  %-38s created %d records\n", 'ce_vehicles', $n);
}
else {
  printf("  %-38s present, skipped\n", 'ce_vehicles');
}

if ($rowCount('ce_event_sessions') === 0) {
  $titles = ['Opening Keynote', 'Panel: The Year Ahead', 'Workshop: Getting Started', 'Lightning Talks', 'Networking Lunch', 'Closing Remarks', 'Breakout: Volunteers', 'Breakout: Donors'];
  $n = 0;
  foreach ($events as $event) {
    $start = strtotime($event['start_date'] ?: '2026-11-01 09:00:00');
    foreach (array_slice($titles, 0, mt_rand(3, 6)) as $s => $title) {
      $createRecord('ce_event_sessions', [
        'entity_id' => $event['id'],
        'session_title' => $title,
        'starts_at' => date('Y-m-d H:i:s', $start + $s * 5400),
        'duration_minutes' => $pick([30, 45, 60, 90]),
        'room' => $pick(['main_hall', 'room_a', 'room_b', 'online']),
        'speaker' => $pick($individuals),
        'capacity' => $pick([25, 50, 100, 250]),
        'fee' => $pick([0, 0, 15, 25.5, 40]),
        'materials_url' => mt_rand(0, 1) ? "https://slides.example.org/event-{$event['id']}/session-" . ($s + 1) : NULL,
        'description' => "<p>{$title} at <em>{$event['title']}</em>.</p>",
        'related_activity' => mt_rand(0, 2) ? $pick($activities) : NULL,
      ]);
      $n++;
    }
  }
  printf("  %-38s created %d records\n", 'ce_event_sessions', $n);
}
else {
  printf("  %-38s present, skipped\n", 'ce_event_sessions');
}

if ($rowCount('ce_professional_memberships_history') === 0) {
  $associations = ['National Nonprofit Council', 'Association of Fundraising Professionals', 'Society for Data Stewards', 'Regional Volunteer Network', 'American Library Association'];
  $n = 0;
  foreach (array_slice($individuals, 40, 30) as $cid) {
    foreach ($pickSome($associations, 1, 3) as $association) {
      $createRecord('ce_professional_memberships_history', [
        'entity_id' => $cid,
        'association' => $association,
        'member_since' => $randomDate(2000, 2025),
        'annual_dues' => $pick([75, 120, 250, 99.99]),
        'currently_active' => (bool) mt_rand(0, 3),
        'level' => mt_rand(1, 3),
      ]);
      $n++;
    }
  }
  printf("  %-38s created %d records\n", 'ce_professional_memberships_history', $n);
}
else {
  printf("  %-38s present, skipped\n", 'ce_professional_memberships_history');
}

// Single-record: values are written through the extended entity.
$profileCount = Contact::get(FALSE)->selectRowCount()->addWhere('ce_profile_all.text', 'IS NOT EMPTY')->execute()->countMatched();
if ($profileCount === 0) {
  $n = 0;
  $targets = array_merge(array_slice($individuals, 0, 70), array_slice($organizations, 0, 10));
  foreach ($targets as $i => $cid) {
    $values = [
      'ce_profile_all.text' => "Profile text for contact $cid",
      'ce_profile_all.select' => $pick(['small', 'medium', 'large']),
      'ce_profile_all.radio' => $pick(['email', 'phone', 'mail']),
      'ce_profile_all.checkboxes' => $pickSome(['news', 'events', 'volunteer', 'advocacy'], 0, 3),
      'ce_profile_all.multiselect' => $pickSome(['mon', 'tue', 'wed', 'thu', 'fri'], 1, 3),
      'ce_profile_all.autocomplete' => $pick(['arts', 'health', 'education', 'environment', 'housing']),
      'ce_profile_all.integer' => mt_rand(-50, 500),
      'ce_profile_all.int_radio' => mt_rand(1, 3),
      'ce_profile_all.float' => round(mt_rand(0, 100000) / 1000, 3),
      'ce_profile_all.money' => round(mt_rand(0, 500000) / 100, 2),
      'ce_profile_all.memo' => "Line one for $cid\nLine two",
      'ce_profile_all.rich_text' => "<p>Rich <strong>text</strong> for contact $cid.</p><ul><li>One</li><li>Two</li></ul>",
      'ce_profile_all.date' => $randomDate(1960, 2026),
      'ce_profile_all.datetime' => sprintf('%s %02d:%02d:00', $randomDate(2024, 2026), mt_rand(0, 23), mt_rand(0, 59)),
      'ce_profile_all.boolean_radio' => (bool) mt_rand(0, 1),
      'ce_profile_all.boolean_toggle' => (bool) mt_rand(0, 1),
      'ce_profile_all.state' => array_rand($states),
      'ce_profile_all.country' => $pick($countries),
      'ce_profile_all.countries' => $pickSome($countries, 1, 3),
      'ce_profile_all.link' => "https://example.org/profile/$cid",
      'ce_profile_all.contact_ref' => $pick($individuals),
      'ce_profile_all.event_ref' => $events ? $pick($events)['id'] : NULL,
      'ce_profile_all.hidden' => "hidden-$cid",
    ];
    // Leave some fields empty, so empty handling gets exercised too.
    if ($i % 7 === 3) {
      foreach (['select', 'checkboxes', 'date', 'contact_ref', 'link', 'money'] as $empty) {
        $values["ce_profile_all.$empty"] = NULL;
      }
    }
    if ($i % 10 === 0) {
      $values['ce_profile_all.file'] = $makeFile("profile-$cid.txt", "Profile attachment for contact $cid\n");
    }
    Contact::update(FALSE)->addWhere('id', '=', $cid)->setValues($values)->execute();
    $n++;
  }
  printf("  %-38s wrote %d contacts\n", 'ce_profile_all', $n);
}
else {
  printf("  %-38s present (%d), skipped\n", 'ce_profile_all', $profileCount);
}

// ce_profile_all.states came later (punch list J31). Its values are written
// on their own and without mt_rand, so every value above stays what earlier
// builds wrote.
$statesCount = Contact::get(FALSE)->selectRowCount()->addWhere('ce_profile_all.states', 'IS NOT EMPTY')->execute()->countMatched();
if ($statesCount === 0) {
  $stateIds = array_keys($states);
  sort($stateIds);
  $stateIds = array_slice($stateIds, 0, 5);
  $profiled = Contact::get(FALSE)->addSelect('id')->addWhere('ce_profile_all.text', 'IS NOT EMPTY')->addOrderBy('id')->execute()->column('id');
  $n = 0;
  foreach ($profiled as $i => $cid) {
    // Every fifth stays empty; the others hold one to three states.
    if ($i % 5 === 4) {
      continue;
    }
    $picked = [];
    for ($k = 0; $k <= $i % 3; $k++) {
      $picked[] = $stateIds[($i + 2 * $k) % count($stateIds)];
    }
    Contact::update(FALSE)->addWhere('id', '=', $cid)->addValue('ce_profile_all.states', $picked)->execute();
    $n++;
  }
  printf("  %-38s wrote %d contacts\n", 'ce_profile_all.states', $n);
}
else {
  printf("  %-38s present (%d), skipped\n", 'ce_profile_all.states', $statesCount);
}

$meetings = Activity::get(FALSE)
  ->addSelect('id', 'ce_meeting_details.outcome')
  ->addWhere('activity_type_id:name', '=', 'Meeting')
  ->addOrderBy('id')
  ->setLimit(40)
  ->execute();
if ($meetings->count() && !array_filter($meetings->column('ce_meeting_details.outcome'))) {
  foreach ($meetings as $meeting) {
    Activity::update(FALSE)->addWhere('id', '=', $meeting['id'])->setValues([
      'ce_meeting_details.agenda' => '<ol><li>Welcome</li><li>Updates</li><li>Next steps</li></ol>',
      'ce_meeting_details.outcome' => $pick(['decided', 'deferred', 'follow_up']),
      'ce_meeting_details.follow_up_on' => $randomDate(2026, 2027),
      'ce_meeting_details.attendee_count' => mt_rand(2, 30),
    ])->execute();
  }
  printf("  %-38s wrote %d meetings\n", 'ce_meeting_details', $meetings->count());
}
else {
  printf("  %-38s %s\n", 'ce_meeting_details', $meetings->count() ? 'present, skipped' : 'no Meeting activities');
}

$logistics = Event::get(FALSE)->addSelect('id', 'ce_event_logistics.dress_code')->execute();
if (!array_filter($logistics->column('ce_event_logistics.dress_code'))) {
  foreach ($logistics as $event) {
    Event::update(FALSE)->addWhere('id', '=', $event['id'])->setValues([
      'ce_event_logistics.dress_code' => $pick(['casual', 'business', 'formal']),
      'ce_event_logistics.parking_info' => "Parking for event {$event['id']}: use the north lot.",
      'ce_event_logistics.presenting_sponsor' => $pick($organizations),
      'ce_event_logistics.livestream_url' => "https://live.example.org/event/{$event['id']}",
      'ce_event_logistics.minimum_age' => $pick([0, 13, 18, 21]),
    ])->execute();
  }
  printf("  %-38s wrote %d events\n", 'ce_event_logistics', $logistics->count());
}
else {
  printf("  %-38s present, skipped\n", 'ce_event_logistics');
}

echo "\ndone\n";
