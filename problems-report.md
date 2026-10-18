# CiviCRM Entity 5.0.x multi-record smoke test: problems found

- **Tested:** `civicrm_entity` branch `multi-record-custom-fields-architecture` at
  `23cf4c7`, with `api_backend: api4_native`.
- **Stack:** Drupal 11.4.10, CiviCRM 6.19.1, PHP 8.4, MariaDB 11.8, on DDEV.
- **Data:** CiviCRM core sample data, plus 8 multi-record and 3 single-record
  custom groups that cover every data type and html type pairing CiviCRM
  allows.
- **What was exercised:** the record types through view modes, form modes,
  Views, blocks, and create/update/delete.
- **Fixture:** `scripts/smoke/` under the site root
  `/var/www/web/drupal-civi-composer-experiment` (see its `README.md`). The
  module repo was not modified.

Paths are relative to the module root
(`web/modules/contrib/civicrm_entity`), except those starting with `web/core`
or `vendor/`. Line numbers are at `23cf4c7`.

## Summary

| # | Severity | Problem | Origin |
| --- | --- | --- | --- |
| 1 | High | CiviCRM's system rebuild crashes Drupal's router rebuild after a group disappears out of band | Branch: record types make a stale cache reachable |
| 2 | Medium | Serialized multi-value fields are missing from record-type Views data | Branch |
| 3 | Medium | Default display options: none for reference, Float/Money or (view) Boolean fields; raw IDs for option integers | Pre-existing, now user-facing on record types |
| 4 | Medium | `civicrm_value_*` Views filters lack `allow empty`, and a saved empty operator is silently dropped | Pre-existing |
| 5 | Low | File custom fields map to `integer`, Link custom fields to `string` | Pre-existing |
| 6 | Low | `filter_format: ''` on install, so rich text renders escaped | Pre-existing |

---

## 1. Router rebuild crashes after a group disappears out of band

**Fixed 2026-10-16 (punch list J1).** One `drush cr` now undoes the repro; `j1-smoke.sh` checks it.

### Symptom

A multi-record group can vanish or go inactive without CiviCRM firing any
hooks: a database drop and reinstall, an SQL import or restore, or a direct
SQL update. After that, the next CiviCRM system rebuild fails. A system
rebuild runs on extension enable or install, Cleanup Caches, `cv flush`, or an
upgrade. The error:

```
[warning] Undefined array key "civicrm_cv_ce_vehicles" CivicrmEntity.php:154
LogicException: The id field definition does not exist and it is used as id entity key.
```

Every later router rebuild in the same request then fails:

```
RuntimeException: Recursive router rebuild detected.
```

That second error happens because core's `RouteBuilder::rebuild()` sets
`$this->building = TRUE`
(`web/core/lib/Drupal/Core/Routing/RouteBuilder.php:125`) and resets it only
on success (`:148`).

Found by reinstalling the CiviCRM database while record types were installed:

1. Setup's InstallExtensions step enables extensions through API3
   `Extension.enable`.
2. The first extension's rebuild throws the `LogicException`.
3. The next extension's rebuild reports the recursion as a `CRM_Core_Exception`
   from `api.php`.
4. Installation aborts. The CiviCRM tables exist, but the default extensions
   (search_kit, afform, authx, riverlea, …) are left disabled.

### Minimal repro

This is reversible and takes about 10 seconds. Run it as a php script with
`drush --uri=https://drupal-civi-composer-exp-d11.ddev.site php:script`:

```php
\Drupal::service('civicrm')->initialize();
\CRM_Core_DAO::executeQuery("UPDATE civicrm_custom_group SET is_active = 0 WHERE name = 'ce_vehicles'");
\Civi::rebuild(['system' => TRUE, 'triggers' => TRUE])->execute(); // LogicException
\Civi::rebuild(['system' => TRUE, 'triggers' => TRUE])->execute(); // Recursive router rebuild detected
```

To undo it (this exact sequence was verified):

1. Set `is_active = 1`.
2. Run `drush cr`.
3. Run `\Civi::rebuild(['system' => TRUE, 'triggers' => TRUE])->execute()`.
4. Run `drush cr` again.

