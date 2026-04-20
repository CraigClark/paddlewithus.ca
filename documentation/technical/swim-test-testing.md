# Swim test — testing and troubleshooting

## Manual testing scenarios

### Parent registers under-15 kid

1. Log in as account holder with at least one profile whose birth date is after Dec 31 of (current_year - 15).
2. Visit `/program/swim-test`.
3. Pick an available slot with capacity > 0.
4. Click **Register for this swim test**.
5. Verify checkboxes: under-15 kids appear; adults don't; already-cleared kids are disabled; existing future bookings are disabled with a note.
6. Select participants, click Register.
7. Verify: redirected to product page, confirmation message shown.
8. Check `/admin/commerce/orders` — new order exists, state Completed, total $0, one order item per participant.
9. Check order item: `field_participant` set, `field_swim_test_status = pending`, title includes participant name.
10. Check emails log (`drush watchdog:show --type=symfony_mailer`) — two emails sent (parent + PICC).

### Coach evaluates

1. Log in as coach.
2. Visit `/swim-test-roster`.
3. Verify view shows pending registrations grouped by date, sorted by date then time.
4. Click **Evaluate** beside a participant.
5. Dialog opens showing name, current status, date field.
6. Test Pass: order item → passed, profile `field_swim_status` → passed, date set, `field_swim_test_evaluated_by` → current user.
7. Test Fail: order item → failed, profile unchanged.
8. Test Undo on passed: order item → pending, profile cleared.

### Commerce manager resolves no-show

1. Have an order item with `field_swim_test_status = no_show` (either manually set via drush, or via cron on a past slot).
2. Log in as commerce_manager.
3. Visit `/admin/swim-test-no-shows`.
4. Click **Resolved**.
5. Dialog requires a reason. Submit.
6. Verify: order item status → excused, `field_swim_test_excuse_reason` set.
7. Undo: status → no_show, reason cleared.

### Cron auto-no-show

Backdate a variation's `field_date` and `field_time_range.end_value` to more than 8 hours ago, register a kid with status pending, run `drush cron`, verify status flipped to no_show.

```
ddev drush php:eval "
\$v = \Drupal::entityTypeManager()->getStorage('commerce_product_variation')->load(VARIATION_ID);
\$v->set('field_date', date('Y-m-d', strtotime('-1 day')));
\$utc_end = (new DateTime('-10 hours'))->setTimezone(new DateTimeZone('UTC'));
\$utc_start = (new DateTime('-10 hours -15 minutes'))->setTimezone(new DateTimeZone('UTC'));
\$v->set('field_time_range', [
  'value' => \$utc_start->format('Y-m-d\TH:i:s'),
  'end_value' => \$utc_end->format('Y-m-d\TH:i:s'),
]);
\$v->save();
"
ddev drush cron
```

### Annual reset

Force the state to last year and run cron:

```
ddev drush sset picc_registration.last_swim_reset_year 2025
ddev drush cron
```

Verify all profiles with swim data have been cleared.

## Common issues

### Variant doesn't show on product page

- Check capacity is > 0. Out-of-stock variants are hidden by `picc_ext_views_query_alter()`.
- Check variant is published.
- Clear caches: `ddev drush cr`.

### Handler rejects registration with cryptic error

Check `drush watchdog:show --count=10 --severity=3`. The handler logs detailed error messages.

### SKU is null when creating a variation

The `picc_sku` module auto-generates SKUs via `hook_form_alter()` on all variation bundles. If this breaks, check `picc_sku.module`.

### Email not sending

In ddev, SMTP will time out (no real mail server). This is expected. On production, check `drush watchdog:show --type=symfony_mailer`.

### Translations not updating

After editing `fr.po`, add a new `hook_update_N()` calling `_picc_registration_import_translations()` and run `drush updb`. Just editing the file isn't enough — translations live in the locale tables, not the file.

### "Already registered" block is incorrect

The logic is in `PiccSwimTestRegistrationHandler::getExistingFutureSwimTestRegistration()` and `isRegisteredForSlot()`. Rules:
- Same-slot duplicate → always blocked
- Other future slot with status `pending` → blocked
- Other today/future slot with status NOT pending → not blocked

The form alter callback `_picc_registration_disable_future_swim_registered()` in `picc_registration.module` mirrors this logic for the checkbox disable.

## Field permission reference

Permissions granted per role on swim-related fields:

| Field | Coach | Commerce mgr | Content editor |
|---|---|---|---|
| field_swim_status | edit + view | edit + view | edit + view |
| field_swim_test_passed_date | edit + view | edit + view | edit + view |
| field_swim_test_evaluated_by | edit + view | edit + view | edit + view |
| field_swim_attestation_date | view | edit + view | edit + view |
| field_swim_attestation_by | view | edit + view | edit + view |

Stored in `user.role.*.yml` config files using the `field_permissions` module's custom permission type.
