# How coaches evaluate swim tests

## The roster

Coaches see the swim test roster at `/participants/swim-test-roster` (or via the **Swim test roster** menu link). The page is designed for mobile use at the pool or beach.

- One row per under-15 participant.
- Pre-registered kids appear under a heading for their slot (date + time).
- Kids who showed up without registering appear at the bottom under **Unscheduled**.
- Each row shows: participant name, a status badge, and an **Evaluate** button (when applicable).

## Status badges

| Badge | Meaning |
|---|---|
| **Pending** | Hasn't been tested yet. |
| **Failed** | Tested and didn't pass — needs another attempt. |
| **Passed** | Tested and passed; cleared for the season. |
| **Attested** | Adult who self-attested they meet swim requirements. |
| **Exempt** | Marked exempt by an admin (e.g. medical accommodation). |

Only **Pending** and **Failed** rows have an Evaluate button. Cleared statuses (Passed, Attested, Exempt) show the badge alone — there's nothing for you to do on those rows.

## Filtering

Two exposed filters at the top of the page:

- **Status** — defaults to **Pending**, which shows both never-tested kids (`Pending` badge) and kids who failed and need a re-test (`Failed` badge). Other options:
  - **Passed** — kids who already cleared this season.
  - **Failed only** — drill down to just the kids who failed (a subset of the default Pending view).
  - **Attested** — adults who self-attested.
  - **Exempt** — kids who are exempt.
  - **- Any -** — every participant regardless of status.
- **Name** — type a first or last name (or part of one) to narrow the list. Searches both columns.

The Status filter narrows who appears in the list; the badges still show on every row so you can see at a glance what state each kid is in.

## Evaluating a participant

1. Tap **Evaluate** beside the participant's name.
2. An off-canvas dialog opens with the participant's name, current status, an evaluation date, and Pass / Fail buttons.
3. The date defaults to today. Change it only when backdating (e.g. you forgot to record someone yesterday).
4. Tap **Pass** or **Fail**.
5. The dialog closes and the row updates.

### What happens on Pass

- Profile swim status → **Passed**.
- Evaluation date → the date you picked.
- Evaluator → you (the logged-in coach).
- If the kid was pre-registered for a slot, that registration is marked complete behind the scenes — it won't appear as a no-show overnight.

### What happens on Fail

- Profile swim status → **Failed**.
- Evaluation date → the date you picked.
- Evaluator → you.
- Same behind-the-scenes update on any pending registration.

The kid stays on the roster (under "Pending" by default) so they can be re-evaluated. They can also re-register for a future slot if desired.

## No-shows

You do **not** need to mark no-shows. If a pending registration's slot ended more than 8 hours ago, the system automatically flips it to "no show" overnight. No-shows are reviewed by the commerce manager — not your concern.

## Walk-ups

The most common scenario: a parent shows up at the beach with a kid who isn't registered for any swim test, and the kid needs to be tested before joining a paddle program.

1. Find them under the **Unscheduled** heading (or filter by Name if it's a long list).
2. Click **Evaluate** and proceed exactly as you would for a registered kid.

The same form, the same outcome — the only difference is that there's no pre-existing registration to update behind the scenes.
