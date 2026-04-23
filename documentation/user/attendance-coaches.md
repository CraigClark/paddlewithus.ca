# How coaches record attendance

Coaches use the attendance page at `/participants/attendance` to check participants in when they arrive and check them out when they're picked up.

The page is designed for fast use on a phone at the dock.

## The attendance list

- Shows only programs that have attendance tracking turned on.
- Shows only sessions whose date range covers today.
- Shows only completed registrations (paid + confirmed).
- Grouped by program.

If multiple programs are running today, use the **Program** filter at the top to narrow to just yours. Use the **Participant name** filter to jump to a specific kid.

## Check-in

1. Tap **Check in** on the row.
2. A dialog opens: `Check in <Jane Smith>` with an optional note field.
3. Add a note if there's anything worth recording (e.g. "Arrived late", "Parent mentioned medication change"). Otherwise leave it blank.
4. Tap **Check in**. The dialog closes and the row updates.

The check-in time is captured automatically — it's the moment you tap the button.

## Check-out

1. Tap **Check out** on the row.
2. A dialog opens: `Check out <Jane Smith>`.
3. Select who's picking up:
   - The list shows emergency contacts the parent has marked as authorised pickup.
   - If it's someone else, select **Other** and type their name.
4. Add a note if useful (e.g. "Biked home with parent consent", "Picked up early — felt sick").
5. Tap **Check out**.

If zero authorised contacts are on file, the dialog will show a warning and require you to type the pickup name yourself.

### Check-out without check-in

If you realise a kid is leaving and you never checked them in, it's still safe to check them out. The dialog warns that the record will be flagged as "without check-in" — the flag is for the commerce manager's records, not something you need to correct.

## Mistakes — resets

If you tap the wrong row or type the wrong name:

1. On the row, tap **Reset check-in** or **Reset check-out**.
2. Enter a short reason ("Clicked the wrong participant", "Entered by mistake").
3. Tap **Reset**.
4. The row returns to the previous state, and you can try again.

Resets don't erase anything — the original action stays in the audit history, just flagged as superseded. This is intentional. You don't need permission to do it, but if you're confused about what happened on a previous day, let a commerce manager sort it out.

## Badges on the list

| Badge | Meaning |
|---|---|
| **In 08:54** (green) | Checked in at that time. Not yet checked out. |
| **Out 16:12** (neutral) | Check-out recorded normally. |
| **Out 16:12 (no check-in)** (yellow) | Check-out recorded but no check-in existed. Needs no action from you — flagged for manager review. |

## What you can't do

- Change yesterday's attendance. The list only shows today.
- See history for any day other than today.
- Delete records.

If you need any of those, ask a commerce manager.
