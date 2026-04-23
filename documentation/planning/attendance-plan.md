# Attendance Feature — Implementation Plan

Status: **implemented**. See implementation notes at the end of this document.
Last updated: 2026-04-22.
Target branch: `program-attendance`.

This document is the authoritative plan for the youth paddling program attendance feature. It captures the discovery findings, the architecture decisions already made in conversation, the concrete implementation steps, and the items explicitly scoped out of v1.

---

## 1. Context

The club already has a registration system for paddling programs (activities) and swim tests, built on Drupal 11 + Commerce + Webform, with two custom modules: `picc_registration` (heavy lifter) and `picc_ext` (utilities/plugins).

We are adding **attendance tracking for paddling programs only** (not swim tests). Coaches need a fast, mobile-friendly interface to check participants in and out at the start and end of each session day. The system must retain attendance history for 3 years and then purge it, while preserving a reliable audit trail for resets and corrections.

---

## 2. Findings (confirmed from code/config)

### 2.1 Registration model

- Product type `activity` holds both paddling programs and swim tests. Differentiation is at the **variation type**:
  - `activity` → order item bundle `activity_registration`, daterange session via `field_date_range` (date-only, no time)
  - `swim_test_slot` → order item bundle `swim_test_registration`, `field_date` + `field_time_range`
- Orders move draft → completed. Only completed orders are considered real registrations.
- Each order item carries `field_participant` → `profile` of bundle `participant`.

### 2.2 Entity chain

```
User (account holder)
  └─ Profile [bundle: participant]
       ├─ field_name (name module — given/family)
       ├─ field_birth_date
       ├─ field_emercency_contact_{1,2,3}_name      [string]
       ├─ field_emercency_contact_{1,2,3}_pickup    [boolean, default 1]
       ├─ field_emercency_contact_{1,2,3}_phone / _rel
       ├─ field_swim_status, field_allergies, field_photo_consent, …

Commerce Order (type: default)
  ├─ uid (account holder)
  ├─ state (draft | completed | canceled | fulfillment)
  └─ Order Items (type: activity_registration | swim_test_registration)
       ├─ field_participant  →  Profile (participant)
       ├─ purchased_entity   →  Commerce Product Variation
       │    └─ (activity)   field_date_range [daterange, datetime_type: date]
       │    └─ (swim)       field_date + field_time_range
       └─ (swim only) field_swim_test_status, field_swim_test_excuse_reason
```

Field name typo `emercency` is real and intentional. Use as-is.

### 2.3 Rosters today

Both rosters are pure Views over `commerce_order_item`:

- Activity roster: [config/sync/views.view.registration.yml](../../config/sync/views.view.registration.yml) — path `/participants/roster`, bundle filter `activity_registration`, order state `completed`, roles `coach` + `commerce_manager`, grouped by product then by date range. Includes a CSV export display.
- Swim test roster: [config/sync/views.view.swim_test_roster.yml](../../config/sync/views.view.swim_test_roster.yml) — path `/participants/swim-test-roster`, bundle `swim_test_registration`, exposed status filter defaulting to `pending`, grouped by date + time.

Non-Views code involved:

- [ParticipantStatusIcons](../../html/modules/custom/picc_ext/src/Plugin/views/field/ParticipantStatusIcons.php) — renders swim/medical/photo icons.
- [SwimTestAction](../../html/modules/custom/picc_registration/src/Plugin/views/field/SwimTestAction.php) — emits **Evaluate/Pass/Fail/Undo** buttons as `use-ajax` dialog links. **This is the exact modal pattern to reuse.**

### 2.4 Reusable patterns

