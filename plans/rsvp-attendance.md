# RSVP attendance — gap review and plan

**Status: all five phases are BUILT.** Tests: `RsvpAttendanceTest`, `RsvpLastSeatConcurrencyTest` (MySQL only, never run locally). Left as is: a group's seat pool can still be lowered below what is taken (seats just read as none left), and the guest-list cap (A12) is documented in `CLAUDE.md` only.

Scope: the fourth block of the RSVP edge-case list.

- Guest accepts with zero attendees
- Guest enters a negative number
- Guest enters an extremely large number
- Guest adds more attendees than allowed
- Guest's plus-one exceeds the event guest limit
- Two guests simultaneously consume the last available space

Follows `plans/plus-one-edge-cases.md` (the maximum rule, `GuestLimitReachedException`) and
`plans/rsvp-submission-edge-cases.md`. Code read: `ValidatesRsvpPayload`, `StoreRsvpByTokenRequest`,
`StoreOpenRsvpRequest`, `StoreGroupRsvpRequest`, `SetGuestRsvpRequest`, `RsvpSubmissionService`, `OpenRsvpService`,
`GroupRsvpService`, `WhatsAppInboundRsvpService`, `EventAttendance`, `GuestGroup::seatsRemaining`,
`GuestLimitNotBelowConfirmed`, `GuestLimitReachedException`, `bootstrap/app.php`, `rsvp/partials/form-fields`,
`rsvps` migration, `GuestLimitEdgeCasesTest`.

## What happens today

The count is checked twice: by the form request (`ValidatesRsvpPayload`, group and host requests have their own
copies) and again by `RsvpSubmissionService::submit()`, which refuses an out-of-range count and never clamps. Capacity
is checked in `submit()` under a row lock on the event, taken first by every write path (token, open, group, host
override, WhatsApp), so the checks and the write see one serialised view.

| Case | Today |
|---|---|
| Accept with 0 | Refused: "Choose between 1 and N attendee(s)". Nothing saved. |
| Negative | Refused, but by **two** rules (see A2). Nothing saved. |
| Huge number | Refused (`integer` fails on overflow). Nothing saved, wrong wording (A3). |
| More than the guest may bring | Refused with the same sentence; the form only offers valid options, so only a tampered or stale form reaches it. |
| Plus-one over the event limit | `GuestLimitReachedException`: names the seats left, says the current RSVP is unchanged, the form comes back preselected to what fits. Covered by `GuestLimitEdgeCasesTest`. |
| Two guests, last seat | Serialised by the event lock; the second gets the limit message. **Not proven by any test** (A9). |

## Gaps

Severity: **H** = wrong outcome or a limit that can be passed, **M** = confusing or unproven, **L** = polish.

### Zero, negative and huge counts

| # | Sev | Gap |
|---|---|---|
| A1 | L | **Zero on Accepted is a contradiction, and the error doesn't say how to fix it.** "Choose between 1 and 1 attendee(s)" for a solo guest reads as nonsense, and a guest who chose "Attending" with "Not attending" in the count is never told to change the answer instead. |
| A2 | M | **A negative number gets two errors, one of them technical.** `min:0` on the field means `-1` fails Laravel's default "The attendee count field must be at least 0." and then the closure also runs (no `bail`) and adds the 1..N sentence. `min:0` is also the wrong floor for an accepting guest. The group and host requests use `min:1` and the default message. |
| A3 | M | **An extremely large number is refused with the wrong sentence.** `99999999999999999999` fails `integer`, which carries the message "Please say how many people are coming." (it implies the guest left it blank). The closure still runs and `(int)` of the string saturates to `PHP_INT_MAX`, adding a second error. A value just inside the int range (`4294967296`, over the `unsignedInteger` column) only fails because of the closure, so the service range check is the one thing standing between it and MySQL strict mode. |
| A4 | L | **Odd numeric shapes are accepted or fail unpredictably.** `" 2"`, `"+2"` and `"-0"` pass `integer` and are cast; `"2.0"`, `"1e0"` and `"٢"` fail it with the same "say how many" message. Harmless (the value is cast and range-checked) but untested, and a JSON client sending `2.0` or `"2"` gets different results. |
| A5 | L | The **host override** and **group link** requests have their own bounds (`max:2`, `max:$max`) and wording, separate from `ValidatesRsvpPayload`, so the three channels give three different sentences for the same mistake. |

### More than allowed, and the event limit

| # | Sev | Gap |
|---|---|---|
| A6 | M | **A group seat pool refusal is less helpful than the event limit's.** Asking for 2 seats with 1 left says "This group has no seats left", which is false, and doesn't offer the one that fits or restore the count. The event limit already does this (`GuestLimitReachedException`); the pool should use the same exception (`seatsLeft`, preselect, API `seats_left`). |
| A7 | M | **Two limits, one message at a time.** A guest in a pooled group can be refused by the event limit while the pool has room (or the reverse). The first check wins and the message names only that limit; "fix it and it fails again on the other one" is possible. Both should be evaluated and the smaller figure reported. |
| A8 | M | **Lowering a limit races with accepting.** `GuestLimitNotBelowConfirmed` (and the matching seat-pool rule) sums held seats with **no lock** and the event update doesn't take the row lock `submit()` uses, so a host saving a lower limit at the moment a guest accepts can leave the event over its own limit. Rare; the effect is that someone confirmed is above the cap and the host sees a number they refused to allow. |

