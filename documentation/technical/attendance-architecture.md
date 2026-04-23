# Attendance feature — technical architecture

## Overview

The attendance feature lives in a single custom module:

- **`picc_attendance`** — custom content entity, recorder/access/purger services, coach modal forms, Views field plugin, admin settings form, translations.

Plus:

- Config in `config/sync/` (product field, views, role permissions, module settings).
- Reuses patterns established by `picc_registration` (lock-wrapped writes, `hook_views_query_alter` for dynamic conditions, state-gated cron, translations-on-install).

Attendance applies **only** to paddling programs — the `activity` product type with its `activity` variation type. Swim tests are not tracked here.

## Data model

### Product flag

`field_track_attendance` (boolean) on `commerce_product` bundle `activity`. Default `0`. When `1`, the product's sessions surface on the coach attendance view and attendance actions are available.

Admin edit form includes it inside the existing "About this activity" fieldset.

### Content entity: `picc_attendance`

Revisionable content entity. One row per `(order_item, date)`, enforced by a composite unique index.

| Field | Type | Purpose |
|---|---|---|
| `order_item` | entity_reference → `commerce_order_item` (bundle `activity_registration`) | The registration being tracked. |
| `participant` | entity_reference → `profile` (bundle `participant`) | Denormalized from order item — survives order item edits. |
| `product` | entity_reference → `commerce_product` | Denormalized from the variation — survives order item deletion for audit continuity. |
| `date` | datetime (date-only) | Calendar day, site timezone. Partition key. Drives retention. |
| `check_in_at` | timestamp | Set at button press. Null until checked in. Rendered `Y-m-d H:i`. |
| `check_in_by` | entity_reference → user | Coach who recorded check-in. |
| `check_in_note` | string_long | Optional. |
| `check_out_at` | timestamp | Set at button press. Null until checked out. Rendered `Y-m-d H:i`. |
| `check_out_by` | entity_reference → user | Coach who recorded check-out. |
| `check_out_pickup_name` | string | Resolved name of the person who picked up. |
| `check_out_pickup_source` | list_string | `contact_1` / `contact_2` / `contact_3` / `freetext`. |
| `check_out_note` | string_long | Optional. |
| `checkout_without_checkin` | boolean | 1 if at the moment of check-out, `check_in_at` was null. Reflects the final non-reset state. |
| `revision_*` | standard | Every write creates a revision with a `revision_log_message`. |

### Indexes

Defined in `AttendanceStorageSchema::getEntitySchema()`:

- **Composite unique `(order_item__target_id, date_value)`** — enforces uniqueness and speeds the coach-view lookup.
- **Single-column `date_value`** — drives the 3-year retention purge and history-view date filter.

Entity-reference `target_id` columns on `participant`, `product`, `order_item` are auto-indexed by Drupal.

## Roles and permissions

Defined in `picc_attendance.permissions.yml`:

- **`record picc attendance`** — granted to coach + commerce_manager. Allows the check-in / check-out / reset forms. For coaches, enforcement is strictly *today* within the session window; commerce_managers bypass the window.
- **`view picc attendance history`** — granted to commerce_manager. Allows `/admin/attendance/history` (Views page) and CSV export. Also grants window-bypass on write forms (for corrections).
- **`administer picc attendance`** — granted to administrator (via `is_admin: true`). Allows `/admin/config/picc/attendance`, hard delete, manual purge.

## Access flow

The access-window rule from the plan document is enforced in **two places**:

1. **Route level** — `picc_attendance.access_checker:accessCheckInRoute` (etc.). Runs before the form is reached. Returns 403 for out-of-window direct URL pokes.
2. **Entity level** — `AttendanceAccessControlHandler` returns baseline permission access for view/update/delete.

Both consult the same `AttendanceAccessChecker::canCheckIn()` / `canCheckOut()` / `canReset()` methods so behaviour stays consistent.

### Window rule (v1)

Let `today` = current date in site timezone; `start = field_date_range.value`; `end = field_date_range.end_value`.

| Action | Coach allowed when | Manager/admin allowed when |
|---|---|---|
| Check-in | `start <= today <= end` and product has `field_track_attendance = 1` and order is completed | Any time (within same eligibility) |
| Check-out | Same | Any time |
| Reset | Attendance record's `date` = today | Any time |

Managers bypass the window; admins bypass everything. No hour-level gate — attendance is keyed to the calendar day and coaches can only act on today.

## Flows

### Coach checks in a participant

1. Coach opens `/participants/attendance` (coach attendance view). Page renders rows for today's active programs (date range covers today, product has `field_track_attendance = 1`, order state = `completed`).
2. Each row has an **Attendance action** cell rendered by the `AttendanceAction` Views field plugin. Buttons are `use-ajax` links that open Drupal's dialog at the form routes.
3. Coach taps **Check in** → modal shows `Check in <name>` + optional note field + submit. The AttendanceAccessChecker has already validated the window at route level.
4. On submit, `AttendanceCheckInForm::submitForm()` calls `AttendanceRecorder::recordCheckIn()`:
   - Acquires lock `picc_attendance_{oi_id}_{Y-m-d}` (15s timeout, one retry).
   - Loads today's attendance record if any; otherwise builds a new entity with `order_item`, `participant`, `product`, `date` populated.
   - Sets `check_in_at`, `check_in_by`, `check_in_note`.
   - Saves with new revision + log message `Check-in by <coach>`.
   - Releases lock.