| Pattern | Source | Reused for |
|---|---|---|
| `use-ajax` + `data-dialog-type="dialog"` + `core/drupal.dialog.ajax` | [SwimTestAction.php](../../html/modules/custom/picc_registration/src/Plugin/views/field/SwimTestAction.php) | Check-in / check-out modal buttons |
| Route-loaded form at `/…/{commerce_order_item}` | [picc_registration.routing.yml](../../html/modules/custom/picc_registration/picc_registration.routing.yml), [SwimTestMarkForm.php](../../html/modules/custom/picc_registration/src/Form/SwimTestMarkForm.php) | Attendance forms |
| Views field plugin emitting status + action buttons | [SwimTestAction.php](../../html/modules/custom/picc_registration/src/Plugin/views/field/SwimTestAction.php) | `AttendanceAction` plugin |
| `hook_views_data_alter()` to expose the plugin | [picc_registration.views.inc](../../html/modules/custom/picc_registration/picc_registration.views.inc) | Same, in new module |
| `\Drupal::lock()` acquire / release | [PiccRegistrationHandler::createCommerceOrderWithStockLock()](../../html/modules/custom/picc_registration/src/Plugin/WebformHandler/PiccRegistrationHandler.php) | Per (order_item, date) lock on attendance writes |
| State-gated cron helper | `_picc_registration_annual_swim_status_reset()` in [picc_registration.module](../../html/modules/custom/picc_registration/picc_registration.module) | Nightly 3-year purge |
| Load profile from order item: `->get('field_participant')->entity` | Throughout `picc_registration` | Pickup list derivation |
| Name extraction: `field_name.given + field_name.family` | Throughout | Modal titles |
| Translations (.po + install-hook importer) | [picc_registration.install](../../html/modules/custom/picc_registration/picc_registration.install) | French strings |

### 2.5 Roles

- `coach` — toolbar + administration pages access, swim field edits, taxonomy term CRUD. Will gain attendance permissions.
- `commerce_manager` — full commerce admin, profile management, order management.
- `administrator` — full access.
- No `instructor` role exists; not adding one.

### 2.6 Config workflow

Standard `drush cex` / `drush cim`. No recipes. `config_split` used only for dev/prod Stripe keys. No automated tests — manual scenario testing only.

---

## 3. Decisions (locked in)

These are the answers to the open questions and risks raised during discovery. They are final for v1.

1. **Active today filter** = date-range only. `field_date_range.value <= today AND field_date_range.end_value >= today`. Weekday scheduling is out of scope (see §10).
2. **`field_audience`** stays on the product, unused by attendance. Attendance uses the new boolean `field_track_attendance` on the product.
3. **Time-boxing rule**:
   - Check-in allowed: today is within `[start_date, end_date]`, any time of day.
   - Check-out allowed: today is within `[start_date, end_date]`, any time of day. No next-day grace.
   - Reset allowed (coach): attendance record's `date` equals today.
   - Admin / commerce_manager: always.
   - Enforcement: route `_custom_access` + form `validateForm()`.
   - No new time-of-day field — coaches cannot backfill previous days, so the view stays to today-only.
4. **Timestamps captured at the moment of the button press.**
   - `check_in_at` and `check_out_at` are Drupal timestamp fields (Unix int).
   - Views formatter renders both as `Y-m-d H:i` (e.g. `2026-06-06 08:54`) for both in-browser display and CSV export.
5. **Managers view-only** on history. Admins can edit. Coaches never see historical data.
6. **AJAX row refresh** preferred on modal submit; fallback to submit-and-reload (swim test pattern) if fiddly.
7. **Views CSV export** is the only export channel. No scheduled external export.
8. **Retention = 3 years from `date`**, not from `created`. Purge query: `date < today - 3 years`.
9. **Revisions on writes only.** Check-in, check-out, and reset each create a revision with a revision log message. No read auditing.
10. **Resets by coach are allowed** on their own actions without manager approval. Revision log is the audit mechanism.
11. **Emergency-contact default of `1`** (authorised unless explicitly unchecked) is intentional. The check-out modal lists every contact with a non-empty name and `pickup = 1`. If zero qualify, a free-text field with a visible caution is shown.
12. **`checkout_without_checkin`** reflects the final non-reset state. If a coach checks in, resets it, then checks out, the flag is set to 1. Revision history still shows the original check-in.
13. **Coach is the only new role.** No instructor / assistant distinction.
14. **Coach view shows all active programs for the day.** Disambiguation via exposed filters (program, participant name). No required self-selection step.

---

## 4. Architecture

### 4.1 `track_attendance` flag

Add boolean **`field_track_attendance`** on `commerce_product` type `activity`. Default `0`. Exposed in the product admin form. Attendance features only activate for products where this is `1`.

### 4.2 Storage model — custom content entity

New content entity type `picc_attendance`, revisionable. One row per `(order_item, date)`, enforced by a composite unique index.

