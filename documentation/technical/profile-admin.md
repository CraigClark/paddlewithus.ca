# Profiles admin — technical notes

The system entity collection at `/admin/people/profiles` (provided by the Profile contrib module via an `EntityListBuilder`) becomes unusable past a few dozen profiles — no filters, no sort beyond a single click-to-sort, no useful labels for customer profiles. We replace it with a Views-based listing while keeping the URL and the existing **People > Profiles** menu link.

## How the URL swap works

The contrib route is `entity.profile.collection`, auto-generated from the Profile entity annotation `"collection" = "/admin/people/profiles"`. Two ways we could intervene:

1. Define our own Views page at the same path, delete the contrib route, override the menu link.
2. **Alter the contrib route's controller defaults to invoke our Views display.** Same URL, same route name, menu link unchanged.

We do option 2.

`Drupal\picc_registration\Routing\RouteSubscriber::alterRoutes()` replaces the route's defaults with the Views page controller and the requirements with a `_permission: administer profile` check, plus the four Views options the controller expects:

- `_view_display_plugin_class` — `\Drupal\views\Plugin\views\display\Page::class`
- `_view_display_plugin_id` — `'page'`
- `_view_display_show_admin_links` — `TRUE`
- `_view_argument_map` — `[]`
- `returns_response` — `FALSE`

Without those four, `\Drupal\views\Routing\ViewPageController::handle()` errors at line 60 with "Class name must be a valid object or a string" because `_view_display_plugin_class` is null.

The view itself **also** has its own page display at `/admin/people/profile-list` — that's the canonical Views-side path, useful for direct linking and as a fallback if anything ever bypasses the route alter.

## The view: `picc_profiles_admin`

Base table `profile`. Columns: profile bulk form, name (`field_name` from the name module, no link), type (bundle), owner (`uid`, linked to user), status, updated, View link, Edit operations dropdown.

### Sort

Default: `field_name__family` ASC, then `field_name__given` ASC. All columns are click-sortable; updated defaults to descending.

### Filters

- **Type** (bundle) — exposed, defaults to `participant`. The system view showed both bundles indiscriminately; in practice the `participant` bundle is the meaningful one (real people) and `customer` is order-billing snapshots.
- **Name** — exposed, full-text via the `name_fulltext` plugin on `profile.field_name` (mirrors the swim-test-roster pattern).
- **Owner** — exposed, full-text via `name_fulltext` on `user.field_name` through a `profile.uid → user` relationship. Filters by the **account holder's** real name, not their username (which is often an email).
- **Hide anonymous (uid=0)** — non-exposed, hardcoded `uid != 0`. Drupal Commerce creates a fresh customer profile per checkout to record the billing address, with `uid = 0` even when the order owner is logged in. These are frozen address snapshots; nobody manually edits them. Hiding them removes ~half the rows that would otherwise appear under Type=Customer.

### Customer-bundle name column

`profile.field_name` is on both `participant` and `customer` bundles, but only participants populate it. Customer profiles will therefore show an empty Name column — that's a known limitation; there's nothing useful to display there for customer profiles since they're keyed by address, not person.

For the **profile page title** (label) we extend `picc_ext\ProfileLabelSubscriber` to handle the customer bundle by pulling `field_name` from the owning **user** entity (which does have a name field). Falls back to Commerce's address-line label only for guest-checkout profiles where there's no user. The subscriber registers with `priority: -10` so it runs after Commerce's own `ProfileLabelSubscriber`.

## Key files

- `html/modules/custom/picc_registration/src/Routing/RouteSubscriber.php` — alters `entity.profile.collection`.
- `html/modules/custom/picc_registration/picc_registration.services.yml` — registers the subscriber and the swim-exemption service.
- `config/sync/views.view.picc_profiles_admin.yml` — the view.
- `html/modules/custom/picc_ext/src/EventSubscriber/ProfileLabelSubscriber.php` — sets profile labels from `field_name` (participant uses profile's own; customer uses owning user's).

## Update hook 10013

`picc_registration_update_10013()` calls `\Drupal::service('router.builder')->rebuild()` so the alter takes effect on existing environments after a deploy. The view config itself is imported by the standard `drush cim`.
