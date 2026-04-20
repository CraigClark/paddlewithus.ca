# Swim test feature — technical architecture

## Overview

The swim test feature lives across two custom modules:

- **`picc_registration`** — webforms, handlers, forms, views field plugins, cron, emails routing, translations.
- **`picc_ext`** — views query alters (stock filter on variant_session), participant status icons plugin.

Plus:
- Config in `config/sync/` (views, webforms, commerce types, fields, emails, language overrides).
- Theme templates in `html/themes/custom/daisyui_ext/templates/view/`.

## Data model

### Profile (bundle: `participant`) — swim fields

| Field | Type | Purpose |
|---|---|---|
| `field_swim_status` | list_string | Current season clearance. Values: `none`, `passed`, `attested`, `exempt` |
| `field_swim_test_passed_date` | datetime | Date of pool test pass |
| `field_swim_test_evaluated_by` | user reference | Coach who passed them |
| `field_swim_attestation_date` | datetime | Date attestation was submitted |
| `field_swim_attestation_by` | user reference | User who submitted attestation |

All six are cleared on Jan 1 by `_picc_registration_annual_swim_status_reset()`.

Field permissions module restricts edits to coach / commerce_manager / content_editor roles.

### Commerce

- **Product type `activity`** holds both activity variations and swim_test_slot variations.
- **Variation type `swim_test_slot`**:
  - `field_date` — single date, site timezone, date-only precision.
  - `field_time_range` — datetime range, datetime precision, rendered with the `time_range` widget (contrib module). UTC internally.
  - `field_capacity` — commerce_stock_level. Stock service mapped to `local_stock` in `commerce_stock.service_manager`.
  - `field_desc` — optional string.
  - Price is forced to $0 CAD on presave by `picc_registration_commerce_product_variation_presave()`. Hidden from the form.
- **Order item type `swim_test_registration`**:
  - `field_participant` — profile reference, single cardinality (one participant per order item).
  - `field_swim_test_status` — list_string: `pending`, `passed`, `failed`, `no_show`, `excused`.
  - `field_swim_test_excuse_reason` — text_long. Set by commerce manager when resolving no-shows.

### Status decoupling

Order item status (`field_swim_test_status`) is per-registration history. Profile status (`field_swim_status`) is the participant's current season clearance. A kid can fail in May and pass in June — the June order item is `passed`, the May order item stays `failed`, and the profile reflects `passed`.

## Flows

### Parent registers a kid (pool test)

1. Parent visits `/program/swim-test`, sees variants from the `variant_session` view.
2. Out-of-stock variants are hidden by `picc_ext_views_query_alter()`.
3. Click "Register for this swim test" → `/form/swim-test-registration?product=X&variation=Y`.
4. The `swim_test_registration` webform filters participants to under-15s via `er_swim_participants` view display. The birth-date filter is injected dynamically by `picc_registration_views_query_alter()` using Dec 31 of `current_year - 15`.
5. On submit, `PiccSwimTestRegistrationHandler::submitForm()` validates, checks stock with a mutex lock, creates one order item per selected participant, and transitions the order directly to `completed`.
6. `commerce_email` templates `swim_test_receipt` and `swim_test_notify_picc` fire on `order_placed` (filtered to swim_test_slot variation type).

### Parent attests an adult

1. Parent visits the attestation page (content-linked to `/form/swim-attestation`).
2. `swim_attestation` webform filters to 15+ via `er_attestation_participants` view display.
3. On submit, `PiccSwimAttestationHandler::submitForm()` validates and sets `field_swim_status = attested`, `field_swim_attestation_date = today`, `field_swim_attestation_by = current user` on each profile.
4. Two Webform email handlers send notifications (parent + PICC).

### Coach evaluates

1. Coach visits `/swim-test-roster` (`swim_test_roster` view, commerce_manager/coach/content_editor access).
2. `SwimTestAction` views field plugin renders an **Evaluate** button per pending row. Passed/failed rows show a badge + Undo.
3. Click → opens off-canvas dialog at `/swim-test/mark/{commerce_order_item}`, handled by `SwimTestMarkForm`.
4. Pass: updates order item status + profile. Fail: updates order item status only. Undo: resets order item, clears profile fields if applicable.

### Commerce manager resolves a no-show

1. Visits `/admin/swim-test-no-shows` (`swim_test_no_show_review` view, commerce_manager only).
2. `SwimTestAction` plugin renders a **Resolved** button on no_show rows.
3. Click → same `SwimTestMarkForm`. Shows a required reason textarea.
4. On submit, status → `excused`, reason → `field_swim_test_excuse_reason`.
5. Undo flips back to `no_show` and clears the reason.