Rejected alternatives (see discovery report for rationale): custom DB table (loses Views / permissions / UI), webform submissions (wrong shape), multi-value fields on order item or profile (cardinality + lifetime mismatch).

### 4.3 Coach UI

- New view display `attendance_page` added to [config/sync/views.view.registration.yml](../../config/sync/views.view.registration.yml) at path `/participants/attendance`.
- Bakes in filters: `type = activity_registration`, `order.state = completed`, product `field_track_attendance = 1`, `field_date_range` active today.
- Exposed filters: program (selective product filter), participant name (name filter).
- Relationship to the attendance entity for today; one new Views field plugin `AttendanceAction` renders the status badge + buttons per row.
- Roles: `coach`, `commerce_manager`.

### 4.4 Modals / forms

All route-loaded, use `use-ajax` + `data-dialog-type="dialog"`:

- `/attendance/check-in/{commerce_order_item}` → `AttendanceCheckInForm` (title `Check in Jane Smith`, optional note, submit `Check in`).
- `/attendance/check-out/{commerce_order_item}` → `AttendanceCheckOutForm` (title `Check out Jane Smith`, radio of eligible pickup contacts + "Other" freetext + caution, optional note, submit `Check out`).
- `/attendance/reset/{picc_attendance}/{slot}` where `{slot}` is `check_in` or `check_out` → `AttendanceResetForm` (required reason, submit `Reset`).

Writes go through an `AttendanceRecorder` service that acquires a 15-second lock named `picc_attendance_{oi_id}_{date}` (pattern from `PiccRegistrationHandler::createCommerceOrderWithStockLock`).

### 4.5 Resets

Never delete. A reset creates a new entity revision with the relevant timestamp cleared, the action's fields cleared, and a `revision_log_message` like `"Reset check-in by Coach A: <note>"`. Prior revision remains for audit. The admin history view can surface records where `revision_count > 1`.

### 4.6 Retention & purge

Nightly `hook_cron()` helper (state-gated so it runs at most once per 24h):
- Query: `date < today - retention_days` (default `1095`).
- Batch-delete (500/run) including all revisions.
- Log `rows_purged` + `last_date_retained` to watchdog on the `picc_attendance` channel.
- Retention stored in module config, tunable from `/admin/config/picc/attendance`.
- Admin config page exposes a "Run purge now" button for ad-hoc runs.

### 4.7 Access surfaces

| Role | Coach attendance view | Modal forms | Admin history view | Admin config | Hard delete |
|---|---|---|---|---|---|
| coach | Yes | Yes (today only) | No | No | No |
| commerce_manager | Yes | Yes (can bypass window) | Yes | No | No |
| administrator | Yes | Yes | Yes | Yes | Yes |

---

## 5. Data Model

### 5.1 New field on product

| Field | Type | Cardinality | Default | Notes |
|---|---|---|---|---|
| `field_track_attendance` | boolean | 1 | 0 | On `commerce_product` bundle `activity`. Translatable: false. |

### 5.2 `picc_attendance` entity

Revisionable content entity. One row per `(order_item, date)`.

| Field | Type | Required | Notes |
|---|---|---|---|
| `id` | auto int | — | Primary key. |
| `uuid` | uuid | — | Standard. |
| `revision_id`, `revision_created`, `revision_user`, `revision_log_message` | standard | — | Revisions enabled. Every write (check-in, check-out, reset) creates one. |
| `order_item` | entity_reference → `commerce_order_item` | yes | Target bundle `activity_registration` only. |
| `participant` | entity_reference → `profile` (bundle `participant`) | yes | Denormalized from order item at create time for query speed and survival if order item is later edited. |
| `product` | entity_reference → `commerce_product` | yes | Denormalized from variation → product at create time. Survives order item deletion for audit continuity. |
| `date` | datetime (date-only) | yes | Site-tz date at creation. Partition key. Drives retention purge. |
| `check_in_at` | timestamp | no | Set at button press via `\Drupal::time()->getRequestTime()`. Rendered as `Y-m-d H:i`. |
| `check_in_by` | entity_reference → user | no | |
| `check_in_note` | text_long | no | Optional. |
| `check_out_at` | timestamp | no | Set at button press. Rendered as `Y-m-d H:i`. |
| `check_out_by` | entity_reference → user | no | |
| `check_out_pickup_name` | string | no | Resolved from selected emergency contact or freetext. |
| `check_out_pickup_source` | list_string | no | Values: `contact_1`, `contact_2`, `contact_3`, `freetext`. |
| `check_out_note` | text_long | no | Optional. |
| `checkout_without_checkin` | boolean | no | 1 if at the moment of check-out `check_in_at IS NULL` (regardless of earlier resets). |
| `created`, `changed` | standard | — | |

