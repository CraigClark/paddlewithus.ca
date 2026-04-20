# Cancellations and refunds

Commerce manager handles all cancellations. Parents cannot self-cancel.

## Cancelling an order

1. Parent emails `petriecanoe@gmail.com` requesting a cancellation.
2. Commerce manager opens `/admin/commerce/orders`.
3. Finds the order (filter by customer email or order number).
4. Opens the order.
5. Clicks **Cancel order** (available on Completed orders thanks to the custom workflow transition).
6. Confirms the cancellation.

## What happens on cancellation

- Order state → `canceled`.
- Commerce Stock automatically returns the capacity (seat becomes available for someone else).
- Participant disappears from the participant roster view.
- The participant can be re-registered for the same variation if desired.

## Partial cancellation

To cancel some but not all participants on an order:
1. Open the order.
2. Click **Edit** on the order item line you want to remove.
3. Either delete the individual order item or reduce the quantity.
4. Save the order.
5. The remaining items stay Completed; the removed item is gone.

The commerce manager handles refund processing for the removed portion outside Drupal (Stripe dashboard, etc.).

## Refunds

Drupal does not initiate refunds automatically. The commerce manager:
1. Cancels the order (or order item) in Drupal.
2. Processes the refund in the Stripe dashboard.
3. Optionally notes the refund reference in the order comments/notes.

## Cancellations after event date

Cancelling after the session has happened is allowed by the workflow, but doesn't make practical sense. The commerce manager should apply the club's cancellation policy and refund guidance here.

## Re-registering after a cancellation

Once an order is canceled, the participant is no longer considered "registered" for that variation. They can register again normally.
