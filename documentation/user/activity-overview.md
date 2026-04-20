# Activity registration — overview

Petrie Island Canoe Club offers paid activities (camps, competitive training, etc.) that members register for through the website. This document explains who does what in that flow.

## Who does what

### Parents and members
- Browse activities at `/programs` or the site's activity catalog.
- Click through to an activity page, pick a session, register their kids, pay via Stripe.
- Can edit or add family members (participant profiles) before registering.

### Coaches
- View the participant roster at `/participant-roster` to see who is in their program/session.
- Download CSV exports for offline use (attendance sheets, etc.).

### Commerce manager
- Creates activity products and session variations.
- Sets prices, capacity limits, and family discount tiers.
- Handles cancellations and refunds.
- Manages orders in `/admin/commerce/orders`.

### Administrators
- Manage the underlying taxonomy (activity types, experience levels, etc.).
- Manage waiver and policy content pages.
- Configure Stripe.

## Key concepts

### Products and variations

- **Product**: an activity in general (e.g. "Canoe Kids", "Marathon Training").
- **Variation** (also called a "session"): a specific dated offering of that activity (e.g. "Canoe Kids — Week 1 of summer camp, July 5–9").

Parents register for a specific variation, not the product.

### Age requirements

Each variation has minimum and maximum age requirements. The system checks participant birth dates against these during registration. Parents cannot register kids who fall outside the age range.

### Capacity and stock

Each variation has a capacity limit. When it's full, the variation is hidden from the public catalog. The handler atomically checks stock under a lock to prevent overbooking during concurrent registrations.

### Family discounts

Configured per-variation. Graduated tiers:
- 1st participant: discount %
- 2nd participant: discount %
- 3rd participant: discount %
- 4th participant: discount %
- 5+ participants: discount %

Applied automatically at checkout based on how many of the family's participants are in the same variation.

### Waivers

Every activity registration requires agreement to:
- Legal authority (must be 18+)
- Release of liability and waiver of claims
- Concussion code of conduct
- Code of conduct
- Swim test policy

Agreements are captured on the registration webform.

### Checkout and payment

Registration uses the full commerce cart + checkout flow:
1. Parent fills out the registration webform.
2. Order items are added to a draft cart.
3. Parent can continue registering other kids/activities, all added to the same cart.
4. Proceeds to Stripe checkout.
5. On successful payment, the order is marked Completed.
6. Email receipts are sent.
