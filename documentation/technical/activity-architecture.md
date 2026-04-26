# Activity registration — technical architecture

## Overview

Paid activity registration (distinct from swim tests and attestation). The flow is: webform → handler → cart → Stripe checkout → completed order → email receipts.

Custom code lives primarily in two modules:

- **`picc_registration`** — webforms, the activity handler, hooks for billing prefill, order workflow, cart customization, tokens.
- **`picc_discount`** — graduated family discount calculation.

Plus:
- `picc_sku` — auto-generates SKUs for all variations.
- Config in `config/sync/` (product type, variation type, order item type, fields, webforms, views, emails, checkout flow).
- Stripe via `commerce_stripe`.

## Data model

### Product type `activity`

Holds both activity variations AND swim test slot variations (shared product type). The type config is at `config/sync/commerce_product.commerce_product_type.activity.yml`.

**Fields on the product**:
- `body` — description (text_with_summary, required)
- `field_primary_image` — hero image
- `field_acivity_type` — taxonomy
- `field_age_range` — display-only range
- `field_audience` — youth/adult/all ages
- `field_experience` — experience level
- `field_coach_lang` — coaching language
- `field_contact_email`
- `field_location`
- `field_schedule`
- `field_equipmenet_provided`
- `field_what_to_bring`
- `field_season` — taxonomy

### Variation type `activity`

- Order item type: `activity_registration`
- `generateTitle: false` — title is set by the admin

**Fields on the activity variation**:
- `field_date_range` — daterange (required). Session dates.
- `field_desc` — short description (string)
- `field_capacity` — commerce_stock_level. Mapped to `local_stock` in `commerce_stock.service_manager`.
- `field_age_min` — integer, default 1
- `field_maximum_age` — integer, default 99
- `field_age_calc_date` — datetime (optional, defaults to "today" during validation)
- `field_discount_1` through `field_discount_5_up` — integers 0–100 (percentage)

### Order item type `activity_registration`

- `field_participant` — entity reference to profile (single cardinality). One order item per participant.

## Registration flow

### Webform: `program_registration`

Path: `/form/program-registration?product=X&variation=Y`

Elements:
- `product_id`, `variation_id` — hidden, pulled from query string via `[current-page:query:...]` tokens.
- `participants` — `webform_entity_checkboxes` using the `participants` view, `er_participants` display (shows photo consent + shirt size inline).
- `manage_participants` — webform markup with add/edit links.
- `policy_info` — processed text embedding 4 policy node references.
- Five required waiver checkboxes:
  - `waiver_agreement_authority` (18+ legal authority)
  - `waiver_agreement` (liability waiver)
  - `concussion_acknowledgment`
  - `code_of_conduct_acknowledgment`
  - `swim_test_acknowledgment`
- `actions` — submit button labeled "Register".

Handler: `picc_registration_handler` (single cardinality, RESULTS_PROCESSED).

### Handler: `PiccRegistrationHandler`

File: `html/modules/custom/picc_registration/src/Plugin/WebformHandler/PiccRegistrationHandler.php`

`submitForm()` flow:
1. Extract selected participants (filter out zero/unchecked).
2. `validateVariation()` — load variation by ID.
3. `validateSessionDate()` — `field_date_range.end_value` must be in the future.
4. `validateParticipantsAge()` — each participant's `field_birth_date` vs. `field_age_min`/`field_maximum_age`/`field_age_calc_date`. Throws with a list of failures.
5. `createCommerceOrderWithStockLock()` — acquires a mutex lock, re-checks stock, creates order items, adds to cart.
6. Redirect to `/cart` (commerce cart page).

Key methods:
- **`checkStockAvailability()`** — queries `commerce_stock_transaction` for total stock. Subtracts items sitting in draft carts (across all users) to get effective availability. Accounts for the current user's own cart items to avoid double-counting.
- **`createCommerceOrder()`** — finds existing draft cart for the user, skips participants already in cart or in completed orders, creates new order items, then adds to cart or creates a new draft order. Post-save, reloads order items and appends participant name to the title ("Activity Title - Participant Name").
- **`createCommerceOrderWithStockLock()`** — wraps the above in `\Drupal::lock()->acquire('picc_registration_stock_' . $variation_id, 15.0)`. Retries once after `wait()`. Releases in `finally`.
- **`findDraftOrder()`** — loads most recent draft+cart order for the user.
- **`isInCompletedOrder()`** — checks if participant+variation is already in a completed or fulfillment order.
- **`countDraftCartItemsForVariation()`** — raw SQL count of draft cart items for the variation across all users.
- **`getStore()`** — loads the first store (assumes one store).

### Cart and checkout

Standard Commerce cart + `commerce_checkout` flow. Configured at `config/sync/commerce_checkout.commerce_checkout_flow.default.yml`.