### 5.3 Indexes

Added in `AttendanceStorageSchema::getEntitySchema()`:

- Composite unique: `(order_item__target_id, date)` — enforces uniqueness and speeds the coach-view join.
- Single-column: `date` — drives retention query and admin history date filter.
- Single-column: `participant__target_id`, `product__target_id` — auto-indexed by Drupal as entity_reference columns; confirmed adequate for history view filtering.

### 5.4 Permissions

Defined in `picc_attendance.permissions.yml`:

- `record picc attendance` — granted to coach (+ higher). Allows the check-in / check-out / reset forms within the time window.
- `view picc attendance history` — granted to commerce_manager, administrator. Allows the admin history view.
- `administer picc attendance` — granted to administrator. Allows config page, retention edits, hard deletes, window bypass.

---

## 6. Scale analysis

At projected peak (150 kids × ~78 days × 3 years ≈ **35k rows**, plus ~100k revision rows in a separate table):

- MySQL/MariaDB handles this trivially with the indexes above.
- Coach view queries **today only** and at most ~150 rows regardless of historical volume — constant-time after indexes.
- Admin history view paginates 50/page; indexed filters on `date`, `product`, `participant` are sub-100ms at this scale.
- CSV export via `views_data_export` streams batched; 35k rows well within tested bounds.
- 3-year purge caps growth.
- Design would need revisiting at ~10× volume or 10+ year retention (would introduce an archive table). Not a v1 concern.

Cron logs `rows_purged` + `last_date_retained` on every sweep so silent purge failures can be caught.

---

## 7. Implementation Plan

### 7.1 Field add

1. Add storage + field config for `field_track_attendance` on `commerce_product` type `activity`.
2. Add to the product default form display with a sensible weight (near `field_audience`).
3. Export to `config/sync`.

### 7.2 New module: `picc_attendance`

Location: `html/modules/custom/picc_attendance/`. Files:

- `picc_attendance.info.yml` — core_version_requirement `^11`; dependencies: `picc_registration`, `commerce:commerce`, `commerce_order`, `commerce_product`, `profile`, `views`, `options`, `datetime`, `user`.
- `picc_attendance.module` — implements:
  - `hook_cron()` — purge sweep (state-gated 24h).
  - `hook_views_data_alter()` — register `AttendanceAction` Views field on `commerce_order_item`.
  - `hook_ENTITY_TYPE_view_alter()` if needed for minor row rendering.
- `picc_attendance.permissions.yml` — three permissions as listed in §5.4.
- `picc_attendance.routing.yml` — check-in, check-out, reset, admin config, admin history.
- `picc_attendance.links.menu.yml` — admin config link under `/admin/config/picc/attendance`.
- `picc_attendance.install` — translations import hook (copy helper from picc_registration). Entity schema is auto-generated from the entity definition.
- `picc_attendance.services.yml` — the recorder service, an access checker service for the time-window rule.
- `picc_attendance.views.inc` — registers the `AttendanceAction` plugin.

### 7.3 Entity code

Under `src/Entity/`, `src/Form/`, `src/`, `src/Plugin/`:

- `src/Entity/Attendance.php` — `@ContentEntityType` annotation:
  - `id = "picc_attendance"`
  - `label`, `label_singular`, `label_plural`, `label_collection`
  - `handlers`: `view_builder`, `list_builder = AttendanceListBuilder`, `views_data = EntityViewsData`, `access = AttendanceAccessControlHandler`, `form: default = AttendanceForm`, `delete = AttendanceDeleteForm`, `storage_schema = AttendanceStorageSchema`
  - `base_table = "picc_attendance"`, `revision_table = "picc_attendance_revision"`
  - `revisionable = TRUE`
  - `admin_permission = "administer picc attendance"`
  - `entity_keys`: `id`, `revision`, `uuid`, `label = "participant"`
  - `links`: canonical at `/admin/attendance/{picc_attendance}`, delete at `/admin/attendance/{picc_attendance}/delete`
  - `baseFieldDefinitions()` method defines all fields in §5.2.
