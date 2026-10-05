# RSVP deadline: fixing the gaps found in the audit

Status: **Built, all five phases** (2026-10-05, recommendations D1 to D8 accepted). How it behaves today: the "RSVP Deadline" section of `CLAUDE.md`. Source: the RSVP-deadline audit (edge cases and gaps) from 2026-10-04. Every "confirmed"
item below was reproduced with a throwaway probe test against the working tree; "by reading" means it was read from the code
but not run.

## 1. What is wrong, in one table

| # | Gap | Evidence | Severity |
|---|---|---|---|
| G1 | **Deadline is read as UTC, entered as Lusaka time.** A host's 18:00 deadline closes at 20:00 Lusaka. Reminder days are counted in UTC too | confirmed | High, affects every host with a deadline |
| G2 | **No deadline means open until 02:00 Lusaka the next day.** Guests can accept after the event has started or finished | confirmed | High |
| G3 | **A guest who already answered cannot change or decline after the deadline** (403). The host's headcount goes stale | confirmed | Medium |
| G4 | **A late submit is a bare 403.** The guest's answers are lost and there is no "RSVP is closed" message. The API returns a raw 403 | confirmed | Medium |
| G5 | **`RsvpSubmissionService::submit()` never re-checks the deadline under its lock**, so a submit that already passed the request check still saves | confirmed | Low (race), but it is why G4 needs a service-level fix |
| G6 | **Changing or removing the deadline does not reset reminder markers.** A guest who got the 7-day reminder never gets one for the new deadline | confirmed | High for reminder users |
| G7 | **Reminders fire only on exactly 7, 3 and 1 days out.** Deadlines 0, 2, 4, 5, 6 days away get nothing that day, and a missed scheduler day is never caught up | confirmed (and by reading for the catch-up) | Medium |
| G8 | **A deadline in the past is accepted on create** with no warning, so the event is created already closed | confirmed on create, by reading on update | Medium |
| G9 | **Minute-precision deadline, microsecond cut-off.** "18:00" refuses a guest at 18:00:30, with no grace for a form that was already open | confirmed | Low |
| G10 | **Invitations can be sent after the deadline.** No send path checks `isRsvpOpen()`, so a guest is invited to a closed form | by reading | Medium |
| G11 | **Guests are never told when a deadline is extended, shortened or removed** | by reading | Low, see section 3 (D7) |
| G12 | **The closed page shows "Deadline was ..." with no timezone;** designed invitations show only the date | confirmed | Low |
| G13 | **`isRsvpOpen()` does not check `is_published`.** Each caller adds it itself, and uncommitted work in the tree is adding it caller by caller | by reading | Medium (drift) |

Not a gap, by design: ticketed events ignore the deadline (`EventController::update()` drops it), and a deadline later than the
event start is already rejected on create and update (equal to the start is allowed).

## 2. How it works today (so the fixes land in the right place)

