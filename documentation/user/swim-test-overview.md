# Swim test system — overview

Petrie Island Canoe Club requires all participants under 15 to pass a swim test before water activities. Adults (15+) must provide an attestation that they can swim. This document explains who does what.

## Who does what

### Parents and members
- **Under 15**: register kids for a swim test session at `/program/swim-test`.
- **15 or older**: submit a swim attestation for themselves or adult family members.
- Both are free.

### Coaches
- Evaluate kids at the pool using the swim test roster at `/swim-test-roster`.
- Mark each kid **Pass** or **Fail**. Leave alone if they didn't show up (the system will flip it to "no show" automatically 8 hours after the slot ends).

### Commerce manager
- Reviews no-shows at `/admin/swim-test-no-shows`.
- Marks each no-show **Resolved** once the absence has been handled (payment received, absence excused, etc.). Records a reason.
- Creates product variants for swim test sessions (see [creating-sessions.md](creating-sessions.md)).

### Administrators
- Can manually override any participant's swim status on their profile.
- Can cancel registrations on behalf of a parent (through the commerce order admin).

## Status values explained

Each participant has a **swim status** on their profile. The possible values are:

| Status | Meaning |
|---|---|
| Swim test required | Default — kid needs a test |
| Swim test passed | Passed a pool test this season |
| Attestation on file | Adult has attested they can swim |
| Exempt | Admin override (e.g. Special Olympics) |

On the participant roster, the pool icon appears if the participant meets any one of passed / attested / exempt.

## Season and annual reset

All swim status, test dates, attestation records, and evaluator references are cleared on January 1 each year. Every participant starts fresh. The clearing runs automatically via cron on the first cron run of the new year.

## Age rule

- **Under 15 as of December 31 of the current year** → swim test required
- **15 or older as of December 31 of the current year** → attestation

For example: in 2026, anyone born **after December 31, 2011** needs a test. Anyone born on or before that date uses attestation.