- `src/Entity/AttendanceInterface.php`.
- `src/AttendanceAccessControlHandler.php` — returns access based on operation + user permissions + time-window rule (for non-admins on write ops).
- `src/AttendanceStorageSchema.php` — overrides `getEntitySchema()` to add the composite unique index and `date` index.
- `src/AttendanceListBuilder.php` — admin list at `/admin/attendance`.
- `src/Form/AttendanceForm.php` — admin edit form.
- `src/Form/AttendanceDeleteForm.php` — admin delete confirmation.

### 7.4 Coach modal forms

- `src/Form/AttendanceCheckInForm.php`:
  - Loads `{commerce_order_item}` from the route parameter.
  - Validates bundle = `activity_registration`, product has `field_track_attendance = 1`, window check via the access service.
  - Builds form with participant name in title, optional note textarea, submit `Check in`.
  - On submit: acquires per-`(order_item, today)` lock; loads-or-creates entity; sets `check_in_at`, `check_in_by`, `check_in_note`; saves with revision log `"Check-in by {coach}"`.
  - Returns `AjaxResponse` with `CloseDialogCommand` + `ReplaceCommand` for the Views row; falls back to redirect-to-page if row refresh is fiddly.
- `src/Form/AttendanceCheckOutForm.php`:
  - Same loading and validation.
  - Derives pickup list: iterate over `field_emercency_contact_{1,2,3}_name` / `_pickup`, include contacts where name is non-empty AND pickup is truthy.
  - Render: radio list of qualifying contacts (label = name), a final "Other" radio that reveals a required freetext input. If zero contacts qualify, only the freetext is shown, with a visible caution.
  - Optional note textarea.
  - On submit: computes `check_out_pickup_name` + `check_out_pickup_source`; computes `checkout_without_checkin` based on current `check_in_at` being null (ignoring revision history); sets `check_out_at`, `check_out_by`, `check_out_note`; saves with revision log `"Check-out by {coach}; pickup: {name}"`.
- `src/Form/AttendanceResetForm.php`:
  - Takes `{picc_attendance}` and `{slot}` ∈ `check_in` | `check_out`.
  - Requires reason.
  - Clears the slot's `_at`, `_by`, `_note`, and (for check_out slot) `_pickup_name`, `_pickup_source`, `checkout_without_checkin`.
  - Saves with revision log `"Reset {slot} by {coach}: {reason}"`.

### 7.5 Views field plugin

- `src/Plugin/views/field/AttendanceAction.php` — mirrors the structure of `SwimTestAction`.
  - Reads the order item + joined attendance entity (if any).
  - Renders one of:
    - No record → `Check in` button.
    - Checked in, not checked out → `Check out` + `Reset check-in`.
    - Checked out → status badge + `Reset check-out`.
    - Checked out without prior check-in → badge `"Checked out (no check-in)"` + reset.
  - All buttons are `use-ajax` links with `data-dialog-type="dialog"` and `data-dialog-options='{"width":420}'`.
  - Attaches `core/drupal.dialog.ajax`.
- Registered via `hook_views_data_alter()` in `picc_attendance.views.inc`, matching the registration pattern from [picc_registration.views.inc](../../html/modules/custom/picc_registration/picc_registration.views.inc).

### 7.6 Views changes

**Coach attendance display** — add to [config/sync/views.view.registration.yml](../../config/sync/views.view.registration.yml):

- New display `attendance_page`, display type `page`, path `/participants/attendance`, menu entry `Attendance`.
- Inherits base relationships (`commerce_product_variation`, `field_participant`, `order_id`).
- Adds relationship from variation → product to reach `field_track_attendance`.
- Adds LEFT relationship from order item → `picc_attendance`, constrained to `date = [date:custom:Y-m-d]`.
- Filters (on top of inherited):
  - `product.field_track_attendance = 1`
  - `variation.field_date_range.value <= [date:custom:Y-m-d]`
  - `variation.field_date_range.end_value >= [date:custom:Y-m-d]`
