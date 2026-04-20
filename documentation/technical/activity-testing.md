# Activity registration — testing and troubleshooting

## Manual testing scenarios

### Parent registers for an activity

1. Log in as an account holder with at least one participant profile (with a birth date in the variation's age range).
2. Visit the activity product page.
3. Scroll to Sessions, pick one with capacity > 0.
4. Click **Register for this session**.
5. On the webform:
   - Check at least one participant.
   - Agree to all 5 required checkboxes.
   - Click Register.
6. Verify: redirected to `/cart` with a status message.
7. Check cart shows an order item per participant, priced correctly, with family discount applied (if applicable).
8. Check billing address is pre-filled from profile.
9. Complete Stripe checkout (use Stripe test cards in dev).
10. Verify: order state = Completed, emails sent.

### Age validation

1. Create a variation with `field_age_min: 8`, `field_maximum_age: 12`.
2. Try registering a 6-year-old. Form errors with "Kid Name (age 6) does not meet age requirements (8–12)".
3. Try registering a 14-year-old. Form errors similarly.
4. Verify participant without a birth date gets "Kid Name (no birth date)".

### Capacity enforcement

1. Create a variation with capacity 2.
2. Register participant A (capacity becomes 1).
3. Register participant B (capacity becomes 0).
4. Try registering participant C. Form errors: "Sorry, this session is at capacity."
5. Try registering 2 participants at once when capacity is 1: "Sorry, only 1 spot remain..."

### Concurrent registration (stock lock)

Hard to test manually. The mutex lock (`picc_registration_stock_<variation_id>`) is the protection. You can simulate concurrency with two quick registrations from two browsers and verify neither oversells capacity.

### Already-registered participant

1. Register Hugo for session X.
2. Without completing checkout, try registering Hugo again for session X.
3. The handler silently skips Hugo with a status message "Already in cart: Hugo Clark". New order items aren't created.
4. If Hugo is in a completed order for session X, the skip message is a warning: "The following participants were already registered for this session (skipped): Hugo Clark".

### Family discount

1. Create a variation with discounts: 0, 10, 20, 30, 40.
2. Register 5 kids of the same family for the variation.
3. Check the cart: 1st kid full price, 2nd 10% off, 3rd 20% off, 4th 30% off, 5th 40% off.
4. Verify total reflects discounts.

### Cancellation

1. As commerce manager, open a Completed order at `/admin/commerce/orders`.
2. Click **Cancel order**.
3. Verify:
   - Order state = Canceled.
   - Stock is returned (capacity on variation increases).
   - Participant no longer shows on `/participant-roster`.

### Participant roster

1. As coach or commerce_manager, visit `/participant-roster`.
2. Verify only completed orders show (not draft, not canceled).
3. Verify exposed filters work (program, session).
4. Check CSV export at `/participant-roster/YYYY-MM-DD-picc-participant-roster.csv`.

## Common issues

### "Registration successful" but no order created

Check watchdog for errors: `drush watchdog:show --count=10 --severity=3 --type=picc_registration`. The handler logs everything with full context. Common causes:
- Stock lock acquire failed (high contention — rare).
- Unable to load variation (variation deleted between page load and submit).

### Variation not showing on product page

- Capacity zero → hidden by `picc_ext_views_query_alter()`.
- Variation unpublished.
- `field_date_range.end_value` is in the past (the `variant_session` view filters these out).
- Wrong interface language (view filters by language).

### Email not sending

- In ddev, SMTP will time out. This is expected.
- On production, check `drush watchdog:show --type=symfony_mailer`.
- Verify `commerce_email` is enabled.

### Order item title missing participant name

The handler sets the title post-save in `createCommerceOrder()`. If it's missing, check:
- The order item has `field_participant` set.
- The profile has `field_name` with given/family values.
- Try re-saving the order item — `picc_registration_commerce_order_item_view_alter()` re-appends the name on view.

### Family discount not applied

- The discount must be configured as an active commerce promotion with the `picc_family_discount` offer plugin.
- Check the variation has non-zero `field_discount_*` values.
- Verify the order has multiple items for the same variation (single-participant registrations don't trigger graduated discount).

### Checkout not redirecting to Stripe

- `commerce_stripe` module enabled.
- Stripe keys configured at `/admin/commerce/config/payment-gateways`.
- In dev, use test keys.

### Billing address not pre-filling

- User must have a profile with `field_address` populated.
- Check `picc_registration_form_alter()` — it logs to watchdog when it runs. If it's not firing, the form id pattern may have changed.

## Field permission reference

The `field_permissions` module restricts access to swim-related fields but activity-registration fields (birth_date, age fields on the variation, capacity, etc.) use standard Drupal access.

## Data model diagnostic

```sh
# Check a specific order
ddev drush php:eval "
\$o = \Drupal::entityTypeManager()->getStorage('commerce_order')->load(ORDER_ID);
echo 'State: ' . \$o->getState()->getId() . PHP_EOL;
foreach (\$o->getItems() as \$oi) {
  \$p = \$oi->get('field_participant')->entity;
  \$v = \$oi->getPurchasedEntity();
  echo '- ' . \$oi->getTitle() . ' | participant: ' . (\$p ? \$p->id() : 'null') . ' | variation: ' . (\$v ? \$v->id() : 'null') . ' | price: ' . \$oi->getUnitPrice()->getNumber() . PHP_EOL;
}
"

# Check stock level for a variation
ddev drush php:eval "
\$v = \Drupal::entityTypeManager()->getStorage('commerce_product_variation')->load(VARIATION_ID);
\$svc = \Drupal::service('commerce_stock.service_manager')->getService(\$v);
\$locs = \Drupal::entityTypeManager()->getStorage('commerce_stock_location')->loadByProperties(['status' => TRUE]);
echo 'Stock: ' . \$svc->getStockChecker()->getTotalStockLevel(\$v, \$locs) . PHP_EOL;
"
```
