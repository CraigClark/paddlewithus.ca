# How parents register for activities

## Finding an activity

1. Parent visits the activity catalog (`/programs` or similar).
2. Clicks through to the activity page of interest.
3. Reviews the description, schedule, what to bring, etc.
4. Scrolls down to **Sessions** — shows all dated offerings with status:
   - **Available** — normal display, register button active.
   - **Almost full** — yellow badge appears when 3 or fewer spots remain. Register button still active.
   - **Full** — red "Full" badge replaces the capacity line and a disabled "Session full" button replaces the register button. Parents can see the session existed (so they don't wonder if it was missing or cancelled) but can't register.
   - Sessions that were never properly stocked AND have no registrations don't appear at all (treated as misconfigured).

## Registering

1. Clicks **Register for this session** beside a specific session.
2. Redirected to the program registration webform at `/form/program-registration?product=X&variation=Y`.
3. The form lists the parent's family members. Each row shows the participant's name, photo consent status, and shirt size.
4. Parent can:
   - Check participants to register.
   - Click **Add a new family member** if needed (opens a new tab — returns to the form after saving).
   - Click **Edit your list of family members** to update birth dates, photo consent, etc.
5. Agree to all required policies (5 checkboxes):
   - Legal authority (must be 18+)
   - Release of Liability and Waiver of Claims
   - Concussion Code of Conduct
   - Code of Conduct
   - Swim Test Policy
6. Click **Register**.

## Validation

The handler runs several checks:
- **Age**: each selected participant must fall within the variation's age range. If anyone doesn't, the form errors with a specific list ("Bill Smith (age 6)").
- **Session date**: the session can't have already ended.
- **Stock**: enough capacity for the selected participants. If someone beats you to the last spot, the form errors.
- **Already registered**: if a participant is already in the cart or a completed order for this variation, they're silently skipped with a warning.

## Cart and checkout

After a successful registration:
1. Parent sees a message: "Registration successful! Proceeding to checkout..."
2. They're redirected to the cart page (`/cart`).
3. The cart shows order items for each registered participant, with the activity title and participant name.
4. Family discounts are applied automatically based on how many family members are in the same variation.
5. Parent can:
   - Add more activities to the cart (click **Register for more activities**).
   - Remove items (if they made a mistake).
   - Proceed to checkout.
6. Stripe payment.
7. On successful payment, order is Completed. Two emails are sent (receipt to parent, notification to PICC).

## Cancellations

Parents cannot self-cancel. To cancel:
1. Email `petriecanoe@gmail.com`.
2. Commerce manager cancels the order in the admin UI. This:
   - Transitions the order to Canceled.
   - Restores capacity automatically.
   - Handles refund per the club's policy (outside Drupal).