The reverse-direction note below explains why it takes two `cr` runs.

Trace, innermost first:

```
CivicrmEntity::baseFieldDefinitions()               src/Entity/CivicrmEntity.php:154
EntityFieldManager->buildBaseFieldDefinitions()     web/core/lib/Drupal/Core/Entity/EntityFieldManager.php:204
ContentEntityType->hasIntegerId()
DefaultHtmlRouteProvider->getCanonicalRoute()       web/core/lib/Drupal/Core/Entity/Routing/DefaultHtmlRouteProvider.php:229
CiviCrmEntityRouteProvider->getRoutes()             src/Routing/CiviCrmEntityRouteProvider.php:20
EntityRouteProviderSubscriber->onDynamicRouteEvent()
RouteBuilder->rebuild()                             web/core/lib/Drupal/Core/Routing/RouteBuilder.php:134
CRM_Utils_System_Drupal8->invalidateRouteCache()    vendor/civicrm/civicrm-core/CRM/Utils/System/Drupal8.php:963
Civi\Core\Rebuilder->execute()                      vendor/civicrm/civicrm-core/Civi/Core/Rebuilder.php:216
```

### Root cause

Two caches describe the record types, and a CiviCRM flush invalidates only one
of them:

- `SupportedEntities::getInfo()`: cache ID `civicrm_entity:entity_info`, tag
  `civicrm_entity_metadata`. It includes the record types from
  `CustomRecordTypeRegistry::getTypes()`.
- Drupal's entity type definitions: tag `entity_types`. They are built from
  `getInfo()` in `EntityHooks`' `hook_entity_type_build`.

The sequence:

1. `Rebuilder::execute()` dispatches `civi.core.clearcache` (`Rebuilder.php:172`)
   before it rebuilds Drupal's routes (`:216`).
2. `CiviCrmEventSubscriber::onClearCache()`
   (`src/EventSubscriber/CiviCrmEventSubscriber.php:256`) calls
   `CacheInvalidator::invalidateMetadata()`, which runs
   `SupportedEntities::reset()`. It also invalidates `entity_field_info` and
   `views_data`, but leaves the entity type definitions cached.
3. So `getInfo()` is rebuilt without the vanished group, while the entity
   types still list it.
4. The route rebuild needs that type's field definitions. Line 154 indexes
   `getInfo()` with the missing key, so `$civicrm_entity_info` is NULL, no
   fields are built (not even `id`), and core throws.

In-band changes are fine. `CacheInvalidator::invalidate()` clears the entity
type and typed data definitions on CustomGroup and CustomField writes
(`src/Cache/CacheInvalidator.php:107-112`). Deleting the groups through API4
and then running `drush cr` works; the fixture's `build.sh --reinstall-civicrm`
relies on that as a workaround.

Line 154 has been unguarded since 2018. Before this branch the set of entity
types never depended on CiviCRM data, so the two caches could not disagree.

### Reverse direction (lower impact, same cause)

When a group comes back out of band, a CiviCRM flush gives `getInfo()` the
type while the entity definitions still lack it, until the next `drush cr`.
Meanwhile, loading it fails with `The "civicrm_cv_ce_vehicles" entity type
does not exist.` `SupportedEntities::getEntityType()` already guards CiviCRM
hooks against this case.

During the undo, the first `drush cr` also uninstalled the restored type. Its
synchronizer (`EntityHooks::rebuild()` → `CustomRecordTypeSynchronizer`)
appears to read `CRM_Core_BAO_CustomGroup::getAll()` from CiviCRM's own cache,
which still had the group as inactive. The orphaned config was kept (decision
4), so the second `cr` restored the type cleanly.

### Suggested fix

1. In `onClearCache()`, also clear the entity type manager's and the typed
   data manager's cached definitions, as `CacheInvalidator::invalidate()`
   already does for schema entities. Because `civi.core.clearcache` runs
   before CiviCRM rebuilds Drupal's routes, the routes would then be built
   from the current types. A vanished type's last-installed definition remains
   until `hook_rebuild` syncs; check that nothing in a route rebuild reads it.
   Running the synchronizer from `onClearCache()` is possible, but it would
   run inside CiviCRM's install or upgrade.
