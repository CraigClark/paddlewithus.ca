# Activity registration — deployment notes

## Required modules

Ensure these are installed:
- `picc_registration` (custom) — handler, hooks, tokens
- `picc_discount` (custom) — family discount offer plugin
- `picc_sku` (custom) — auto SKU
- `picc_ext` (custom) — participant status icons, stock filter
- `commerce`, `commerce_cart`, `commerce_checkout`, `commerce_order`, `commerce_payment`, `commerce_price`, `commerce_product`, `commerce_promotion`, `commerce_store`
- `commerce_stock` + `commerce_stock_local` (stock tracking)
- `commerce_stripe` (Stripe payments)
- `commerce_email` (templated emails on order events)
- `webform`
- `better_exposed_filters` (roster exposed filters)
- `profile` (participant profiles)
- `symfony_mailer` (HTML email rendering)

## First deploy to a new environment

1. `drush cim -y`
2. `drush updb -y`
3. `drush cr`

## Stripe configuration

The Stripe payment gateway isn't in config (secret keys don't belong there). Configure manually:
1. Go to `/admin/commerce/config/payment-gateways`.
2. Add/edit the Stripe gateway.
3. Fill in publishable key and secret key (from the Stripe dashboard).
4. Switch to live mode when going to production.

## Default store

`picc_registration_commerce_product_presave()` auto-assigns the first store marked `is_default: TRUE` to new products. Make sure one store is flagged as default in `/admin/commerce/config/stores`.

## Family discount promotion

The `picc_family_discount` offer plugin needs to be attached to a commerce promotion entity for it to apply. Create the promotion at `/promotion/add`:
1. Name: e.g. "Family discount (graduated)".
2. Coupon: none (automatic).
3. Offer plugin: "PICC Family Discount (Graduated)".
4. Conditions: none, or filter to a specific store.
5. Enable.

With no promotion using the plugin, discounts won't apply regardless of what's set on the variations.

## Policy content

The registration webform references 4 policy nodes via embedded entity references in the `policy_info` element and the `registration.yml` email. UUIDs:
- `75bda914-483e-4dfb-bd8e-b46a5e53b10a`
- `4787287a-681d-46f6-b289-839820928e70`
- `e668b1df-3897-4a27-a3dd-1aad94ca113f`
- `91bf1982-5de8-4eec-afc4-b7f5cea54bdb`

If the policy nodes don't exist in the destination environment, the webform and email will render empty boxes. Ensure these UUIDs are migrated / re-created.

## Cron

Drupal's standard cron (every 15–30 min recommended). Activity registration doesn't have its own cron jobs — the hooks in `picc_registration_cron()` are for swim tests (annual reset + no-show sweep).

## Adding new translatable strings

The handler and module use `$this->t()` / `t()` for user-visible strings. French translations are in:
- `html/modules/custom/picc_registration/translations/fr.po` — custom module strings
- `config/sync/language/fr/` — config translations (webforms, views, emails)

After adding new `t()` calls:
1. Add to `fr.po`.
2. Bump the update hook (see `picc_registration.install`).
3. Run `drush updb -y`.

For config translations (webform labels, email subject/body), edit the fr.yml file directly and run `drush cim`.

## Removing or disabling activity registration

If you ever need to stop activity registration:
- Unpublish the activity products you don't want to run.
- Close the webform: set `status: closed` in `config/sync/webform.webform.program_registration.yml`.

### ⚠️ DO NOT uninstall `picc_registration` to disable activity registration

`picc_registration` is shared across all registration flows. Uninstalling it removes:
- The swim test handler, swim attestation handler, and coach evaluation form.
- Annual swim reset and auto no-show cron.
- Cart customizations, order cancellation transition, token, profile billing prefill.

Closing the webform is the correct way to pause activity registration.

## Database size considerations

Each registration creates one order item per participant. A family of 4 registering for 3 activities generates 12 order items across 1–3 orders. Over a year this grows. Consider periodic cleanup of old canceled or test orders.

## Related documentation

- `/documentation/technical/swim-test-architecture.md` — swim test feature architecture (related but distinct).
- `/documentation/user/` — end-user-facing docs.
