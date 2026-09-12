# Payment Schedule Engine

## Payment vs. Payment Schedule

A **Payment Schedule** is an _obligation_: "this participant is expected to pay this amount for this period." It is generated ahead of time and never represents money that has actually moved. A **Payment** (implemented in an earlier phase, processing logic out of scope here) is the _actual_ payment attempt against a schedule. This phase only generates and manages schedules — no payment processing, gateway, or transaction logic is touched.

```
Organization → Class → Class Participant → Payment Schedule → Payment
```

## Existing implementation reused

`payment_schedules` table/migration, `PaymentSchedule` model, `PaymentSchedulePolicy`, and `PaymentScheduleController` (CRUD) already existed and were reused unchanged in their authorization/CRUD behavior. This phase added the **generation engine** on top: `PaymentScheduleService`, model scopes, the `payment-schedules:generate` command, and the class-activation integration point.

## Frequency rules

Frequency comes from `class_payment_settings.payment_frequency` (`weekly`, `fortnightly`, `monthly`) — never hardcoded per schedule.

| Frequency     | Period length                                                                         |
| ------------- | ------------------------------------------------------------------------------------- |
| `weekly`      | 7 days (`period_start` to `period_start + 6 days`)                                    |
| `fortnightly` | 14 days (`period_start` to `period_start + 13 days`)                                  |
| `monthly`     | One calendar month, using `addMonthNoOverflow()`/`subDay()` — **not** a fixed 30 days |

Monthly periods use Carbon's "no overflow" month arithmetic specifically to avoid the classic bug where `Jan 31 + 1 month` becomes `Mar 3` instead of clamping to the last valid day of February. E.g. a class starting `2026-01-31` produces the period `2026-01-31 → 2026-02-27` (not into March), and a class starting `2026-12-20` correctly crosses the year boundary to `2027-01-19`.

## Period calculation & generation horizon

Schedules are **not** generated for a class's entire lifetime up front — an open-ended class (`end_date = null`) would otherwise produce an unbounded number of rows. Instead, `PaymentScheduleService::generateForClass()` generates a configurable horizon: **the current period plus the next N periods** (`PaymentScheduleService::DEFAULT_HORIZON_PERIODS = 3`, overridable per call or via `--horizon=` on the Artisan command).

- The anchor period is computed from `class.start_date` (or, if null, from _today_ — documented default for classes without a start date).
- If the anchor is in the past relative to today, it is fast-forwarded to the period that currently contains today (so a class running for a year doesn't regenerate its entire past history on every run — only the current + upcoming periods are ever produced).
- If `class.end_date` is set and a period would start after it, generation stops there. A class whose `end_date` is already in the past generates nothing.

## Due date

`class_payment_settings` has no configurable "due day" field. **Chosen default rule (centralized in `PaymentScheduleService::calculateDueDate()`):** the due date equals the period's `period_start` — the payment is due at the start of its period. This is a single, documented, easily-adjustable rule rather than logic scattered across the codebase; a future phase could add a `due_day_offset` column to `class_payment_settings` and change this one method.

## Amount snapshot

`required_amount` is copied from `class_payment_settings.required_amount` **at the moment a schedule is generated** and stored directly on the `payment_schedules` row. It is never recalculated from the current setting afterward:

- Changing a class's price after schedules exist does not alter any existing schedule.
- The very next generation run (new period) uses whatever the setting's amount is _at that time_.

## Participant eligibility

Only `class_participants` rows where `participant_type = 'student'` **and** `status = 'active'` receive schedules. Sponsors are not billed directly (they pay against a student's schedule via the existing `Payment.payer_id`, unrelated to schedule ownership), so sponsor participant rows never receive their own schedules. `inactive`/`removed` participants receive no _new_ schedules, but any schedules generated before their status changed are left untouched — historical obligations are never deleted or altered by a later participant status change.

## Status lifecycle

Every generated schedule starts as `upcoming`. This phase does not implement the `upcoming → pending → overdue` transition (that belongs to the reminder/overdue tooling from an earlier phase) or the `pending → partially_paid → paid` transition (payment processing, out of scope). Model scopes (`scopeUpcoming`, `scopePending`, `scopeOverdue`, `scopePaid`, `scopeForParticipant`, `scopeForClass`, `scopeForOrganization`) are provided for later phases to query by status/ownership without duplicating query logic.

## Idempotency

Running generation any number of times never creates duplicate schedules:

- **Database-level:** `unique(class_participant_id, period_start, period_end)` on `payment_schedules` (added this phase, along with indexes on `class_id`, `class_participant_id`, `due_date`, `status`).
- **Application-level:** `PaymentScheduleService` uses `firstOrCreate()` keyed on that same triple, so a second call simply finds the existing row and does nothing.
- The date columns use the `date:Y-m-d` cast format specifically so the plain `Y-m-d` strings used in `firstOrCreate()`'s lookup always match what was actually stored (a bare `date` cast serializes to a full datetime string on write, which would silently break the uniqueness lookup).

## Class activation integration

`POST /api/v1/admin/classes/{class}/activate` is the integration point added this phase:

1. Authorizes via the existing `ClassModelPolicy::update` ability (`class.update` permission + `isOrganizationAdmin`) — an admin can only activate classes in organizations they administer.
2. Sets `status = active`.
3. Calls `PaymentScheduleService::generateForClass()`.
4. Both steps run inside one `DB::transaction()` — if generation throws, the class's status change is rolled back too.

This phase did **not** rebuild class management — `ClassController`'s existing CRUD (`index`/`store`/`show`/`update`/`destroy`) is untouched; `activate()` is a new, narrowly-scoped addition.

## Manual generation command

```
php artisan payment-schedules:generate [--horizon=N]
```

Iterates every class with `status = active` **and** an existing payment setting, generating any missing schedules (safe to run repeatedly). Scheduled daily at 00:10 in `routes/console.php`, alongside the existing `payment-schedules:mark-overdue` (00:05) and `payments:send-reminders` (08:00) commands from earlier phases. Not exposed as a public API — Artisan/scheduler only.

## Edge cases verified by tests

Class without payment settings, class without participants, class with no `start_date`, class with `end_date` in the past, inactive/draft class, duplicate generation, amount changed after schedule creation, participant removed after schedules exist, month-end start dates, and a period crossing a year boundary — see `tests/Feature/Services/PaymentScheduleServiceTest.php` and `tests/Feature/Api/ClassActivationTest.php`.