2. Add a defensive guard. Nothing should throw out of a route rebuild, because
   the `building` flag then breaks every later rebuild in that request. Have
   `CiviCrmEntityRouteProvider::getRoutes()` skip a `civicrm_custom_record`
   type that is not in `SupportedEntities::getInfo()`, or make
   `baseFieldDefinitions()` cope with missing info.
3. Add a kernel test:
   1. Install a record type.
   2. Deactivate or delete its group in SQL.
   3. Dispatch `civi.core.clearcache`, or call `Civi::rebuild(['system' =>
      TRUE, 'triggers' => TRUE])`.
   4. Assert that `router.builder->rebuild()` does not throw, and that a second
      rebuild does not throw either.

---

## 2. Serialized multi-value fields are missing from record-type Views data

### Symptom

In a View with a record type as its base table, CheckBox and multi-select
fields are not offered as fields, filters or sorts. Examples:
`civicrm_cv_ce_education.custom_14` (Honors) and
`civicrm_cv_ce_skills.custom_23` (Languages Used). The same fields do appear on
the parent-side group tables, for example
`civicrm_value_education_his_4.honors_14` with a `civicrm_entity_in_operator`
filter.

`views.views_data` for `civicrm_cv_ce_education` lists `school_7` through
`notes_15`, `transcript_16`, `id` and `entity_id`, but no `honors_14`.

### Root cause

These fields are `list_string` with cardinality -1 and custom storage, from
`src/Entity/FieldDefinitionProvider.php`. In
`CivicrmEntityViewsData::getViewsData()`:

- `allowsSharedTableStorage()` (`src/CivicrmEntityViewsData.php:123`) is
  false.
- `requiresDedicatedTableStorage()` (`:182`) is also false.
- The only other path that describes custom fields,
  `processViewsDataForCustomFields()`, is skipped for records by the
  `!$custom_record` check (`:198`).

The module already compensates for the same skip on the activity fields
`assignee_contact_id` and `target_contact_id` (see the comment at `:370-382`).

### Suggested fix

For a record type, describe each serialized custom field on the record's base
table:

- Use `column_name` as the key, so it matches `CustomRecordTableMapping`.
- Give it the handlers `processViewsDataForCustomFields()` would give it, such
  as `civicrm_entity_in_operator` with `multi`.
- Set `entity field` so that the `field` handler renders it.

Add a test that the Views data includes these fields.

---

## 3. Default display options are missing or show raw values

**Fixed 2026-10-18 (punch list J3), on record types.** Each field of a record
type gets its type's default widget and formatter; booleans read Yes and No,
every field shows its label, and Radio and CheckBox fields get radios and
checkboxes.
Two corrections to what follows: option integers rendered their labels on the
record's page all along (the formatter manager falls back to `list_default`,
though the display kept `number_integer`), and the IDs were in the record list,
which showed stored values and now renders its columns through the formatter.
`j3-smoke.php` checks it.

An unconfigured record type uses the base field display options from
`FieldDefinitionProvider`. Each record type is a new entity type, so its
default view and default `add` form are what a site builder sees first.
The table shows what those options are, as checked on the record types:

| CiviCRM type | Drupal type | Default view | Default form | Where set |
| --- | --- | --- | --- | --- |
| ContactReference, EntityReference | `entity_reference` | none | none | `FieldDefinitionProvider.php:55`, `:85` |
| Float, Money | `float` | none | none | `:107` (`@todo`) |
| Boolean | `boolean` | none | `boolean_checkbox` | `:318` |
| Int with options, Country, StateProvince | `list_integer` | `number_integer`: shows the raw ID | `options_select` | `:288` |
| String (Text) | `string` | `text_default` | `string_textfield` | `:360`, `:517` |

The effects, all observed on this site:

