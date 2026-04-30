# Swim test feature — technical architecture

## Overview

The swim test feature lives across two custom modules:

- **`picc_registration`** — webforms, handlers, evaluation form, views field plugins, query alters, cron, email routing, translations.
- **`picc_ext`** — variant_session stock filter (paddling listing) and the participant pool/medical/photo status icons.

Plus:
- Config in `config/sync/` (views, webforms, commerce types, fields, emails, language overrides).
- Theme templates in `html/themes/custom/daisyui_ext/templates/view/`.

## Data model

### Profile (bundle: `participant`) — swim fields

| Field | Type | Purpose |
|---|---|---|
| `field_swim_status` | list_string | Current season clearance. Values: `none`, `passed`, `failed`, `attested`, `exempt` |
| `field_evaluation_date` | datetime | Date of the most recent pass-or-fail evaluation by a coach |
| `field_swim_test_evaluated_by` | user reference | Coach who recorded the evaluation |
| `field_swim_attestation_date` | datetime | Date attestation was submitted |
| `field_swim_attestation_by` | user reference | User who submitted attestation |

All five are cleared on Jan 1 by `_picc_registration_annual_swim_status_reset()`.

Field permissions module restricts edits to coach / commerce_manager / content_editor roles.

### Commerce

- **Product type `activity`** holds both activity variations and swim_test_slot variations.
- **Variation type `swim_test_slot`**:
  - `field_date` — single date, site timezone, date-only precision.
  - `field_time_range` — datetime range, datetime precision, `time_range` widget. UTC internally.
  - `field_capacity` — commerce_stock_level. Stock service mapped to `local_stock` in `commerce_stock.service_manager`.
  - `field_desc` — optional string.
  - Price is forced to $0 CAD on presave by `picc_registration_commerce_product_variation_presave()`. Hidden from the form.
- **Order item type `swim_test_registration`**:
  - `field_participant` — profile reference, single cardinality (one participant per order item).
  - `field_swim_test_status` — list_string: `pending`, `passed`, `failed`, `no_show`, `excused`. Tracks the lifecycle of a registered slot.
  - `field_swim_test_excuse_reason` — text_long. Set by commerce manager when resolving no-shows.

### Status flow

The **profile** is the source of truth for "can this kid get in a boat?" The **order item** is a per-registration record used by the no-show pipeline.

When a coach evaluates a participant (registered or walk-up), `SwimTestMarkForm::applyEvaluation()`:
1. Writes the outcome to the profile: `field_swim_status` (passed/failed), `field_evaluation_date`, `field_swim_test_evaluated_by`.
2. If the participant has any **pending** `swim_test_registration` order item, mirrors the same outcome onto its `field_swim_test_status`. This pulls the row out of the `pending` bucket so the no-show cron skips it.

## Flows

### Parent registers a kid for a pool test

1. Parent visits `/program/swim-test`, sees variants from the `variant_session` view.
2. Out-of-stock variants are hidden by `picc_ext_views_query_alter()`.
3. Click "Register for this swim test" → `/form/swim-test-registration?product=X&variation=Y`.
4. The `swim_test_registration` webform filters participants to under-15s via `er_swim_participants` (Dec 31 of `current_year - 15`, injected by `picc_registration_views_query_alter()`).
5. On submit, `PiccSwimTestRegistrationHandler::submitForm()` validates, locks for stock, creates one order item per selected participant, and transitions the order to `completed`.
6. `commerce_email` fires `swim_test_receipt` and `swim_test_notify_picc` on `order_placed` (filtered to swim_test_slot variation type).

### Parent attests an adult

1. Parent visits the attestation page (content-linked to `/form/swim-attestation`).
2. `swim_attestation` webform filters to 15+ via `er_attestation_participants`.
3. On submit, `PiccSwimAttestationHandler::submitForm()` validates and sets `field_swim_status = attested`, `field_swim_attestation_date = today`, `field_swim_attestation_by = current user`.
4. Two Webform email handlers send notifications (parent + PICC).

### Coach evaluates (Architecture B — profile-based roster)

1. Coach visits `/participants/swim-test-roster` (`swim_test_roster` view, coach / commerce_manager / content_editor access).
2. The view is **profile-based**: one row per under-15 participant. Pre-registered kids cluster under their slot heading; participants with no pending registration fall into the "Unscheduled" group at the bottom.
3. Each row renders three things:
   - Name (`field_name`).
   - Status badge — rendered by the `ParticipantSwimStatusBadge` field plugin from the profile's `field_swim_status` (Pending / Passed / Failed / Attested / Exempt).
   - Action — rendered by `ParticipantSwimAction`. **Evaluate** button (linked to `/swim-test/evaluate/{profile}`) shows only when status is `none` or `failed`; everything else is badge-only.