## Cron (`hook_cron`)

`picc_registration_cron()` calls two helpers on every cron run:

1. **`_picc_registration_annual_swim_status_reset()`** — state-gated. Runs once per year when `current_year > last_swim_reset_year`. Clears all six swim fields on every participant profile. Sets state to current year.

2. **`_picc_registration_auto_no_show_sweep()`** — runs every cron. Finds `swim_test_registration` order items still at `pending` on completed/fulfillment orders. Computes the slot end time by combining `field_date` (site tz) with the UTC time from `field_time_range.end_value`. If slot ended more than 8 hours ago, flips to `no_show`.

## Preventing duplicate registrations

Two layers in `PiccSwimTestRegistrationHandler`:

1. `isRegisteredForSlot($profile_id, $variation_id)` — blocks registering the same participant for the same slot regardless of date.
2. `getExistingFutureSwimTestRegistration($profile_id)` — blocks registering when the participant already has a `pending` registration for today or a future slot. Once the coach marks it passed/failed, the status changes and the parent can re-book.

The form also visually disables checkboxes via `_picc_registration_disable_cleared_participants` (passed/attested/exempt) and `_picc_registration_disable_future_swim_registered` (pending future registrations, with a human-readable note).

## Emails

All swim-test-related emails go through **commerce_email** (for swim_test_slot orders) or **Webform email handlers** (for attestation).

Email configs in `config/sync/commerce_email.commerce_email.swim_test_*.yml` are filtered to the `swim_test_slot` variation type. Activity registration emails are filtered to `activity` variation type. No overlap.

`commerce_email` fires on `order_placed`, which happens when the order transitions to `completed` in the handler.

## Translations

French translations for custom `t()` strings live in `html/modules/custom/picc_registration/translations/fr.po`. Imported automatically by `hook_install()` on install and by incremented `hook_update_N()` functions when strings change.

To add new translations:
1. Add `msgid` / `msgstr` pairs to `fr.po`.
2. Add a new `picc_registration_update_1000N()` function calling `_picc_registration_import_translations()`.
3. Run `drush updb -y`.

## Key files

### Module
- `html/modules/custom/picc_registration/picc_registration.module` — hooks (cron, form_alter, views_query_alter, preprocess, entity presaves, tokens)
- `html/modules/custom/picc_registration/picc_registration.install` — install + update hooks, translation importer
- `html/modules/custom/picc_registration/picc_registration.routing.yml` — `/swim-test/mark/{commerce_order_item}`
- `html/modules/custom/picc_registration/picc_registration.views.inc` — views data_alter for swim_test_action field
- `html/modules/custom/picc_registration/src/Plugin/WebformHandler/PiccSwimTestRegistrationHandler.php`
- `html/modules/custom/picc_registration/src/Plugin/WebformHandler/PiccSwimAttestationHandler.php`
- `html/modules/custom/picc_registration/src/Plugin/views/field/SwimTestAction.php`
- `html/modules/custom/picc_registration/src/Form/SwimTestMarkForm.php`
- `html/modules/custom/picc_registration/translations/fr.po`

### Config
- `views.view.swim_test_roster.yml` — coach evaluation view
- `views.view.swim_test_no_show_review.yml` — commerce manager no-show review
- `views.view.participants.yml` — adds `er_swim_participants` and `er_attestation_participants` displays
- `views.view.variant_session.yml` — existing, filtered by picc_ext stock hook
- `webform.webform.swim_test_registration.yml`
- `webform.webform.swim_attestation.yml`
- `commerce_email.commerce_email.swim_test_receipt.yml`
- `commerce_email.commerce_email.swim_test_notify_picc.yml`
- `language/fr/*` — French overrides for all of the above

### Templates
- `html/themes/custom/daisyui_ext/templates/view/views-view-fields--swim-test-roster.html.twig`
- `html/themes/custom/daisyui_ext/templates/view/views-view-grouping--swim-test-roster.html.twig`
- `html/themes/custom/daisyui_ext/templates/view/views-view-unformatted--swim-test-roster.html.twig`
- Same trio for `swim-test-no-show-review`

### picc_ext
- `html/modules/custom/picc_ext/picc_ext.module` — `picc_ext_views_query_alter()` hides out-of-stock variants from variant_session
- `html/modules/custom/picc_ext/src/Plugin/views/field/ParticipantStatusIcons.php` — pool icon reads `field_swim_status`
