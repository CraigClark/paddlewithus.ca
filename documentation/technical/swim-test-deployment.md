# Swim test — deployment notes

## First deploy to a new environment

1. Ensure these modules are installed:
   - `picc_registration` (custom)
   - `picc_ext` (custom)
   - `picc_sku` (custom)
   - `time_range` (contrib)
   - `commerce_stock` + `commerce_stock_local`
   - `commerce_email`
   - `better_exposed_filters`
   - `webform`
   - `field_permissions`
   - `locale` (for translation import)

2. Run config import: `drush cim -y`.

3. Run database updates: `drush updb -y`.
   - `picc_registration_update_10001` — backfills `field_swim_status = none` on existing participant profiles.
   - `picc_registration_update_10002` / `10004` / `10005` — import French translations.
   - `picc_registration_update_10003` — forces swim_test_slot variations to $0 CAD.

4. Rebuild cache: `drush cr`.

## Cron

The site must run cron regularly for:
- Auto no-show sweep (every run)
- Annual swim status reset (runs once per year on the first cron run of the new year)

Default Drupal cron frequency is fine. Recommended: external cron every 15–30 min (standard for commerce sites).

## Adding new `t()` strings

When a PHP file gets a new translatable string:

1. Add `msgid` / `msgstr` to `html/modules/custom/picc_registration/translations/fr.po`.
2. Bump the update hook: add `picc_registration_update_1000N()` (next unused number) calling `_picc_registration_import_translations()`.
3. Run `drush updb -y` locally, commit the change.
4. On deploy, `drush updb -y` re-imports the .po.

## Creating the first swim test product

If the swim test product doesn't exist yet (fresh site):

1. Go to `/en/product/add/activity`.
2. Title: "Swim test".
3. Save.
4. Note the product ID (you'll need it if anything references the URL `/en/product/23/variations` — adjust accordingly or update config).

Variations are created per session — see user docs `creating-sessions.md`.

## Menu items

- `/swim-test-roster` — appears in main menu under "Swim test roster" (coaches).
- `/admin/swim-test-no-shows` — appears in admin menu under Commerce (commerce manager).

Both menu links come from the view configs (page display menu settings).

## Removing the feature

If you ever need to disable swim tests entirely:

1. Unpublish the Swim test product. Variants disappear from the public page.
2. The webforms, views, and email configs can be left in place, or manually deleted. They have no effect when the product is unpublished.

### ⚠️ DO NOT uninstall `picc_registration` to disable swim tests

The `picc_registration` module is shared by **all** registration flows on the site, not just swim tests. Uninstalling it will:

- Remove the activity registration webform handler (`PiccRegistrationHandler`) — breaks paid activity registration entirely.
- Remove the swim test handler, swim attestation handler, and coach evaluation form.
- Remove the profile billing pre-fill on checkout.
- Remove order cancellation transition (completed → canceled).
- Remove the cart page customizations ("Register for more activities" button, etc.).
- Remove the `[commerce_order:order_items_formatted]` token used in many email templates.
- Remove the Stock Availability views field plugin.
- Remove annual swim reset and auto no-show cron.

Do not uninstall this module as a way to turn off swim tests. Unpublish the product instead.

### Data cleanup

Data cleanup (profile swim fields, order items, webform submissions) should be a separate conscious decision, handled via drush scripts with backups in place.