- **Board Terms add form:** `ce_board_terms.custom_26` (Board Member, a
  required ContactReference) is absent from the default `add` form. What
  saving without it does was not tested.
- **Money and Float fields** are on neither the default form nor the default
  view: GPA, Annual Stipend, Session Fee.
- **Reference fields** are on neither: Endorsed By, Speaker (ContactReference),
  and Related Activity (EntityReference).
- **Yes/no fields** are missing from the default view.
- **Option integers** (Proficiency, Seat Country, Seat State/Province) render
  as IDs. Configuring `list_default` shows the labels correctly.
- **`text_default`** does not apply to `string`. Drupal swaps in
  `StringFormatter` when rendering, so the output is fine, but the display
  component keeps `type: text_default`.

The branch already handles this for `entity_id`
(`src/Entity/CivicrmEntity.php:195`: "Entity references get no widget from the
field definition provider").

### Suggested fix

Give custom reference fields `entity_reference_autocomplete` and
`entity_reference_label`, at least on record types. Give `float` the `number`
widget and the `number_decimal` formatter, `boolean` the `boolean` formatter,
`list_integer` the `list_default` formatter, and `string` the `string`
formatter. These defaults matter only where no display is saved, but they also
change the unconfigured defaults of the existing parent types.

---

## 4. `civicrm_value_*` Views filters lack `allow empty`

**Fixed 2026-10-16 (punch list J4).** Every custom field filter allows empty;
`/smoke/directory` uses `not empty` again and lists 80 contacts. `j4-smoke.php`
checks it. Found while testing it: punch list J31 (multi-select "is one of").

### Symptom

The setup: a View with `civicrm_contact` as its base table and no
relationships. Filters on columns of `civicrm_value_*` tables then offer no
"Is empty (NULL)" or "Is not empty (NOT NULL)". If a View's config sets
`operator: 'not empty'` anyway, for example on
`civicrm_value_profile_all_f_12.text_56`, the filter adds no condition at all,
because `StringFilter` has no method for an operator it does not offer.

The fixture's `/smoke/directory` returned all 203 contacts instead of the 80
that have a value. The fixture now uses `longerthan 0` as a workaround
(`scripts/smoke/drupal-smoke-config.php`).

In the Views data for `civicrm_value_education_his_4` and
`civicrm_value_profile_all_f_12`, only `contact_reference_77` has
`allow empty`. Record-type tables get it from core for every non-required field
(`web/core/modules/views/src/EntityViewsData.php:598-601`).

It is easy to miss: `FilterPluginBase::init()` forces `allow empty` whenever
the display has a relationship
(`web/core/modules/views/src/Plugin/views/filter/FilterPluginBase.php:146-151`).
So the problem only shows in Views without relationships.

### Root cause

`getViewsFilterPlugin()` (`src/CivicrmEntityViewsData.php:791`) sets
`allow empty` only in the ContactReference case (`:820`). This is 4.0.x code,
but the parent-side group tables of multi-record groups still go through it.

### Suggested fix

Set `'allow empty' => TRUE` on every custom field's filter, or on every field
whose `is_required` is empty, as core does. Custom value columns are nullable,
and a contact with no group row already reads as NULL through the LEFT join.

---

## 5. File and Link custom fields map to plain scalars

- **File** (`ce_education.custom_16` Transcript, `ce_profile_all.custom_75`):
  - API4 reports these as Integer with a foreign key to File. `civicrm_file` is
    not a supported entity type, so the `getIntegerDefinition()` fallback
    makes them `integer`, with the `number_integer` formatter and the `number`
    widget.
  - A record page shows "Transcript: 1", which is the `civicrm_file` ID, and
    the form offers a number box.
  - In Views, the group table uses the `civicrm_entity_custom_file` field
    plugin (`src/CivicrmEntityViewsData.php:753`) with a string filter. The
    record table gives the same column a numeric field and filter.
  - Suggested fix: reuse `CustomFile`'s rendering for a formatter, and give
    these fields no default number widget.
- **Link** (`ce_skills.custom_22` Certificate URL, `ce_event_sessions.custom_47`
  Materials): these become `string` and render as plain text. The provider
  already has `getUrlDefinition()` (`uri` with `uri_link`) for `T_URL`;
  suggested fix: map `data_type`/`html_type` Link to it.

## 6. Rich text renders escaped until `filter_format` is set

**Fixed 2026-10-18 (punch list J6), with J39.** A new site gets `basic_html`
where it exists, and the status report warns a site with no format chosen.
The suggested default alone would have lost markup: with `basic_html` and
CKEditor 5 (as this fixture has it), saving event 1's edit form unchanged
removed every element and attribute Basic HTML does not allow from CiviCRM's
value (J39). A value like that is now edited as HTML. `j6-smoke.php` checks
both.

`config/install/civicrm_entity.settings.yml` ships `filter_format: ''`.
`CiviEntityStorage` (`src/CiviEntityStorage.php:345-347`, `:586-591`) then
falls back to `getFallbackFormatId()`, which is `plain_text`. RichTextEditor
fields therefore render with their HTML tags visible (Board Bio, Session
Description, the profile's rich-text memo). Escaping is the safe default, but
it is what every new install sees first.

Suggested fix: note it in the README or the status report, or default to
`basic_html` when it exists. The fixture sets `basic_html`.

---

## Minor

- 60 `@see docs/*.md` references in `src/` point to a `docs/` directory that
  is neither in the repository nor git-ignored. Examples:
  `multi-record-phase-2-plan.md`, `multi-record-custom-groups.md`,
  `api4-native-mode.md`, `outstanding-punch-list.md`.

## Passed (no action needed)

The fixture's `verify-smoke.php` runs 77 checks. All pass, both on an
idempotent rebuild and after a full CiviCRM reinstall:

- **Type registration:** six record types are registered and installed, with
  no pending entity updates. One of them has a hashed ID:
  `civicrm_cv_ce_professiona_7cb000`.
- **Exclusions:** the inactive group, the field-less group and the inactive
  field are correctly left out.
- **Group lifecycle:** after a group is deleted in band, `cr` uninstalls its
  type and keeps 30 orphaned config objects. No Views are disabled.
- **Queries and rendering:** entity query counts match SQL, records render in
  `default` and `ce_compact`, and the parent's computed multi-record lists are
  correct.
- **Single-record values:** every `ce_profile_all` value round-trips against
  API4. Datetimes are stored in UTC on the Drupal side and in America/Denver
  in CiviCRM, both correct.
- **Views totals:** these match the database: record base tables, the
  contact-to-record reverse relationship, ContactReference and EntityReference
  relationships, aggregation, and the 4.0.x group tables.
- **Writes:** create, update and delete through Drupal work, as does saving a
  contact's single-record values.
- **Constraints:** each of these is refused:
  - a 6th skill (max 5);
  - a 3rd vehicle (max 2);
  - an Individual-only group on an organization;
  - a Staff-only group on a non-Staff contact.
- **Pages and logs:** all 22+ fixture pages return 200 as admin, with no PHP
  errors or watchdog warnings.

Not covered:

- form submission in a browser (forms were built and their fields checked);
- non-admin access and `check_permissions`;
- the `auto` and `api3` modes;
- the submodules.

## Environment

- **URL:** https://drupal-civi-composer-exp-d11.ddev.site (login `admin` /
  `admin`).
- **Drush:** needs `--uri=https://drupal-civi-composer-exp-d11.ddev.site`.
  Without it CiviCRM sees `http://default`.
- **Reset:** `scripts/smoke/build.sh` seeds, configures and verifies, and is
  idempotent. `build.sh --reinstall-civicrm` first reinstalls CiviCRM with
  sample data; it is destructive.
- **Sample IDs:** contact 28, organization 47, Staff contact 7, event 1,
  meeting activity 2.
- **Record routes:** `/civicrm-cv-<type-with-dashes>/{id}` (plus `/edit` and
  `/delete`) and `/civicrm-cv-<type>/add?entity_id=N`.
- **Record lists:** `/admin/structure/civicrm-entity/civicrm-cv-<type>`.
