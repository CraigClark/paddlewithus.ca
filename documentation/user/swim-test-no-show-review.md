# No-show review (commerce manager)

## Purpose

When a participant registered for a swim test slot doesn't show up, the system flips their registration status to **No show** 8 hours after the slot ends. The commerce manager reviews these at `/admin/swim-test-no-shows`.

The system does **not** automatically invoice or charge for no-shows. That's handled outside Drupal (email, e-transfer, etc.).

## The view

`/admin/swim-test-no-shows` shows all registrations currently marked No show. Rows are grouped by date.

Filter by **Status**: default is No show. You can also view Resolved items to review history.

## Resolving a no-show

1. Click **Resolved** on the row.
2. A dialog opens with a required **Reason / notes** text field.
3. Enter why the no-show is being resolved:
   - "Payment received"
   - "Missed due to illness — excused"
   - "Waived"
   - etc.
4. Click **Mark resolved**.
5. The row disappears from the default view. The registration status becomes `excused` and the reason is saved with it.

## Undoing a resolution

If you made a mistake, click **Undo** on a resolved row. The status flips back to No show and the reason is cleared.

## Receiving payment

Payment handling is outside Drupal. The club's standard process applies (email the parent, e-transfer, etc.). Once handled, come back to this view and mark the no-show resolved with a note like "Payment received 2026-05-20 via e-transfer".

## Does a no-show block re-registration?

No. A parent can immediately register the same kid for a new slot. This is intentional — the priority is getting kids tested, not enforcing fees in software.