- `events.rsvp_deadline` is a `datetime` cast, stored **as typed** (naive, the host's local Lusaka time). `config('app.timezone')`
  is UTC, so `now()->lte($event->rsvp_deadline)` compares a real UTC instant to a Lusaka wall-clock number (G1).
- `events.event_date` + `event_time` are also naive Lusaka values, but `Event::startsAt()` correctly applies
  `venueTimezone()` (`config('events.timezone')`, `Africa/Lusaka`). The deadline is the one value that never got that treatment.
- `Event::isRsvpOpen()` is a pure attribute check: not trashed, cancelled or paused, not `isLocked()` (event date before
  *today in UTC*), then the deadline. `isLocked()` is also used for edit locking, redefine charges, pass visibility and the
  Ended status page, so it must **not** be repurposed for the implicit deadline (G2).
- Every guest-facing path asks `isRsvpOpen()`: `StoreRsvpByTokenRequest`, `StoreOpenRsvpRequest`, `GroupRsvpResolver`,
  `Api\V1\RsvpController`, `WhatsAppInboundRsvpService`, the reminder command and the invitation views. Most add their own
  `is_published` check (G13).
- `rsvp:send-reminders` (09:00 Lusaka daily) emails non-responders at `daysUntil in [7, 3, 1]`, with per-guest markers in
  `guests.rsvp_reminders_sent` and a unique `notification_logs` key `rsvp-reminder:{event}:{guest}:{bucket}` (no deadline in it).
- `Event::booted()` already clears the WhatsApp event-reminder markers when `event_date` changes. Nothing does the same for
  the deadline (G6).

## 3. Decisions

Recommendations are the default; each is the owner's call. D1 and D2 change what guests experience, so they are the ones to
confirm before building.

| # | Question | Recommendation |
|---|---|---|
| D1 | How to fix the timezone: **A** keep stored values as typed and interpret them in the venue timezone (like `event_date`/`event_time`), or **B** convert to UTC on write and migrate existing rows | **A.** No data migration, no double-shift risk, consistent with how the event's own date and time work. Every existing deadline will close 2 hours *earlier* than today, which is what its host meant |
| D2 | What closes RSVP when there is **no deadline**: **event start**, end of the event day, or start plus N hours | **Event start** (`startsAt()`), via a new `rsvpClosesAt()`. An explicit deadline can still be set up to the start (the existing rule), so nothing is lost for hosts who set one. If you want walk-up RSVPs on the day, pick "end of event day" instead and G2 shrinks to a timezone fix |
| D3 | May a guest who **already answered** change it after the deadline | **Yes, reductions only, until the event starts:** decline, or lower the headcount. No new accept and no increase, so the deadline still protects the host's catering numbers. Needs a host-visible note on the guest list |
| D4 | Grace for a form that was open when the deadline passed | **60 seconds, on submit paths only** (`RSVP_DEADLINE_GRACE_SECONDS`, default 60). Display and "is it open" stay exact, so the page never shows a form it would refuse, but a slow submit is not lost |
| D5 | Reject a deadline in the past | **Yes on create, and on update only when the deadline value changed**, with the same 5-minute slack `guardEventNotPushedIntoPast` uses. An event whose deadline already passed can still be saved for other edits |
| D6 | Reminder cadence | **Catch-up rule:** send a reminder when the deadline is within a bucket's window and that bucket is unsent, once per run, marking every window already crossed. Fixes 0, 2, 4, 5, 6 days out and missed days. Wording uses the real days remaining, plus a "closes today" line |
| D7 | Notify guests when a deadline changes | **No automatic broadcast** (spam, and it needs consent copy). Show the host a one-line prompt after saving a change that reopens a closed RSVP. Revisit if hosts ask |
| D8 | Invitations sent while RSVP is closed | **Refuse with a message** ("RSVP is closed. Extend the deadline first") for the WhatsApp card and the share-link paths, and show a "RSVP closed" banner on the guest list |

## 4. Phases

Land them in this order. Phase 1 is a prerequisite for most of the others because it introduces the one place the closing
instant is computed.

### Phase 1: one definition of "when does RSVP close" (G1, G2, G9, G13)

1. `Event::rsvpDeadlineAt(): ?Carbon`: the stored naive value parsed in `venueTimezone()`. This is the only place the raw
   attribute is interpreted. Nothing else reads `$event->rsvp_deadline` for a decision.
2. `Event::rsvpClosesAt(): ?Carbon` = `rsvpDeadlineAt() ?? startsAt()` (D2). Null only for an event with no date.
3. `isRsvpOpen(?CarbonInterface $at = null)` uses `rsvpClosesAt()`, keeping the trashed / cancelled / paused checks, and keeps
   the `isLocked()` check as a backstop. It stays a pure attribute check (no queries) and keeps working on unsaved preview
   events.
4. `Event::acceptsRsvps()` = `is_published && isRsvpOpen()`: the one call every **guest-facing submit path** uses. Display and
   preview paths keep `isRsvpOpen()` because a draft preview must still show the RSVP section. Replace the per-caller
   `is_published` additions (including the uncommitted ones in `StoreRsvpByTokenRequest`, `WhatsAppInboundRsvpService` and
   `RsvpController`) with `acceptsRsvps()`. Do this on top of that work, not around it.
5. Submit paths pass the grace (D4): `acceptsRsvps($now->subSeconds(grace))`.
6. Everything that formats or compares the deadline uses `rsvpDeadlineAt()`: the closed page (with a timezone label,
   `Event::rsvpDeadlineLabel()`, G12), the seven invitation layouts (still date-only, through one helper so the layouts stop
   formatting it themselves), `EventResource` (`rsvp_deadline` becomes `rsvpDeadlineAt()->toIso8601String()`, now carrying
   the correct `+02:00`, plus a new additive `rsvp_closes_at`), the reminder command and both `guardRsvpDeadline()` methods.
7. `rsvp:send-reminders` counts days with venue-local dates, not UTC.

### Phase 2: late submits and answers that change (G3, G4, G5)

1. Move the check into the service: `RsvpSubmissionService::submit()` takes the event lock it already takes, re-checks
   `acceptsRsvps()` with the grace, and throws a new `RsvpClosedException`. A parameter (`enforceDeadline: false`) is left for
   any host-initiated path. Audit every caller of `submit()` first and list them in the PR.
2. Map the exception once: web token / open / group paths redirect to the closed page with a flash
   ("The RSVP deadline passed while you were filling this in"); the API returns **403 with `code: rsvp_closed` and a
   `message`** (same status as today, so the contract is additive); WhatsApp already replies "RSVP is closed".
3. Reductions after the deadline (D3): `StoreRsvpByTokenRequest::authorize()` stops aborting for a guest with an existing RSVP
   and the service allows only a transition that does not take more seats than the guest holds. The closed page shows a
   "Change or cancel your RSVP" action for those guests until the event starts. Seat-pool and approval rules are unchanged.

### Phase 3: reminders follow the deadline (G6, G7)

1. `Event::booted()` `updated` hook: when `rsvp_deadline` changes (including to null), clear `rsvp_reminders_sent` for the
   event's guests, mirroring the `event_date` hook for WhatsApp.
2. Put the deadline in the `notification_logs` idempotency key (`rsvp-reminder:{event}:{guest}:{bucket}:{deadlineDate}`), the
   same way the event reminders include the event date. Without this the unique key blocks the new send even after the
   markers are cleared.
3. The catch-up rule (D6) replaces the exact-day match, and `RsvpReminderNotification` gets a "closes today" line.
4. Still Pro+ only, still non-responders with an email, still honouring the opt-out.

### Phase 4: validation and host-facing warnings (G8, G10, D7)

1. `StoreEventRequest` / `UpdateEventRequest`: a new `guardRsvpDeadlineNotInPast()` (D5).
2. After a save that changes the deadline of a closed event so that RSVP reopens, flash the "RSVP reopened, send reminders?"
   prompt (D7).
3. `GuestController` invitation paths (every one: WhatsApp single, any bulk send) refuse while RSVP is closed (D8), and the
   guest list and event page show an "RSVP closed" banner with the closing instant.

### Phase 5: tests and docs

1. Tests (section 5).
2. `CLAUDE.md`: a short "RSVP deadline" section: the venue-timezone rule, `rsvpClosesAt()` / `acceptsRsvps()` as the only
   gates, the grace, and the reminder markers.
3. Run `./vendor/bin/pint` on touched files and the full `composer test`.

## 5. Tests

Fake time with `Carbon::setTestNow()` and write each in terms of the **venue** clock, since that is the bug.

- **G1:** a deadline typed as 18:00 is open at 17:59 Lusaka (15:59 UTC) and closed at 18:01 Lusaka; `EventResource` emits `+02:00`.
- **G2:** no deadline: open before the start, closed after it (and at 01:30 the next day); `isLocked()` behaviour unchanged.
- **G3:** after the deadline a guest with an accepted RSVP can decline and can lower the count, cannot raise it, and a guest with
  no RSVP is refused.
- **G4/G5:** a late web submit lands on the closed page with the flash; the API returns 403 with `code: rsvp_closed`; calling
  `submit()` directly after the deadline throws; a submit within the grace succeeds; WhatsApp reply unchanged.
- **G6:** deadline moved later, same guest gets the 7-day reminder again; deadline removed then re-added reminds afresh; the
  idempotency key differs by deadline.
- **G7:** deadlines 0 to 8 days out each send exactly one reminder on first run, none on the second; a skipped day is caught up;
  none after the deadline.
- **G8:** past deadline rejected on create; on update rejected only when changed; unrelated update on an already-closed event
  still saves; equal-to-start still allowed.
- **G10:** invitation send refused while closed, allowed again after extending.
- **G13:** every guest-facing path refuses an unpublished event through `acceptsRsvps()`; the draft preview still shows the form.
- Boundaries from the original list: exactly at the deadline, +1 second inside the grace, +61 seconds outside it.

## 6. Rollout and risk

- **No data migration (D1-A).** Hosts do not need to re-enter anything.
- **Behaviour change on release:** every existing deadline closes 2 hours earlier in absolute time (to what its host typed), and
  events with no deadline stop taking RSVPs at the start time instead of the next morning (D2). Mention both in the release
  note; consider emailing hosts of events whose deadline falls in the next 48 hours.
- **API:** `rsvp_deadline` keeps its field name but now carries a real offset; `rsvp_closes_at` is new; the late-submit 403 gains
  a `code` and `message`. All additive. No Android client is shipped, so this is the cheapest time to change it.
- **Conflict risk:** the working tree currently has uncommitted edits to `Event.php`, `RsvpController`,
  `StoreRsvpByTokenRequest` and `WhatsAppInboundRsvpService`. Commit or rebase those before Phase 1.

## 7. Out of scope

- Broadcasting deadline changes to guests (D7), and a "close RSVPs now" button (a past deadline is rejected, so hosts who want
  to close early set the deadline to the next quarter hour).
- Per-event timezones. The platform is single-timezone (`events.timezone`); fix that separately before selling across borders.
- Ticketed events: the deadline stays ignored for them.