4. Click → off-canvas dialog at `/swim-test/evaluate/{profile}` handled by `SwimTestMarkForm::buildEvaluationForm()`. Date field (defaults to today) + Pass and Fail buttons.
5. Submit calls `SwimTestMarkForm::applyEvaluation()` → profile + mirrored order item update (see "Status flow" above).

The view's status filter is exposed and **defaults to "Pending"**, which expands to `none + failed` (kids who still need a coach test). Other options: Passed, Failed only, Attested, Exempt, or "- Any -" to show every participant regardless of status.

### Commerce manager resolves a no-show

1. Visits `/admin/swim-test-no-shows` (`swim_test_no_show_review` view, commerce_manager only).
2. `SwimTestAction` plugin renders a **Resolved** button on no_show rows.
3. Click → `/swim-test/mark/{commerce_order_item}` handled by `SwimTestMarkForm::buildOrderItemForm()`. Required reason textarea + "Mark resolved" button.
4. On submit, status → `excused`, reason → `field_swim_test_excuse_reason`.
5. Undo flips back to `no_show` and clears the reason.

The order-item route also handles Undo for resolved/excused rows from this same view.

## View construction

The roster view is unusual enough to warrant its own section. Base table is `profile` so walk-ups (no order item) appear naturally; the trick is constraining a reverse-reference relationship without dropping walk-ups.

### Relationships

- `reverse__commerce_order_item__field_participant` — auto-provided by Views, joins profile to order_item (LEFT). Required = false.
- `commerce_product_variation` — joins order_item to variation via purchased_entity. Required = false.
- The variation's `field_date` and `field_time_range` are exposed as fields (excluded from row markup, used for native sort + grouping).

### Constraint via `hook_views_query_alter`

`picc_registration_views_query_alter()` for the `swim_test_roster` view does three things:

1. **Adds the under-15 cutoff** via `addWhere` on `profile__field_birth_date.field_birth_date_value`.
2. **Constrains the field-participant JOIN** — appends `bundle = 'swim_test_registration'` to its `extra` array so paddle-camp registrations don't double rows.
3. **Constrains the order-item JOIN** — replaces its `extra` with a string condition: `oi.type = 'swim_test_registration' AND EXISTS (SELECT … status = 'pending') AND EXISTS (SELECT … order state IN completed/fulfillment)`. The string form bypasses Drupal's array-extra parser, which can't express EXISTS subqueries against other tables.
4. Stashes the variation date table alias on `$query->options` for the query-tag alter to use.

### NULL-last sort via `hook_query_TAG_alter`

`picc_registration_query_views_swim_test_roster_alter()` runs against the database query (tag `views_swim_test_roster`):

1. Adds a CASE expression `CASE WHEN <date_alias>.field_date_value IS NULL THEN 1 ELSE 0 END` via `addExpression`.
2. Adds an ORDER BY on that expression alias.
3. Uses reflection to move the new ORDER BY to the **front** of the order list, so walk-ups (NULL date) sort last.

This is done in the database-query phase rather than the views-query phase because Views' `Sql::query()` runs ORDER BY fields through `escapeField`, which mangles the formula.

### Twig templates

- `views-view-grouping--swim-test-roster.html.twig` — h2 for level 0 (date), with a `default('Unscheduled'|t)` fallback for walk-ups whose group title would otherwise be empty.
- `views-view-fields--swim-test-roster.html.twig` — three columns (name | badge | Evaluate) using utilities listed in the theme's `safelist.txt` (`flex-1`, `gap-3`, `gap-4`, `ml-auto`, etc.).
- `views-view-unformatted--swim-test-roster.html.twig` — wraps rows in a flex column.

If you add a new utility class to one of these templates and it doesn't render, add the class to `html/themes/custom/daisyui_ext/css/safelist.txt` and run `npm run build` from the theme directory.

## Cron (`hook_cron`)

`picc_registration_cron()` calls two helpers on every cron run:

1. **`_picc_registration_annual_swim_status_reset()`** — state-gated. Runs once per year when `current_year > last_swim_reset_year`. Clears `field_swim_status`, `field_evaluation_date`, `field_swim_test_evaluated_by`, `field_swim_attestation_date`, `field_swim_attestation_by` on every participant profile. Sets state to current year.

2. **`_picc_registration_auto_no_show_sweep()`** — runs every cron. Finds `swim_test_registration` order items still at `pending` on completed/fulfillment orders. Computes the slot end time by combining `field_date` (site tz) with the UTC time from `field_time_range.end_value`. If slot ended more than 8 hours ago, flips to `no_show`.

Coach evaluations mirror their outcome onto the order item, so an evaluated registration won't get caught by the no-show sweep.

## Preventing duplicate registrations

Two layers in `PiccSwimTestRegistrationHandler`:

