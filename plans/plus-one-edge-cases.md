# Plus-one edge cases

Status: all phases built (see `CLAUDE.md` → Plus-ones for what shipped, including deviations: the limit error stays on
`status`, and "Remove plus-one" is web only). Origin: edge-case review of plus-one handling. Related: `plans/rsvp-deadline-fixes.md`,
`plans/group-rsvp-links.md`.

## Model today (do not change)

A plus-one is `rsvps.attendee_count = 2`. Max per guest = `events.allow_plus_one` AND `guests.plus_one_allowed` → 2, else 1
(`Event::maxAttendeeSlotsForGuest()`). `RsvpSubmissionService::submit()` clamps and enforces the guest limit under the event
row lock; `EventAttendance::heldSeats()` is the single seat count. An invitation RSVP is only ever 1 or 2.

## Decisions to confirm before Phase 1

1. **Turning plus-ones off after some are confirmed.** Recommended: **grandfather** — existing `attendee_count = 2` rows keep
   their seat; the toggle only stops *new* plus-ones. The host sees a count and can reduce individuals by hand. Alternative
   (block the toggle) is rejected: it traps the host with no way out.
2. **Enabling plus-ones for existing guests.** Recommended: bulk action "Allow plus-one" on the guest list, plus a prompt on
   save when the event toggle goes on and guests exist with `plus_one_allowed = false`.

## Phase 1 — Disabling plus-ones later (gap 1)

- `UpdateEventRequest` / `EventController::update()` (web) and `Api\V1\EventController::update()`: when `allow_plus_one`
  goes true → false, count accepted, non-rejected RSVPs with `attendee_count = 2`. If > 0, flash `plus_ones_kept` with the
  count (web); API adds an additive `plus_ones_remaining` field to the response. No block.
- `Guest::hasEntryPassFor` / `GuestPassCard::partyLabel()` keep reading `attendee_count`, so a grandfathered plus-one still
  prints "Guest + 1" and check-in still expects two. State this in the flash copy.
- `RsvpSubmissionService::submit()`: a guest holding 2 who re-submits **unchanged** (count = `previousHeldCount`) must not
  fail. Fix both layers:
  - `ValidatesRsvpPayload::rsvpFieldRules()` — `$max` becomes `max(allowed, currentHeld)` where currentHeld is the guest's
    existing accepted count (pass the guest's RSVP in, alongside `$plusOneAllowed`).
  - service clamp uses the same effective max, so a message-only edit does not drop the plus-one.
- Per guest: `GuestController::update` and `Api\V1\GuestController::update` — when `plus_one_allowed` goes true → false and
  the guest holds 2, keep it, show "Plus-one already confirmed; remove it from their RSVP if needed". Add a host action to
  reduce a guest to 1 (reuse `submit()` with `enforceDeadline: false`, reduction only) and email the guest.
- Tests: toggle off with confirmed plus-ones keeps seats; unchanged re-submit succeeds; message edit keeps count; guest
  reducing to 1 works; going back up to 2 is refused; deadline-passed reduction still allowed.

## Phase 2 — Enabling plus-ones later (gap 2)

- Guest list: bulk action `allow_plus_one` in `GuestBulkActionController` (web) and the matching API action, scoped to the
  event's guests, honouring the current filters. Audit via the existing bulk-action pattern.
- Event save prompt: when `allow_plus_one` goes false → true and guests with `plus_one_allowed = false` exist, flash a
  "N guests can't bring a plus-one yet. Allow for all?" with a one-click form posting the bulk action.
- Import (`EventGuestsImport`) and `Api\V1\RsvpController` open-RSVP path create guests with `plus_one_allowed = false`.
  Decide per path: import gets an optional `plus_one` column (default false); leave the API open-RSVP path as is (document).
- Seat-pool groups: `GroupRsvpController` / `StoreGroupRsvpRequest` use `allow_plus_one ? 2 : 1` per person, so enabling
  silently doubles the pool burn. Show the host "each person may take 2 of your N seats" on the group link panel when
  enabled. No behaviour change.
- Already-sent invitations and reminders: no automatic broadcast (same posture as `rsvp_reopened`). Flash a prompt pointing at
  the guest list's reminder action. Landing/RSVP copy already reads `allow_plus_one` live.
- Tests: bulk action sets flag only for the event's guests; toggle on then guest can pick 2; group link max follows toggle.

## Phase 3 — Align every channel with one rule (gap 3)

One source of truth: add `Event::maxAttendeeSlotsForGuest()` use to **every** entry point; no channel hardcodes 1 or 2.

- `WhatsAppInboundRsvpService:85` hardcodes `attendee_count = 1` on accept: a guest holding 2 who replies "yes" is silently
  downgraded. Fix: on Accepted keep the existing count (min 1); a bare "yes" never changes seats. **Verify first** with a
  failing test.
- API (`Api\V1\RsvpController`, token and open store): validation already goes through `ValidatesRsvpPayload`; add a test
  that `attendee_count: 5` returns 422, not a stored 2. If any path skips the trait, route it through it.
- Service clamp becomes a defence: if the clamp *changes* the requested count, throw a validation error instead of silently
  storing a different number, except where `previousHeldCount` legitimately raises the max (Phase 1).
- `StoreGroupRsvpRequest` keeps its own rule (1 or 2 per person from the pool) — add a test that the cap matches
  `allow_plus_one`.

## Phase 4 — Zero plus-ones wording (gap 4)

- Wording only: the form says "Number attending" with options "Just me" / "Me + 1 guest" instead of 1 / 2. Applies to
  `rsvp/*`, `events/invitations/sections/rsvp.blade.php` and the API docs (`attendee_count` includes the guest).
- Make the contract consistent: Accepted with 0 stays a validation error (already); the service's `max(1, …)` is documented as
  unreachable via requests.

## Phase 5 — Limit exceeded UX (gap 5)

- `submit()` error attaches to `attendee_count` (not `status`) and, when exactly one seat is left and the guest asked for 2,
  says "Only 1 seat is left — you can RSVP for yourself only". Keep the existing message for 0 left. Guest keeps any seat
  they already hold: say so when `previousHeldCount > 0`.
- Web: on that error, re-render with the count preselected to what fits. API: add `seats_left` to the 422 `errors` meta
  (additive).
- Verify, then fix if needed: `GuestController::store` and the API store/import host paths — do they enforce
  `guest_limit` for manually added guests? (They add guests, not RSVPs, so likely fine; confirm no path creates an Accepted
  RSVP outside `submit()`.)
- Out of scope: waitlist for freed seats (rejection frees seats but nobody is re-offered). Log as a separate plan.

## Tests to add (new file `tests/Feature/PlusOneEdgeCasesTest.php`)

Toggle-after-RSVP both directions; per-guest flag change after RSVP; unchanged re-submit with plus-ones off; WhatsApp "yes"
keeps 2; API 422 on over-max; bulk allow; limit boundary (1 seat left, ask 2, existing 1 → ask 2 allowed only if 1 free);
concurrent submits for the last seat (existing lock). Build deadline fixtures per `CLAUDE.md` RSVP Deadline rules (UTC
`setTestNow`).

## Docs

On completion add a "Plus-ones" section to `CLAUDE.md` (grandfather rule, the one-source max rule, bulk action) and update
the Privacy/FAQ copy only if wording about plus-ones exists there (grep `plus` in `resources/views/legal`).

## Order and risk

Phase 3 first (silent data loss, smallest change), then 1, 2, 5, 4. Everything is additive for the Android API contract.
No migrations needed.
