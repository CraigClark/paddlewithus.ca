# How coaches evaluate swim tests

## The roster

Coaches see the swim test roster at `/swim-test-roster`. The page is designed for mobile use at the pool.

- Rows are grouped by date.
- Within each date, rows are sorted by time.
- Each row shows: participant name (linked to profile), Evaluate button.

## Filtering

Default filter shows **Pending** registrations only. Use the Status checkboxes to also see Passed, Failed, No show, or Excused rows.

Filter by date with the Date filter to focus on today's sessions.

## Evaluating a participant

1. Tap **Evaluate** beside the participant's name.
2. An off-canvas dialog opens with the participant's name, current status, and a date field.
3. The date defaults to today. Change it only to backdate (e.g. if you forgot to mark someone yesterday).
4. Tap **Pass** or **Fail**.
5. The row updates immediately.

### Passing
- Order item status → `passed`
- Participant profile:
  - Swim status → `Swim test passed`
  - Passed date → selected date
  - Evaluated by → current coach

### Failing
- Order item status → `failed`
- Profile is not updated. The participant can register for another session.

### Undoing
- Click **Undo** on a passed or failed row. The status resets to `pending` and profile fields are cleared if applicable.

## No-shows

You do **not** need to mark no-shows. If a pending registration's slot ended more than 8 hours ago, the system automatically flips it to "no show" overnight. These appear in the commerce manager's no-show review — not your concern.
