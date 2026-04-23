# Attendance feature — manual test scenarios

The project doesn't use automated tests. These scenarios should all pass after any change to attendance code or config.

Run through them before merging.

## Setup

1. Log in as an admin.
2. Pick (or create) an activity product running today. Edit it, toggle **Track attendance** on, save.
3. Make sure at least one session variation has `field_date_range` covering today.
4. Register a few participants through the normal flow so there are completed orders on that session.

## 1. Coach sees today's roster

- Log in as a user with the `coach` role (only).
- Navigate to `/participants/attendance`.
- Expected: rows for every registered participant on products with `track_attendance = 1` whose session date range covers today.
- Each row shows participant name, status icons (swim / medical / photo), and an Attendance action cell with a **Check in** button.

## 2. Products without the flag are hidden

- Edit a different product, toggle **Track attendance** off.
- Refresh `/participants/attendance`.
- Expected: that product's participants no longer appear.

## 3. Inactive sessions are hidden

- Edit a session variation and set `field_date_range` entirely in the past.
- Refresh.
- Expected: rows for that variation disappear. No over- or under-inclusion.

## 4. Swim test registrations never appear

- Expected: swim test rows never show up on the attendance view (bundle filter `activity_registration`).

## 5. Check in a participant

- Click **Check in** on any row.
- Expected modal: title `Check in <Jane Smith>`, optional note field, blue **Check in** submit.
- Add a note: "Arrived on time." Submit.
- Expected: modal closes, page reloads, row now shows `In HH:MM` badge + `Check out` button + `Reset check-in` link.

## 6. Check out a participant with authorised contacts

- On a checked-in row, click **Check out**.
- Expected modal: radio list of eligible emergency contacts (those with non-empty name AND `pickup = 1`). The site default is `pickup = 1` so named contacts should appear.
- Also present: an **Other** radio that reveals a required text field via `#states`.
- Select one contact. Submit.
- Expected: row shows `Out HH:MM` badge (neutral) + `Reset check-out` link.
- Database: `check_out_pickup_name` = selected contact name, `check_out_pickup_source` = `contact_1`/`contact_2`/`contact_3`.

## 7. Check out using "Other"

- Check out again with a different participant. Select **Other**.
- Expected: text field appears, required.
- Submit empty — expected validation error "Please enter the name of the person picking up."
- Enter "Grandma". Submit.
- Database: `check_out_pickup_name` = `Grandma`, `check_out_pickup_source` = `freetext`.

## 8. Check out with zero authorised contacts

- Temporarily edit a participant's profile and clear all three `field_emercency_contact_N_name` fields (or set all `pickup` flags to 0).
- Start a check-out for that participant.
- Expected: no radio list. A visible warning: "No authorised emergency contacts are on file for <name>. Enter the name of the person picking up manually. This will be flagged in the record."
- Required text field. Enter a name. Submit.
- Database: `check_out_pickup_source` = `freetext`.
- **Restore** the emergency contact data before moving on.

## 9. Check out without prior check-in

- Find a row that has NOT been checked in. Click **Check out** directly.
- Expected: a warning banner in the modal: "This participant was not checked in today. The check-out will be flagged as 'without check-in'."
- Submit.
- Expected: row shows `Out HH:MM (no check-in)` badge (warning-coloured).
- Database: `checkout_without_checkin = 1`.

## 10. Reset check-in

- On a checked-in-only row, click **Reset check-in**.
- Expected modal: short caution + required reason textarea + **Reset** submit.
- Submit without reason — expected validation error.
- Enter "Clicked the wrong participant." Submit.
- Expected: row returns to showing the **Check in** button.
- Admin: viewing the entity reveals revisions listing original check-in + reset.

## 11. Reset check-out

- On a checked-out row, click **Reset check-out**.
- Submit with a reason.
- Expected: row reverts to showing the **Check out** button (if still checked in) or a pristine check-in state (if both were cleared).

## 12. Reset check-in AFTER check-out already recorded

- Full flow: check in, check out, then reset check-in.
- Expected: row now shows `Out HH:MM (no check-in)` — `checkout_without_checkin` flipped to 1.
- Revision history still shows the original check-in was recorded.

## 13. Concurrent coaches

- Open `/participants/attendance` in two browsers as two different coaches.
- Have both attempt to check in the same participant simultaneously.
- Expected: one succeeds. The other either succeeds (no-op on duplicate) or waits briefly. No double-rows — unique index enforces one row per (order_item, date).

## 14. Window enforcement (coach out-of-window)

- Log in as a coach. Copy a check-in URL like `/attendance/check-in/<oi_id>` for a session that is NOT running today.
- Visit directly.
- Expected: 403.

## 15. Window bypass (manager out-of-window)

- Log in as a user with `commerce_manager` role.
- Visit the same out-of-window URL.
- Expected: form loads. Submit works.

## 16. Filters

- Open `/participants/attendance` with multiple programs running today.
- Program dropdown should list active programs. Select one — list narrows.
- Participant name field: type a first name. List narrows.
- Reset clears both.

## 17. Admin history view

- Log in as commerce_manager. Visit `/admin/attendance/history`.
- Expected: table of all attendance records, sorted by date DESC.
- Filter by date range — list narrows.
- Filter by program — list narrows.
- Check "Only show checkouts without check-in" — only flagged rows remain.
- Pagination works at 50/page.

## 18. CSV export

- With any filter applied, visit the CSV export URL (there should be a link auto-generated by views_data_export, or navigate directly to `/admin/attendance/history/YYYY-MM-DD-picc-attendance.csv`).
- Expected: CSV file downloads. Columns: Date, Program, Participant, Check-in, Checked in by, Check-out, Checked out by, Picked up by, Pickup source, Without check-in, Check-in note, Check-out note.
- Timestamps render as `Y-m-d H:i`.

## 19. Retention purge

```
ddev drush sset picc_attendance.last_purge_ts 0
ddev drush eval '
  $storage = \Drupal::entityTypeManager()->getStorage("picc_attendance");
  $old = $storage->create([
    "order_item" => 1, "participant" => 1, "product" => 1,
    "date" => date("Y-m-d", strtotime("-4 years")),
  ]);
  $old->save();
  print "Seeded old row id: " . $old->id() . "\n";
'
ddev drush cron
ddev drush ws --type=picc_attendance --count=3
```

- Expected watchdog entry: `picc_attendance purge: deleted 1 rows older than YYYY-MM-DD (retention 1095 days).`
- Row no longer exists.

## 20. Manual purge now

- Visit `/admin/config/picc/attendance` as admin.
- Click **Run purge now**.
- Expected: status message `Purge complete. N record(s) deleted.`

## Diagnostics

```
# Inspect today's attendance for a given order item
ddev drush eval '
  $today = (new DateTimeImmutable())->format("Y-m-d");
  $ids = \Drupal::entityTypeManager()->getStorage("picc_attendance")->getQuery()
    ->accessCheck(FALSE)
    ->condition("order_item", OI_ID_HERE)
    ->condition("date", $today)
    ->execute();
  print_r($ids);
'

# Dump an attendance entity
ddev drush eval 'print_r(\Drupal::entityTypeManager()->getStorage("picc_attendance")->load(ID_HERE)->toArray());'

# Count attendance records
ddev drush eval 'print \Drupal::entityTypeManager()->getStorage("picc_attendance")->getQuery()->accessCheck(FALSE)->count()->execute();'

# Last purge timestamp
ddev drush sget picc_attendance.last_purge_ts
```
