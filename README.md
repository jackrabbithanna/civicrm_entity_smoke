# CiviCRM Entity multi-record smoke fixture

Sample CiviCRM custom data and Drupal configuration for smoke testing CiviCRM
Entity 5.0.x's multi-record custom group handling in `api4_native`.

```bash
scripts/smoke/build.sh                      # seed + configure + verify (idempotent)
scripts/smoke/build.sh --reinstall-civicrm  # DESTRUCTIVE: fresh CiviCRM + sample data first
```

Drush needs `--uri=https://drupal-civi-composer-exp-d11.ddev.site` on this
site; without it CiviCRM sees `http://default`.

| Script | Does |
| --- | --- |
| `civi-reinstall-sample.php` | CiviCRM's `hook_install` steps with `civicrm_generated.mysql` |
| `civi-seed-custom-data.php` | Custom groups, fields and values (fixed random seed) |
| `ce-settings.php` | Exposes the entity types; sets `filter_format` to `basic_html` |
| `drupal-smoke-config.php` | View/form modes, displays, Views, blocks, menu |
| `verify-smoke.php` | 78 checks: counts, values vs API4, rendering, Views totals, CRUD, constraints, form modes |
| `access-smoke.php` | Non-admin access: 6 users x 4 record types and their parents, with `check_permissions` off/on and custom data open/restricted to a CiviCRM ACL; autocomplete, add-form POSTs, cache order. Restores permissions, ACLs and `check_permissions` on shutdown |
| `browser-smoke.php` | Forms submitted in headless Chromium (ddev `selenium-chrome`): record add/edit/delete, autocomplete, limits, required fields, clearing values, unchanged parent saves; each checked against the CiviCRM tables. Failure screenshots go to `output/`. Since J13 (2026-10-17) all 41 pass in native and `auto`; the multi-select step handles `custom_60[]` (native) and `custom_60` (auto/api3) |
| `modes-smoke.php` | Checks the site in the configured `api_backend`: record types, pending updates, status report, parent lists, Views, pages, writes |
| `modes-smoke.sh` | native -> `auto` -> `api3` -> native the documented way (cset, cr, `config-rewrite --apply`), running the checks in each, j2/j3/j31/j4/j13/j34/j35/j38/j6/j37 included; diffs config exports before/after |
| `submodules-enable.sh` / `submodules-smoke.php` / `submodules-disable.sh` / `submodules-cleanup.php` | Enable the six submodules (contrib deps are in composer), exercise them, then remove their config and uninstall back to baseline. Since J19-J23 (2026-10-17) all 67 pass. Section 5 tracks `ce_education` by default, checks that a record's change queues its parent and that renaming the parent queues its records, and a datasource limited to an event bundle (J19). Section 1 runs "Create linked user" (J20) and the username filters (J21); section 2 checks that the event edit form follows a root `edit` form display (J22, created and removed in place); section 6 adds a contact to a group that does not exist and expects an error message and a log entry (J23), which section 7 leaves out of its error count. Two script-only effects are handled in place: after a `drush cr`, Rules has no listener for its reaction rules until the container is rebuilt (reported as `[info]`, then rebuilt), and VBO's first render of a View created in the same process has no bulk form (the second render is checked). Output in `output/j18-submodules-{before,after}-fix.txt`, `output/j18-submodules-final.txt` |
| `repro-1.php` | Reproduces the router rebuild crash (punch list J1, fixed 2026-10-16) and restores the group: both rebuilds now succeed |
| `j1-smoke.sh` / `j1-smoke.php` | J1: `ce_vehicles` deactivated in SQL and CiviCRM's system rebuild run twice (pages checked while it is gone), then reactivated in SQL and one `drush cr` (type, routes and pages back). J29: disabled through API4 with the routes left in place (its pages and Field UI's give 404, the owner's cached add link goes), then enabled in a separate process (all back without `drush cr`; one process would hit J30). Notes the View over the gone type (J16). Leaves the group active |
| `j4-smoke.php` | J4, read-only: every custom field filter on a `civicrm_value_*` table allows empty and offers both operators (handler and Views UI); `empty` / `not empty` on each `ce_profile_all` column and on `ce_education`'s school against SQL; the directory page with `not empty`. 142 checks (139 before `states`); before the fix 11 passed. Output in `output/j4-{before,after}-fix.txt` |
| `j2-smoke.php` | J2 / F-UT28 / J32, read-only: every list column (record list fields; core list fields in native) has the list display, filter, contextual filter and sort; is one of / all of / none of / empty / not empty and contextual filters (an item, `a+b`, `a,b`, excluded) compared by row ID with PHP's `unSerializeField()`; the display renders every item in one row; the config set's `api3` Views on these tables run with no broken list handler, and their exposed list filters match PHP. 322 checks in native; before the fix 95 passed. Output in `output/j2-{before,after}-fix.txt` |
| `j13-smoke.php` | J13, writes and restores: on a profile contact, four core fields and every `ce_profile_all` field but the booleans, the file and a control are emptied through the entity API and saved; CiviCRM must hold none of them, the control and name must stay, and a second save changes nothing. In `auto`/native also an education record's honors, end date and notes. Restores through API4; in `api3` deletes the records I3 adds. Output in `output/j13-{before,after}-fix.txt` |
| `j3-smoke.php` | J3, writes and restores: sets the saved default view and form displays of every record type aside (restored on shutdown and compared with what was saved), so each type uses the displays built from its base fields, as on a site that has not configured it; `add` and `ce_compact` are left alone. Checks every field's formatter, widget (radios or checkboxes for CiviCRM's Radio and CheckBox fields, booleans included) and label (File fields noted, not counted); each field rendered for a record holding a value, with its label (option labels, Yes/No, two decimals, the referenced entity's label); the edit form's values; the record list. Then in headless Chromium as admin, an event session, a board term and an education record: page and form values, an unchanged save, references picked through the autocomplete, Money, Float, Boolean, Country and State/Province edited, and a required reference refused (by the browser, then by Drupal with the attribute removed); an education record's CheckBox and Boolean Radio fields and a skill's Int Radio field through their radios and checkboxes, and the Boolean Radio emptied through API4 kept empty by an unchanged save; edits restored through API4 (serialized values as lists). Sample activity subjects repeat, so the activity expected is the one the autocomplete filled in. In `api3`, which has no record types, one check. 398 checks; 191 of 272 passed before J3's fix, 340 of 398 before its labels and buttons. The DAO reads NULL as `''`, so emptiness is checked with `IS NULL`. Output in `output/j3-{before,after}-fix.txt`, `output/j3b-{before,after}-fix.txt`, `output/j3*-regress-*.txt` |
| `j38-smoke.php` | J38, writes and restores: `ce_profile_all`'s Boolean Radio and Toggle on contacts. The default contact form (never saved here) gives the Radio field radios with N/A, the Toggle and `is_deceased` a checkbox; that form saved unchanged for a contact with no row in the group's table leaves the Radio field empty and stores the Toggle's 0, as CiviCRM's `advcheckbox` does. In headless Chromium, the fixture's saved `edit` form (checkboxes for both, as a configured 4.0.x site): a contact with no row and one emptied through API4 saved unchanged keep the Radio field empty; ticked it stores 1; a stored Yes unticks to 0; a stored No stays 0. In `api3` the contact forms show the required first fields of multi-record groups (I3), which block the saves, so it checks the guard on the built forms instead (10 checks). Rows it adds are deleted; values put back through API4. 17 checks in native and `auto`; before the fix 13 passed. NULL is spelled out in SQL (the DAO reads it as `''`). Output in `output/j38-{before,after}-fix.txt`, `output/j38-regress-*.txt` |
| `j6-smoke.php` | J6/J39, writes and restores. Without the browser: with `filter_format` empty, the status report has a `civicrm_entity_filter_format` entry naming Plain text; `civicrm_entity_install()` then sets `basic_html`, and the entry goes. In headless Chromium as admin, with `basic_html` (CKEditor 5): markup Basic HTML does not allow (a heading, a style, `<u>`, `<b>`, a table, a `<div>`) is edited without CKEditor, with a note, and an unchanged save leaves CiviCRM's value byte-identical, for event 1's description (the module's widget, display never saved), a `ce_event_sessions` record's description and a named Individual's `ce_profile_all.rich_text` (core's `text_textarea`, on the fixture's displays; not in `api3`); an edit as HTML is saved with the rest kept; markup Basic HTML allows keeps CKEditor and survives an unchanged save. With `full_html` CKEditor stays and keeps all the markup (normalised); with `plain_text` no editor. A save counts only with Drupal's "has been updated". 20 checks in native and `auto`; before the fix 9 passed. In `api3` 9 checks, editor and note only: the event form does not save there (punch list J40; `-- --api3-saves` tries). Output in `output/j6-{before,after}-fix.txt` |
| `j37-smoke.php` | J37, writes and deletes a View `j37_smoke` on contacts with the address (default location type, primary), email (Work) and phone (Any, Mobile) relationships. In headless Chromium as admin, each relationship's nojs Views UI form shows what was saved; applied unchanged and the View saved, the configuration keeps it. 16 checks; before the fix 14 passed (the address form showed Any, and saved `0`). Output in `output/j37-{before,after}-fix.txt` |
| `j31-smoke.php` | J31, read-only: a filter is `multi` exactly when CiviCRM says the field is serialized; for each of the six serialized fields (two in multi-record groups, through the parent), `in` / `not in` every value held and `in` two values, against a binary SQL count. 72 checks; before the fix 20 passed. Output in `output/j31-{before,after}-fix.txt` |
| `j34-smoke.php` / `j34-updb.php` | J34, read-only: every saved CiviCRM display and every View against its config schema; a new default form and view display, built in memory for every enabled CiviCRM entity type and bundle, against it too; the new form displays carry CiviCRM's row counts in the widget settings (activity details 8, event summary 4, TextArea custom fields' `note_rows` in native) and the built event form uses them. Four smoke Views carry the fixture's own schema errors, set aside: `expose.reduce` on string and boolean filters, and multi-value options on the `civicrm_entity_custom_file` field. `j34-updb.php -- inject` writes the contact `edit` form display as 4.0.x left it and marks post-update 5004 pending; after `drush updb`, `-- check` compares and puts the display back. Output in `output/j34-{before,after}-fix.txt`, `output/j34-updb.txt` |
| `j35-smoke.php` | J35/J36, read-only: every Views plugin of the module (handlers on a base table that has or joins them; access, query, contextual filter default and validator), with its defaults as the Views UI saves them and cast as a save casts them, against the config schema; then an address View filtered by two states (the state filter could not be created on Drupal 12) against a SQL count. 36 checks here (35 on proto, which has no File custom field); before the fix 20 passed here. Since J35 `j34-smoke.php` counts the J35 errors it used to set aside. Output in `output/j35-{before,after}-fix.txt`, `output/j35-updb.txt` |

## CiviCRM custom groups

Multi-record (each becomes entity type `civicrm_cv_<name>`):

| Group | Extends | Notes |
| --- | --- | --- |
| `ce_education` | Individual | 10 fields incl. File, CheckBox; one inactive field (excluded) |
| `ce_skills` | Contact | `max_multiple` 5; Link, Toggle, multi-Select, datetime, ContactReference |
| `ce_board_terms` | Organization | ContactReference (required), Country, StateProvince, rich text |
| `ce_vehicles` | Individual / Staff subtype | `max_multiple` 2 |
| `ce_event_sessions` | Event | non-contact parent; ContactReference + EntityReference (Activity) |
| `ce_professional_memberships_history` | Individual | ID hashed: `civicrm_cv_ce_professiona_7cb000` |
| `ce_archived_notes` | Contact | inactive group: no entity type |
| `ce_empty_multi` | Contact | no fields: no entity type |

Single-record: `ce_profile_all` (Contact, every data/html type pairing CiviCRM
allows; `states`, the multi-select State/Province, was added last on 2026-10-16
and backfilled without `mt_rand`), `ce_meeting_details` (Activity, Meeting type only),
`ce_event_logistics` (Event).

## Drupal configuration

- View modes `ce_summary` (contact, event) and `ce_compact` (record types);
  form modes `add` (record types) and `edit` (contact).
- Views, linked from the **CE smoke** main-menu item:
  `/smoke/education` (record base table, exposed filters, click sort,
  operations), `/smoke/skills` (contact base, reverse relationship, endorser
  via ContactReference), `/smoke/skills/summary` (aggregation),
  `/smoke/board-terms` (grouped), `/smoke/directory` (4.0.x-style
  `civicrm_value_*` tables) and `/smoke/directory/cards` (`ce_summary` view
  mode), `/smoke/event-sessions`, `/smoke/vehicles` (`ce_compact` rendered
  entities), `/smoke/memberships-history` (hashed type).
- Blocks: a contact's education records on `/civicrm-contact/*`; an event's
  sessions on `/civicrm-event/*`.

## Second pass, 2026-10-16

The findings of both passes are section J of `outstanding-punch-list.md` in the
ce-docs repository (the first pass's are also in `problems-report.md`).

- `access-smoke.php` keeps its users (`smoke_none`, `smoke_viewer`,
  `smoke_editor`, `smoke_acl`), roles (`smoke_contact_viewer`,
  `smoke_contact_editor`), the CiviCRM group `ce_smoke_acl`, the ACL role
  `ce_smoke_acl_role` and two inactive ACLs named `CE smoke: ...`.
- In `api3`, every contact save adds a multi-record record per group (punch
  list I3/J15); `modes-smoke.php` deletes what it adds.
- `ddev snapshot` restore points: `smoke-baseline-ab4d51b-20261016`,
  `smoke-before-modes-20261016`, `smoke-before-submodules-20261016`,
  `smoke-before-j31-20261016` (before `ce_profile_all.states`),
  `smoke-before-j2-modes-20261016` (before the J2 mode round trip),
  `smoke-before-j13-20261017` (before J13's smoke run),
  `smoke-before-j18-20261017` (before the submodules were enabled for J18),
  `smoke-before-j33-j23-20261017` (before the J33-J23 smoke runs).

