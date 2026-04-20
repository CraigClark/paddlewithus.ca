# Participant roster

The participant roster shows everyone registered for activity sessions. Used by coaches and the commerce manager.

## Accessing the roster

- Path: `/participant-roster` (linked from the main menu for coach and commerce_manager roles).
- Roles allowed: `coach`, `commerce_manager`.

## What's shown

Each row shows:
- **Program** (from the activity product).
- **Session** (from the variation — description + date range).
- **Participant name** (linked to the profile).
- **Status icons**:
  - 🏊 Pool icon: meets swim requirements (passed test, attested, or exempt).
  - ⚕️ Medical icon: the participant has allergies or medical notes. Hover or tap for details.
  - 📷 Photo icon: photo consent given.
- **View profile** link.

## Filtering

Use the exposed filters at the top:
- **Program**: filter by activity product.
- **Session**: filter by specific variation.

## CSV export

The roster has a CSV export display. Access via:
- `/participant-roster/YYYY-MM-DD-picc-participant-roster.csv` (use today's date).
- Useful for offline attendance sheets and printouts.

## What the roster queries

- Base entity: `commerce_order_item`.
- Filtered to:
  - Order item type: `activity_registration` (swim test registrations are in a separate view).
  - Order state: `completed` (not canceled, not draft).
  - Interface language matches order language.

## Cancellations hide automatically

Canceled orders don't appear. When the commerce manager cancels an order (e.g. refund), the participant disappears from the roster automatically.

## Adding custom filters

If you need to filter by activity type, date, or coach, ask a developer to add an exposed filter to the `registration` view.
