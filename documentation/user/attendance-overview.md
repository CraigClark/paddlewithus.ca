# Attendance tracking — overview

The club uses attendance tracking to know who was present each day of a paddling program and who picked them up.

Attendance applies only to **paddling programs** — activities where we need to account for kids from arrival through pickup. It does not apply to swim tests.

## Who does what

- **Commerce manager / admin** — enables attendance tracking on a program, reviews history, handles corrections, exports records.
- **Coach** — checks participants in and out on the day of the session.
- **Parent / member** — no direct involvement. They enter emergency contacts and authorised pickup names once on the participant profile; those names drive the coach's check-out list.

## What gets captured

Every day, for every registered participant in a program that has attendance tracking enabled:

- Whether they were checked in, and by which coach at what time.
- Whether they were checked out, and by which coach at what time.
- Who picked them up (selected from the participant's emergency contacts or entered as free text).
- Optional notes on arrival and/or pickup.
- Whether the check-out was recorded without a prior check-in (flagged for review).

Every edit — including resets — is preserved in an audit history for three years before being purged automatically.

## Enabling attendance on a program

Attendance is off by default. A commerce manager toggles it on via the product edit form:

1. Admin → Commerce → Products → edit the program.
2. In the "About this activity" section, tick **Track attendance**.
3. Save.

The program's active sessions will start appearing on the coach attendance page at `/participants/attendance`.

## Accessing records

- **Coach** — today's attendance only, at `/participants/attendance`. No access to past days.
- **Commerce manager** — everything, at `/admin/attendance/history`. CSV export available.
- **Admin** — everything, plus `/admin/config/picc/attendance` to change retention and run manual purges.

## Retention

Attendance records are automatically deleted three years after the attendance date. This is configurable under `/admin/config/picc/attendance` but the default of 1095 days matches the club's governance policy.

The purge runs once per day via cron. A watchdog log confirms how many records were removed on each run.

## What it is not

- Not a real-time "who's at the dock right now" dashboard — it's a per-day record.
- Not a fee-enforcement tool — attendance doesn't affect billing.
- Not a reporting tool for kids' progress — it just captures presence and pickup.
- Not a substitute for coach observation — coaches still make safety decisions in person.