- Exposed filters: program (selective product filter, reusing inherited pattern), participant name (name filter).
- Fields: participant name, status icons, `AttendanceAction` field.
- Grouping: by program only (no date subgroup — everything is today).
- Roles: `coach`, `commerce_manager`.

**Admin history view** — new file `config/sync/views.view.picc_attendance_history.yml`:

- Base: `picc_attendance`.
- Path `/admin/attendance/history`.
- Roles: `commerce_manager`, `administrator`.
- Fields: `date`, program (via `product` relationship), participant (via `participant` relationship), `check_in_at` (formatted `Y-m-d H:i`), `check_in_by`, `check_out_at` (formatted `Y-m-d H:i`), `check_out_by`, `check_out_pickup_name`, `check_out_pickup_source`, `checkout_without_checkin`, notes.
- Filters: date range, program, participant, "resets only" (derived from revision count > 1 — may require a small computed Views field).
- Pagination: 50 per page.
- Additional display: CSV export at `/admin/attendance/history/[date:custom:Y-m-d]-picc-attendance.csv` using views_data_export.

### 7.7 Services

- `picc_attendance.recorder` → `AttendanceRecorder`:
  - `recordCheckIn(OrderItemInterface $oi, array $values): Attendance`
  - `recordCheckOut(OrderItemInterface $oi, array $values): Attendance`
  - `reset(Attendance $a, string $slot, string $note): Attendance`
  - Wraps a `\Drupal::lock()` section; emits watchdog log entries on the `picc_attendance` channel.
- `picc_attendance.access_checker` → `AttendanceAccessChecker`:
  - `canCheckInToday(OrderItemInterface $oi, AccountInterface $user): AccessResult`
  - `canCheckOutToday(OrderItemInterface $oi, AccountInterface $user): AccessResult`
  - `canResetToday(Attendance $a, AccountInterface $user): AccessResult`
  - Admins / commerce_managers bypass the time-window rule.
- Both injected via DI into forms and the Views field plugin.

### 7.8 Cron / purge

- `picc_attendance_cron()` in `picc_attendance.module` calls `_picc_attendance_purge_sweep()`.
- State key `picc_attendance.last_purge_ts`; bail if `< 24h` ago.
- `entity.query('picc_attendance')->condition('date', $cutoff, '<')->range(0, 500)->execute()`; load and delete.
- Retention stored in `picc_attendance.settings:retention_days` (default `1095`).
- Watchdog log: `"picc_attendance purge: {count} rows deleted, last date retained {date}"`.

### 7.9 Admin surfaces

- `/admin/config/picc/attendance` — `AttendanceSettingsForm`: retention days field, "Run purge now" submit button (with confirm), last-purge timestamp + row count readout.
- `/admin/attendance` — entity list builder (filtered list of all records, admin-only).
- `/admin/attendance/history` — the Views page above.
- `/admin/attendance/{picc_attendance}` — admin view with revision log tab.
- `/admin/attendance/{picc_attendance}/delete` — admin hard delete, logged.

### 7.10 Config export

Run `drush cex` after scaffolding. Expected new/changed files in `config/sync`:

- `field.storage.commerce_product.field_track_attendance.yml`
- `field.field.commerce_product.activity.field_track_attendance.yml`
- `core.entity_form_display.commerce_product.activity.default.yml` (field placement)
- `user.role.coach.yml` (new permission)
- `user.role.commerce_manager.yml` (new permission)
- `user.role.administrator.yml` (new permissions)
- `views.view.registration.yml` (new `attendance_page` display)
- `views.view.picc_attendance_history.yml` (new file)
- `picc_attendance.settings.yml` (new file with retention default)

### 7.11 Translations

- `html/modules/custom/picc_attendance/translations/fr.po` with French strings for all user-facing labels, placeholders, messages.
- `picc_attendance_install()` calls `_picc_attendance_import_translations()` — copy helper from [picc_registration.install](../../html/modules/custom/picc_registration/picc_registration.install).
- Future updates follow the same pattern: add strings, new update hook, `drush updb`.

### 7.12 Documentation updates

New files matching the existing structure:

- `documentation/technical/attendance-architecture.md`
- `documentation/technical/attendance-deployment.md`
- `documentation/technical/attendance-testing.md`
- `documentation/user/attendance-overview.md`
- `documentation/user/attendance-coaches.md`