5. Form redirects back to the attendance page. Drupal's dialog.ajax handles the dialog close; the row now renders with the "In HH:MM" badge.

### Coach checks out a participant

1. Coach taps **Check out** → modal shows `Check out <name>`.
2. Emergency contacts derived from the participant profile: include each contact where `field_emercency_contact_N_name` is non-empty AND `field_emercency_contact_N_pickup = 1`. Since the site default is `pickup = 1`, the expected case is all named contacts appear.
3. Radio list of eligible contacts, plus an **Other** option that reveals a required free-text input via `#states`. If zero contacts qualify, the radio widget is hidden and a caution + required free-text input is shown instead.
4. Optional note textarea.
5. On submit, `AttendanceRecorder::recordCheckOut()` sets `check_out_at`, `check_out_by`, `check_out_pickup_name`, `check_out_pickup_source`, `check_out_note`, and `checkout_without_checkin = 1` if `check_in_at` is currently null. Revision log: `Check-out by <coach>; pickup: <name>`.

### Coach resets a slot

1. Coach taps **Reset check-in** or **Reset check-out** → modal requires a short reason.
2. On submit, `AttendanceRecorder::reset()` clears that slot's fields. If the check-in slot is reset while check-out is active, `checkout_without_checkin` is flipped to 1 (reflects final non-reset state). Revision log: `Reset <slot> by <coach>: <reason>`.
3. Prior revision retains the original values. Admin history view surfaces these via revision inspection.

### Admin history review

`/admin/attendance/history` — Views table for commerce_manager + admin. Filters: date range, program, "only checkouts without check-in". CSV export at `/admin/attendance/history/[date:custom:Y-m-d]-picc-attendance.csv`.

### Cron purge

`picc_attendance_cron()` calls `AttendancePurger::runIfDue()` once per cron tick. The purger:

- Bails if `picc_attendance.last_purge_ts` state key is less than ~23 hours old.
- Computes cutoff = `today - retention_days` (default 1095). Site timezone.
- Queries up to 500 records where `date < cutoff` and deletes them (and their revisions).
- Logs `picc_attendance purge: deleted N rows older than YYYY-MM-DD (retention D days)` to watchdog on the `picc_attendance` channel.

## View architecture

### Coach attendance view — `views.view.picc_attendance.yml`

Base: `commerce_order_item`. Relationships: `commerce_product_variation`, `field_participant`, `order_id`.

- Static filters: type = `activity_registration`, order.state = `completed`, language = interface.
- Dynamic filters (injected by `picc_attendance_views_query_alter()`):
  - Variation's `product_id` IN `(SELECT entity_id FROM {commerce_product__field_track_attendance} WHERE field_track_attendance_value = 1)`
  - Variation's `variation_id` IN `(SELECT entity_id FROM {commerce_product_variation__field_date_range} WHERE field_date_range_value <= :today AND field_date_range_end_value >= :today)`
- Exposed filters: program (product_id), participant (first name contains).
- Grouped by program. One display (`attendance_page`) at `/participants/attendance`.
- Role access: coach + commerce_manager.

The plan originally proposed adding a display to the existing `registration` view; during implementation I chose a standalone view file instead. Rationale: clearer diff, no risk to the existing roster, simpler to reason about. The behavioural outcome is identical.

### Admin history view — `views.view.picc_attendance_history.yml`

Base: `picc_attendance_field_data` (the entity's data table).

- Filters: date range, program (numeric in), checkouts-without-checkin boolean.
- Default sort: date DESC.
- Three displays: `default`, `page_1` at `/admin/attendance/history`, `data_export_1` for CSV.
- Permission: `view picc attendance history`.

## File map

```
html/modules/custom/picc_attendance/
├── picc_attendance.info.yml
├── picc_attendance.install                    # translations on install
├── picc_attendance.module                     # hook_cron, hook_views_query_alter
├── picc_attendance.permissions.yml            # 3 permissions
├── picc_attendance.routing.yml                # 7 routes
├── picc_attendance.services.yml               # 3 services + logger channel
├── picc_attendance.links.menu.yml             # admin config + admin list
├── picc_attendance.views.inc                  # registers attendance_action
├── config/install/picc_attendance.settings.yml
├── config/schema/picc_attendance.schema.yml
├── translations/fr.po
└── src/
    ├── AttendanceAccessControlHandler.php
    ├── AttendanceListBuilder.php
    ├── AttendanceStorageSchema.php
    ├── Entity/
    │   ├── Attendance.php                     # @ContentEntityType
    │   └── AttendanceInterface.php
    ├── Form/
    │   ├── AttendanceCheckInForm.php
    │   ├── AttendanceCheckOutForm.php
    │   ├── AttendanceResetForm.php
    │   └── AttendanceSettingsForm.php
    ├── Plugin/views/field/
    │   └── AttendanceAction.php
    └── Service/
        ├── AttendanceAccessChecker.php        # time-window rule + route access
        ├── AttendanceRecorder.php             # lock-wrapped write path
        └── AttendancePurger.php               # 24h-gated cron purge
```

Related config files (in `config/sync/`):

```
field.storage.commerce_product.field_track_attendance.yml
field.field.commerce_product.activity.field_track_attendance.yml
core.entity_form_display.commerce_product.activity.default.yml   # updated
views.view.picc_attendance.yml                                   # new
views.view.picc_attendance_history.yml                           # new
user.role.coach.yml                                              # updated
user.role.commerce_manager.yml                                   # updated
```