Steps:
1. Login (allow anonymous to log in)
2. Order information (contact + payment_information Stripe pane)
3. Stripe review pane
4. Payment processing (capture)
5. Review
6. Complete (completion message + registration prompt)

Uses `commerce_stripe` module. Config at `config/sync/commerce_stripe.settings.yml`.

### Order lifecycle

- Handler creates order in state `draft`, `cart: TRUE`.
- Parent proceeds through checkout → state becomes `completed`.
- Emails fire on the `order_placed` event (see below).

## Family discount plugin

File: `html/modules/custom/picc_discount/src/Plugin/Commerce/PromotionOffer/FamilyDiscount.php`

Commerce Promotion offer plugin (id: `picc_family_discount`). Applied via a commerce promotion at the order level.

Logic:
1. Groups order items by variation ID.
2. For each variation's group, sorts order items (by insertion order).
3. Assigns each order item a position (1st, 2nd, ..., 5+).
4. Reads the corresponding discount field on the variation (`field_discount_1` ... `field_discount_5_up`).
5. Computes the adjustment: `unit_price * (discount_pct / 100)`, negated.
6. Attaches as a `promotion` adjustment on the order item, label "Family Discount".

Discounts are visible in the cart and on the order receipt.

Note: the plugin groups per-variation. So if a family registers for two different sessions, each session's discount is calculated independently.

## Session listing on the activity page

Each activity product page renders a **Sessions** block listing its variations as cards (date range, capacity, price, family discounts, register button). The block is the `variant_session` view (display `block_session`) embedded in `commerce-product--activity--full.html.twig` via `drupal_view()`.

### Row template

`html/themes/custom/daisyui_ext/templates/view/views-view-fields--variant-session--block-session.html.twig` handles per-row rendering. Beyond the visible fields, the template also reads three preprocess-injected variables: `variation_bundle`, `registration_status`, and `spots_remaining`.

### Visibility filter

`picc_ext_views_query_alter()` in `html/modules/custom/picc_ext/picc_ext.module` keeps a tracked-bundle variation visible if **either**:

- `SUM(commerce_stock_transaction.qty) > 0` (positive remaining stock), OR
- it has at least one `activity_registration` or `swim_test_registration` order item in a `completed` or `fulfillment` state order.

This means a sold-out session with real registrations stays on the page (rendered as **Full**), while a misconfigured session that was never stocked AND has no registrations stays hidden — so a manager who forgets to set capacity doesn't surface a broken row. Only `activity` and `swim_test_slot` bundles are filtered (those marked stock-tracked in `commerce_stock.service_manager.yml`).

### Status computation

`picc_registration_views_post_execute()` in `picc_registration.module` runs after the view query and prefetches per-variation data in two batched queries (one for stock totals, one for completed-registration counts). It writes a static cache keyed by variation ID with: `status`, `spots_remaining`, `registrations`.

`picc_registration_preprocess_views_view_fields()` reads from that static and exposes `registration_status` + `spots_remaining` to the row template.

The three statuses:

| Stock | Status | Display |
|---|---|---|
| `> PICC_REGISTRATION_LOW_STOCK_THRESHOLD` | `available` | Capacity line + active register button |
| `1` to `PICC_REGISTRATION_LOW_STOCK_THRESHOLD` | `low` | Capacity line + warning badge "Almost full" + active register button |
| `<= 0` | `full` | Red badge "Full" (replaces capacity line) + disabled "Session full" button |

### Hardcoded threshold

`PICC_REGISTRATION_LOW_STOCK_THRESHOLD = 3` is a module-level `const` at the top of `picc_registration.module`. To change the spots-remaining cutoff for the "Almost full" badge, edit that constant and `drush cr`. There's no admin UI for this yet — see GitHub issue [#38](https://github.com/CraigClark/paddlewithus.ca/issues/38) for the deferred follow-up to expose it as a config form.

### Accessible disabled buttons

DaisyUI's default `:disabled` styling washes button text below WCAG AA contrast. `daisyui_ext/css/base/base.pcss` overrides `.btn:disabled / .btn[disabled] / .btn-disabled` (matching DaisyUI's `:not(.btn-link, .btn-ghost)` specificity, including a `--btn-fg` reset) to use a solid `--color-base-300` background with full-contrast `--color-base-content` text and a `not-allowed` cursor. This is global — every disabled `.btn` on the site uses the accessible styling. Style changes require `npm run build` in `html/themes/custom/daisyui_ext/`.

## Participant roster view

File: `config/sync/views.view.registration.yml`

Display `page_1`:
- Path: `participant-roster`
- Access: roles `coach`, `commerce_manager`
- Menu: main menu
- Base entity: `commerce_order_item`
- Relationships: `field_participant` → profile, `commerce_product_variation`, `order_id`

Filters:
- `type = activity_registration`
- `state = completed` (via state_machine_state filter on order)
- `langcode = current interface language` (on both product and variation)

Exposed filters: program (selective), session (selective), both BEF-enabled with Better Exposed Filters.

