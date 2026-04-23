# Attendance feature — deployment

## Prerequisites

- `picc_registration` must already be installed. `picc_attendance` declares it as a dependency because it relies on the shared participant / order-item / variation data model.
- `config_split` and `config_ignore` workflows already in use. The attendance feature adds config only to the main `config/sync/` folder — no dev/prod splits needed.

## First-time install

**Order matters.** The roles and views in `config/sync` declare `picc_attendance` as a module dependency. `drush cim` will refuse to import them until the module is enabled. If you run `cim` first you will get:

```
Configuration user.role.coach depends on the PICC Attendance module that will not be installed after import.
```

Do the steps in this order:

1. Pull the code containing `html/modules/custom/picc_attendance/` and all `config/sync/` changes.
2. **Enable the module first** — this creates the `picc_attendance` + `picc_attendance_revision` tables:
   ```
   ddev drush en picc_attendance -y
   ```
3. **Then import configuration** — the product field, views, role permission updates, module settings:
   ```
   ddev drush cim -y
   ```
4. Run any pending updates + clear cache:
   ```
   ddev drush updb -y
   ddev drush cr
   ```
5. Verify:
   ```
   ddev drush eval 'print (int) Drupal::database()->schema()->tableExists("picc_attendance");'  # → 1
   ddev drush eval 'print (int) Drupal::database()->schema()->tableExists("picc_attendance_revision");'  # → 1
   ```

### If install crashes mid-way

If `drush en picc_attendance` WSODs (the module is in `core.extension` but the tables didn't get created), clean up before retrying:

```
ddev drush pmu picc_attendance -y    # harmless error if never fully enabled
ddev drush cr
```

Then fix the underlying error and re-run the install sequence from step 2.

## Subsequent deploys

Standard flow:

```
ddev drush cim -y
ddev drush updb -y
ddev drush cr
```

If you add translatable strings:

1. Add `msgid`/`msgstr` to `html/modules/custom/picc_attendance/translations/fr.po`.
2. Add a new update hook in `picc_attendance.install`:
   ```php
   function picc_attendance_update_1000N() {
     _picc_attendance_import_translations();
     return 'Re-imported translations from picc_attendance/translations/*.po.';
   }
   ```
3. Run `ddev drush updb -y`.

## Enabling attendance on a program

1. Edit the Commerce product (admin/commerce/products).
2. In "About this activity", toggle **Track attendance** on.
3. Save.
4. The product's active sessions will now appear on `/participants/attendance` for coaches.

No changes needed for existing sessions — the flag is product-scope, not variation-scope. Turning it on immediately applies to all current and future session variations.

## Retention

- Default retention: **3 years** (1095 days).
- Configurable at `/admin/config/picc/attendance`. Min 30, max 3650 days.
- Purge runs once per 24h via cron (first cron tick after 23h have elapsed). A "Run purge now" button on the settings form bypasses the gate.
- Every run logs `picc_attendance purge: deleted N rows older than YYYY-MM-DD (retention D days)` to watchdog on the `picc_attendance` channel. Monitor this if you need to confirm the purge is running.

## Data volume

At projected peak (150 participants × ~78 days × 3 years ≈ 35k rows) the tables are small for MySQL/MariaDB. Indexes:

- Composite unique on `(order_item, date)` — enforces one row per registration per day + speeds the coach-view join.
- Single-column on `date` — drives the purge query and admin history date filter.

Revisions live in a separate table (`picc_attendance_revision`), so entity queries aren't affected by revision volume.

## Disabling (if needed)

```
ddev drush pmu picc_attendance -y
```

Uninstall is destructive — it drops both `picc_attendance` and `picc_attendance_revision` tables and loses all historical attendance data. Export the admin history CSV first if you need to preserve records.

The product field `field_track_attendance` is unrelated to the module and will remain until removed manually.

## Troubleshooting

**Attendance buttons don't appear on coach view**
- Product must have `field_track_attendance = 1`.
- Session variation's `field_date_range` must cover today.
- Order must be in `completed` state.
- User must have `record picc attendance` permission.

**Coach can't check in — 403**
- Window rule: check session date range covers today in site timezone.
- Only commerce_manager + admin can bypass the window.

**Purge doesn't run**
- Check `ddev drush sget picc_attendance.last_purge_ts` — should be within last 24h.
- Force with "Run purge now" button at `/admin/config/picc/attendance`.
- Check watchdog: `ddev drush ws --type=picc_attendance`.

**Duplicate writes**
- Per-`(order_item, date)` lock with 15s timeout + retry. If contention persists, a `RuntimeException` is thrown and surfaced as a form error. Rare in practice — means two coaches hit the same participant within <15s.

## Related modules

- `picc_registration` — parent registration flow, source of `field_participant` and order item types.
- `picc_ext` — no direct dependency; `ParticipantStatusIcons` Views field plugin is reused on the coach attendance view.