### 7.13 Manual test scenarios (to be captured in attendance-testing.md)

- Product with `field_track_attendance = 0`: no attendance action buttons, not in coach view.
- Product with `field_track_attendance = 1`, session active today: participant appears, `Check in` button visible.
- Check-in button click: modal opens with correct title, note saves, row updates.
- Check-out: radio shows only contacts with non-empty name AND pickup = 1; "Other" reveals freetext; zero-contact case shows caution + freetext only.
- Check-out before check-in: badge shows "Checked out (no check-in)", `checkout_without_checkin = 1`.
- Reset check-in then check out: final state shows `checkout_without_checkin = 1`; revision history still shows original check-in.
- Coach outside time window (direct URL): 403.
- Admin outside time window: allowed.
- Concurrent check-ins on same registration: second write blocked by lock, retried.
- Cron purge at 3-year boundary: seeded data older than cutoff deleted; newer retained; watchdog logs count.
- Non-coach role: no access to coach view path.
- Swim test order items: never appear on attendance page (bundle filter).

---

## 8. Level of Effort

Sizing convention: S = 0.5–1d, M = 1–2d, L = 3–5d.

| Area | LoE | Rationale |
|---|---|---|
| `field_track_attendance` add + config export | S | Single field + UI tweak. |
| Module scaffolding (info, module, permissions, routing, services.yml) | S | Boilerplate, pattern-matched. |
| Content entity + schema + revisions + access handler + list builder | L | Most of the risk; unique index, access rules across three roles, revision wiring. |
| `AttendanceRecorder` + lock pattern | M | Mirrors existing pattern; adds validation. |
| Check-in form + route + AJAX close | S | Mirrors `SwimTestMarkForm`. |
| Check-out form + emergency-contact radio + fallback | M | Contact derivation + Other-reveal + zero-contact path. |
| Reset form + revision log | S | Small form, most logic in recorder. |
| `AttendanceAction` Views field plugin | S | Clones `SwimTestAction`. |
| Attendance view display (new `attendance_page` on `registration` view) | M | Relationship + filter tuning; date-aware join. |
| Admin history Views + CSV export | S | Standard Views page. |
| Admin config page + retention controls | S | ConfigFormBase. |
| Cron purge sweep | S | Pattern reused. |
| Access checker service + time-window rule | S | Small, clean. |
| Translations (.po + install hook) | S | Pattern reused. |
| Documentation (5 markdown files) | M | Conventions already set. |
| Manual test pass | M | End-to-end scenarios above. |
| Integration polish buffer | S–M | UI nits always surface in browser testing. |

**Total: ~12–18 developer-days.** The two biggest chunks are the entity (L) and the check-out form + view display (M each). Everything else is pattern-matched.

---

## 9. Risks & Open Items

### 9.1 Remaining risks

- **Views row AJAX refresh** after modal submit may not be trivial. Fallback to submit-and-reload is acceptable per decision 6. Not a blocker.
- **Registration changes mid-program** (a parent cancels mid-session): the order flips to `canceled` and the row leaves the attendance list, but prior attendance records remain for audit. The admin history view joins to the current order state so these orphans are visible there.
- **Field-permissions module** currently guards swim fields. Attendance notes live on the attendance entity, not on the participant profile, so there's no collision. Keep it that way.

### 9.2 Non-blockers for implementation

- Whether the admin history view should default-hide records whose underlying order has since been canceled. Proposed default: show them with a flag column, because audit. Confirm during implementation review.
- Exact pagination page size on admin history. Proposed 50; revisit after seeing real volume.

---

## 10. Out of scope (v2+)

Explicitly deferred:

- Weekday-specific scheduling (e.g. "Dragon boat meets Tue/Thu only"). v1 over-includes on non-meeting days and lets coaches ignore them.
- Hour-level session time gating. No structured time data exists on activity variations; `field_schedule` is free-form prose. If needed later, add an optional `field_daily_time_range` on the variation.
- Coach-initiated backfill of previous days. v1 is today-only to keep the view small.
- Instructor / assistant coach distinct role.
- Scheduled external export (e.g. nightly S3 dump).
- Read auditing. Revisions cover writes only.
- Archive table for >3-year or >10× volume. Revisit only if retention or scale grows substantially.
- Read-only export of historical attendance for a departed member (data subject access request). Can be served ad hoc from the history view for now.