Fields:
- Participant name (linked to profile)
- `participant_status_icons` (custom plugin from picc_ext) — pool, medical, photo icons
- Hidden fields used in header grouping: program title, session description, date range

Display `data_export_1`: CSV export at `participant-roster/YYYY-MM-DD-picc-participant-roster.csv`.

## Emails

Three `commerce_email` configs fire on `order_placed`, all filtered to `variation_types: [activity]`:

1. **`receipt.yml`** — to the customer. HTML table with order details, total, `[commerce_order:order_items_formatted]` token showing each participant.
2. **`notify_picc.yml`** — to petriecanoe@gmail.com. Same format, plus a checklist of what the customer received.
3. **`registration.yml`** — to the customer. Registration package with embedded policy PDFs (waiver, concussion policy, code of conduct, swim test policy) via node references.

All three are French-translatable via `config/sync/language/fr/commerce_email.commerce_email.*.yml`.

The `[commerce_order:order_items_formatted]` token is custom, defined in `picc_registration_token_info()` + `picc_registration_tokens()`. Renders each order item as "**Participant Name**: Activity Title" on separate lines, with a deduplication check so the name isn't shown twice when the title already contains it.

## Hooks in picc_registration.module related to activity registration

- **`picc_registration_workflows_alter()`** — Adds a `completed → canceled` transition to `order_default`, letting commerce manager cancel completed orders.
- **`picc_registration_commerce_order_item_view_alter()`** — For `activity_registration` bundle, appends participant name to the displayed title if not already present.
- **`picc_registration_form_alter()`** (checkout branch) — Pre-populates billing address from the user's profile address during checkout.
- **`picc_registration_form_views_form_commerce_cart_form_default_alter()`** — Removes "Update cart" button, adds "Register for more activities" link on the cart page.
- **`picc_registration_commerce_product_presave()`** — Auto-assigns the default store when none is set.
- **`picc_registration_token_info()` / `picc_registration_tokens()`** — Defines the `[commerce_order:order_items_formatted]` token.

## picc_sku auto-generated SKUs

File: `html/modules/custom/picc_sku/picc_sku.module`

- `hook_form_alter()` on variation forms pre-fills the SKU with `ACT-<8 hex chars>` (hash of microtime + random int). Loops until unique.
- `hook_entity_presave()` safety net — fills SKU if still empty (for API/import cases).
- SKU field is hidden from the form completely.
- Applies to all variation bundles (activity, swim_test_slot).

## Preventing duplicate registrations

Two layers in `PiccRegistrationHandler::createCommerceOrder()`:
1. **Already in cart** — checks the user's draft cart for the same participant+variation. Skipped with status message.
2. **Already in completed order** — `isInCompletedOrder()` queries all order items for the participant+variation, checks if any are in `completed` or `fulfillment` state. Skipped with warning message.

## Key files

### Module
- `html/modules/custom/picc_registration/picc_registration.module`
- `html/modules/custom/picc_registration/src/Plugin/WebformHandler/PiccRegistrationHandler.php`
- `html/modules/custom/picc_discount/src/Plugin/Commerce/PromotionOffer/FamilyDiscount.php`
- `html/modules/custom/picc_sku/picc_sku.module`

### Config
- `commerce_product.commerce_product_type.activity.yml`
- `commerce_product.commerce_product_variation_type.activity.yml`
- `commerce_order.commerce_order_item_type.activity_registration.yml`
- `webform.webform.program_registration.yml`
- `views.view.registration.yml` — participant roster
- `views.view.variant_session.yml` — session listing on product pages
- `views.view.participants.yml` — entity checkboxes for the registration webform
- `commerce_email.commerce_email.receipt.yml`
- `commerce_email.commerce_email.notify_picc.yml`
- `commerce_email.commerce_email.registration.yml`
- `commerce_checkout.commerce_checkout_flow.default.yml`
- `commerce_stripe.settings.yml`
- `commerce_stock.service_manager.yml` — maps activity variations to `local_stock`
- Field configs: `field.field.commerce_product.activity.*.yml`, `field.field.commerce_product_variation.activity.*.yml`, `field.field.commerce_order_item.activity_registration.*.yml`

### Templates
- `html/themes/custom/daisyui_ext/templates/view/views-view-fields--registration.html.twig`
- `html/themes/custom/daisyui_ext/templates/view/views-view-grouping--registration.html.twig`
- `html/themes/custom/daisyui_ext/templates/view/views-view-unformatted--registration.html.twig`
- `html/themes/custom/daisyui_ext/templates/view/views-view-fields--variant-session--block-session.html.twig`

### Related (shared with swim tests)
- `views.view.variant_session.yml` — uses `variation_bundle` from `picc_registration_preprocess_views_view_fields()` to pick the right webform link.
- `ParticipantStatusIcons` plugin in `picc_ext` — renders the icons on the roster.