1. `isRegisteredForSlot($profile_id, $variation_id)` — blocks registering the same participant for the same slot regardless of date.
2. `getExistingFutureSwimTestRegistration($profile_id)` — blocks registering when the participant already has a `pending` registration for today or a future slot. Once the coach evaluates, the order item flips out of `pending` and the parent can re-book if needed.

The form also visually disables checkboxes via `_picc_registration_disable_cleared_participants` (passed/attested/exempt) and `_picc_registration_disable_future_swim_registered` (pending future registrations).

## Emails

All swim-test-related emails go through **commerce_email** (for swim_test_slot orders) or **Webform email handlers** (for attestation).

Email configs in `config/sync/commerce_email.commerce_email.swim_test_*.yml` are filtered to the `swim_test_slot` variation type. Activity registration emails are filtered to `activity` variation type. No overlap.

`commerce_email` fires on `order_placed`, which happens when the order transitions to `completed` in the handler.

## Translations

Two layers:

- **t() strings in PHP** — `html/modules/custom/picc_registration/translations/fr.po`. Imported by `_picc_registration_import_translations()` on install and by incremented `picc_registration_update_1000N()` functions when strings change.
- **Config strings (view labels, filter labels, allowed-value labels, menu titles, etc.)** — `config/sync/language/fr/*.yml` overlays. These are part of config sync; `drush cim` applies them.

To add new translations:
1. Add `msgid` / `msgstr` pairs to `fr.po` for any new t() string.
2. For new config strings, edit the relevant `language/fr/<config-id>.yml` overlay (e.g. `views.view.swim_test_roster.yml`).
3. Add a new `picc_registration_update_1000N()` function calling `_picc_registration_import_translations()`.
4. `drush cim -y && drush updb -y`.

## Key files

### picc_registration module
- `picc_registration.module` — hooks (cron, form_alter, views_query_alter, query_TAG_alter, preprocess, entity presaves, tokens).
- `picc_registration.install` — install + update hooks, translation importer.
- `picc_registration.routing.yml` — `/swim-test/mark/{commerce_order_item}` (no-show review) and `/swim-test/evaluate/{profile}` (coach evaluation).
- `picc_registration.views.inc` — views_data_alter for `swim_test_action` (commerce_order_item), `participant_swim_action`, and `participant_swim_status_badge` (profile).
- `src/Plugin/WebformHandler/PiccSwimTestRegistrationHandler.php` — under-15 swim test webform.
- `src/Plugin/WebformHandler/PiccSwimAttestationHandler.php` — 15+ attestation webform.
- `src/Plugin/views/field/SwimTestAction.php` — Resolved/Undo on the no-show review (commerce_order_item).
- `src/Plugin/views/field/ParticipantSwimAction.php` — Evaluate button on the roster (profile-based).
- `src/Plugin/views/field/ParticipantSwimStatusBadge.php` — colored badge from `field_swim_status`.
- `src/Form/SwimTestMarkForm.php` — single form serving both routes; profile-mode (Pass/Fail) or order-item-mode (Resolve/Undo) chosen by the route param.
- `translations/fr.po` — French strings for t() in module code.

### Config
- `views.view.swim_test_roster.yml` — coach evaluation view (profile base).
- `views.view.swim_test_no_show_review.yml` — commerce manager no-show review (commerce_order_item base).
- `views.view.participants.yml` — adds `er_swim_participants` and `er_attestation_participants` displays for webform participant lists.
- `views.view.variant_session.yml` — paddling/swim test variant listing, filtered by picc_ext stock hook.
- `webform.webform.swim_test_registration.yml` and `webform.webform.swim_attestation.yml`.
- `commerce_email.commerce_email.swim_test_*.yml` — receipt + PICC notification.
- `field.storage.profile.field_swim_status.yml` — five allowed values, including `failed`.
- `field.storage.profile.field_evaluation_date.yml` — replaces the old `field_swim_test_passed_date`.
- `language/fr/*` — French overlays for all of the above.

### Templates
- `html/themes/custom/daisyui_ext/templates/view/views-view-fields--swim-test-roster.html.twig`
- `html/themes/custom/daisyui_ext/templates/view/views-view-grouping--swim-test-roster.html.twig`
- `html/themes/custom/daisyui_ext/templates/view/views-view-unformatted--swim-test-roster.html.twig`
- Same trio for `swim-test-no-show-review`.
- `html/themes/custom/daisyui_ext/css/safelist.txt` — Tailwind utility allowlist; add and rebuild after introducing new classes.

### picc_ext
- `picc_ext.module` — `picc_ext_views_query_alter()` hides out-of-stock variants from variant_session.
- `src/Plugin/views/field/ParticipantStatusIcons.php` — pool icon reads `field_swim_status`.