### The last seat

| # | Sev | Gap |
|---|---|---|
| A9 | **H** | **No test proves the last-seat race.** SQLite (the suite) ignores `lockForUpdate()`, so a sequential test would pass without the lock. The only evidence is the code reading. There is a MySQL CI workflow (`10a09d1`) that could run a real two-connection test. |
| A10 | M | **A burst of submits queues on one event row and can time out.** Every submit on a popular event waits for the one before it. Under MySQL a lock wait over `innodb_lock_wait_timeout` (50 s default) or a deadlock raises a `QueryException`; the transaction isn't retried (`DB::transaction()` is called without attempts), so the guest gets the generic 500. For a deadlock a retry would succeed. |
| A11 | L | **The loser's experience.** The second guest gets the limit message, but there is no waitlist (already listed as not built in `CLAUDE.md`), and the page doesn't tell them to call the host the way the group pool and the guest-list-full messages do. |
| A12 | L | **Open RSVP when the guest list is full** is a separate count (`hasReachedGuestCapacity()`, **guests** on the plan cap, not **seats**). The cases are handled under the lock but the two limits are described together nowhere, so a host reading "full" can't tell which one fired. |

## Decisions needed

| # | Question | Recommendation |
|---|---|---|
| D1 | Should "0 attending" on an Accepted answer be refused or read as a decline? | **Refuse**, with a sentence that says to pick "Not attending" as the answer. Never silently turn a yes into a no (the service already refuses instead of clamping). |
| D2 | Waitlist for the guest who loses the last seat? | **Not now.** Show "Call the host" plus the host contact number; a waitlist is its own feature with approval and notification rules. |
| D3 | Retry a deadlock/lock timeout? | **Yes**, up to 3 attempts for deadlocks, and turn a final lock timeout into "We're busy, please try again" rather than a 500. |
| D4 | Use the MySQL CI job for a real concurrency test? | **Yes**, behind a group that only runs there; keep a sequential simulation for SQLite. |

## Phases

### Phase 1 — One validation path for the count (A1 to A5)
1. Move the count rules into one place used by token, open, group and host requests: `bail`, `integer`, a sane ceiling
   before the cast (reject anything over, say, 4 digits), then the range closure. Floor of **1** for Accepted.
2. Messages: blank / not a whole number → "Please choose how many people are coming."; below 1 → "Choose at least 1, or
   answer Not attending."; above the maximum → "You can bring at most N" (just "yourself only" when N is 1).
3. Cast with `filter_var` after the rule so `" 2"` and `"+2"` behave the same on every channel; document that `2.0` is
   refused.
4. Tests: 0, -1, -0, 3, 2.5, `"abc"`, array, 10^20, 4294967296, 99999999999999999999, JSON string vs int, on token,
   open, group and host. Assert one error each, the right text, and no row or history written.

### Phase 2 — Group pool message and combined limits (A6, A7)
1. `GuestLimitReachedException::forSeats()` (or a sibling) used for the pool, with the group's seats left.
2. Compute both limits, report the lower, and say which one it is.
3. Tests: 1 left asked 2 (preselect, `seats_left`), pool has room but event full, event has room but pool full.

### Phase 3 — Lock the lowering of a limit (A8)
1. Take the event row lock in `EventController::update()` / the API update when `guest_limit` changes and recheck
   `GuestLimitNotBelowConfirmed` inside it; same for a group's `seat_limit`.
2. Test the rule against a held-seats fixture (sequential, since SQLite can't race it).

### Phase 4 — The last-seat race, proven (A9, A10, A11)
1. A concurrency test that forks two processes (or two connections) submitting for the last seat; asserts exactly one
   Accepted, one refusal, and `heldSeats <= guest_limit`. Runs only on MySQL (group `concurrency`); wire it into the
   MySQL workflow.
2. A sequential test for SQLite that submits the second request while the first is held in an open transaction, as far
   as the driver allows.
3. `DB::transaction($callback, 3)` in `submit()` and the two wrappers, and render a lock timeout as a friendly
   "try again" (web: back with the answers kept, JSON: 503 `{code: "rsvp_busy"}`).
4. The limit message on the guest pages gets the host contact line (A11).

### Phase 5 — Documentation (A12)
Add a short section to `CLAUDE.md` Plus-ones: the count rule's single home, the two limits (seats vs guest-list
size), and that `lockForUpdate()` is a no-op on SQLite.

## Out of scope

- Ticketed events: capacity is the ticket type's, with its own holds (`TicketPurchaseFlowTest`).
- A waitlist (D2).
- Bulk accept/decline from the host's guest list.