---

## 11. References

### Existing files to study while implementing

- [html/modules/custom/picc_registration/src/Plugin/views/field/SwimTestAction.php](../../html/modules/custom/picc_registration/src/Plugin/views/field/SwimTestAction.php) — exact modal-button pattern.
- [html/modules/custom/picc_registration/src/Form/SwimTestMarkForm.php](../../html/modules/custom/picc_registration/src/Form/SwimTestMarkForm.php) — route-loaded form pattern.
- [html/modules/custom/picc_registration/picc_registration.routing.yml](../../html/modules/custom/picc_registration/picc_registration.routing.yml) — route definition reference.
- [html/modules/custom/picc_registration/picc_registration.views.inc](../../html/modules/custom/picc_registration/picc_registration.views.inc) — `hook_views_data_alter()` for Views field plugin registration.
- [html/modules/custom/picc_registration/picc_registration.module](../../html/modules/custom/picc_registration/picc_registration.module) — `hook_cron()`, state-gated helpers.
- [html/modules/custom/picc_registration/picc_registration.install](../../html/modules/custom/picc_registration/picc_registration.install) — translation import helper to copy.
- [html/modules/custom/picc_registration/src/Plugin/WebformHandler/PiccRegistrationHandler.php](../../html/modules/custom/picc_registration/src/Plugin/WebformHandler/PiccRegistrationHandler.php) — `\Drupal::lock()` usage.
- [html/modules/custom/picc_ext/src/Plugin/views/field/ParticipantStatusIcons.php](../../html/modules/custom/picc_ext/src/Plugin/views/field/ParticipantStatusIcons.php) — simpler Views field plugin example.
- [config/sync/views.view.registration.yml](../../config/sync/views.view.registration.yml) — the view to extend.
- [config/sync/views.view.swim_test_roster.yml](../../config/sync/views.view.swim_test_roster.yml) — sibling view with the modal pattern already wired in.

### Module targets

- `html/modules/custom/picc_attendance/` — new module root.
- `config/sync/` — config export target.
- `documentation/technical/` and `documentation/user/` — documentation targets.

---

---

## 12. Implementation notes (post-build)

Everything in sections 1–11 was implemented as planned, with one material deviation and one minor addition:

### Deviation: separate view instead of extending `views.view.registration.yml`

The plan (§1.4) proposed adding an `attendance_page` display to the existing registration view. During implementation I created a standalone file `config/sync/views.view.picc_attendance.yml` instead.

**Why:** the registration view YAML is ~2400 lines with heavy BEF + field customisations; overlaying a display on top risked silent regressions to the existing roster. A separate view file is ~500 lines, reviewable as a single diff, and semantically identical. Behaviour matches the plan.

The admin history view is also its own file (`views.view.picc_attendance_history.yml`), as originally proposed.

### Addition: manager window bypass

Plan §4.7 says commerce_managers can use the modal forms "(can bypass window)". To implement this cleanly without a fifth permission, `AttendanceAccessChecker` bypasses the window for anyone with `view picc attendance history` OR `administer picc attendance`. Commerce managers get both `record picc attendance` and `view picc attendance history`; the effect is that they can correct attendance records from any day. Coaches, with only `record picc attendance`, remain today-only.

### What's shipping

- `field_track_attendance` boolean on `commerce_product` type `activity` (product form).
- New module `picc_attendance` (entity, storage schema, access control handler, list builder, 3 services, 4 forms, 1 Views field plugin, cron, 3 permissions, French translations).
- New view `picc_attendance` at `/participants/attendance` (coach-facing).
- New view `picc_attendance_history` at `/admin/attendance/history` (manager + admin).
- New admin config at `/admin/config/picc/attendance` (retention + manual purge).
- Documentation: `documentation/technical/attendance-{architecture,deployment,testing}.md` and `documentation/user/attendance-{overview,coaches}.md`.

### Next steps

1. Review the diff.
2. `ddev drush en picc_attendance -y` on a dev environment.
3. `ddev drush cim -y` to import the product field, views, and role permissions.
4. Walk through the manual test scenarios in `documentation/technical/attendance-testing.md`.
5. Turn on **Track attendance** on one real program to pilot.

---

*End of plan.*
