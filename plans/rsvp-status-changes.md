# RSVP status changes — gap review and plan

Scope: the third block of the RSVP edge-case list.

- Guest changes Accepted → Declined
- Guest changes Declined → Accepted
- Guest changes Maybe → Accepted
- Host wants to override an RSVP
- RSVP is changed after the deadline
- RSVP is changed after the event starts

Follows `plans/rsvp-submission-edge-cases.md` and `plans/rsvp-token-edge-cases.md`. Code read:
`RsvpSubmissionService`, `RsvpApprovalService`, `Rsvp`, `Guest::hasEntryPassFor`, `Event` (`isRsvpOpen`,
`acceptsRsvpReductions`, `canReduceRsvp`, `isCheckInOpen`), `CheckInService`, `PublicCheckInController`,
`GuestController`, `GuestBulkActionController`, `CommunicationService::dispatchRsvpNotifications`,
`NewRsvpReceivedNotification`, `RsvpConfirmationNotification`, the `rsvps` migrations.

## What happens today

A guest changes their answer by posting again; `submit()` is the one place that writes it, under the event lock.

| Change | Before the deadline | After the deadline, before the event starts | After the event starts |
|---|---|---|---|
| Accepted → Declined | Saved. Seats are released, the pass is withdrawn, approval reset to "not required". The guest gets a "Not attending" confirmation; the host gets a "responded Decline" alert. | Allowed on the personal link and WhatsApp (a reduction); refused on the open form. | Refused (`isLocked` / reductions close at the start). |
| Declined → Accepted | Saved. Seat limit and seat pool are checked. With host approval on, it becomes **Pending** and the pass is withheld until approved. | Refused ("never declined to anything"). | Refused. |
| Maybe → Accepted | Same as Declined → Accepted. | Refused. | Refused. |
| Accepted → Maybe | Saved. | Allowed (a reduction). | Refused. |
| Host overrides a guest's answer | **Not possible.** The host can approve or reject a *Pending* RSVP, remove one plus-one, or delete the guest. | Same. | Same. |

The deadline and start rules are enforced under the lock and are well tested (`RsvpDeadlineTest`,
`RsvpClosedHandlingTest`).

## Gaps

Severity: **H** = wrong outcome at the door or for money, **M** = confusing or lossy, **L** = polish.

### Accepted → Declined, and what it leaves behind

| # | Sev | Gap |
|---|---|---|
| S1 | **H** | **Check-in ignores the RSVP completely.** `CheckInService::confirm()` only asks whether the door is open. A guest who **declined**, a guest the host **rejected**, one still **Pending**, and one who **never answered** all check in by token or by id. Their printed or emailed pass (the PDF/PNG attachments stay valid forever; `hasEntryPassFor()` only controls what the *page* shows) still scans. Door staff see no RSVP state in the scan result at all. |
| S2 | M | **A checked-in guest can still decline or reduce.** Check-in opens 24 h before the start (`events.check_in.opens_hours_before`) and guest reductions stay open until the start, so for up to a day a guest can be marked "checked in" and "Declined" at once, and their seats are freed while they are standing in the venue. `submit()` never looks at `checked_in_at`. |
| S3 | L | A guest who declines keeps their **table assignment** (`guests.event_table_id`), and `assign_table` in the bulk action does not filter by RSVP. Whether the tables page counts only accepted guests needs checking. |

### Declined / Maybe → Accepted, and host approval

| # | Sev | Gap |
|---|---|---|
| S4 | M | **Toggling resets a host's decision.** Approval only survives a re-submit *while already Accepted*. An **Approved** guest who goes Accepted → Maybe → Accepted becomes **Pending** again: the pass is withdrawn, the host is alerted again, and the guest gets no message saying why their pass disappeared. |
| S5 | M | **A rejected guest can force a fresh review.** Rejected → Declined → Accepted wipes the rejection (status, reviewer and note) and queues a new Pending request. The host's decision is not final, and the note explaining it is gone. |

### Host override

| # | Sev | Gap |
|---|---|---|
| S6 | M | **The host cannot set or correct a response.** "She phoned to say she can't come", "he is bringing his wife after all", a walk-in the host knows will attend: the only tools are approve / reject on a Pending RSVP, remove a plus-one, and *delete the guest* (which loses the record and the history). `RsvpSubmissionService::submit()` already has an `enforceDeadline: false` flag "for a host-initiated path (none today)", so the service side was anticipated. The bulk actions (`assign_group`, `assign_table`, `mark_sent`, `delete`, reminders, `allow_plus_one`) have no "mark attending/declined". |
| S7 | M | **After the event starts nobody can change anything.** Guests cannot cancel (reductions close at the start) and there is no host path (S6), so a late no-show or a walk-in stays recorded as it was. |

### Seeing what changed

| # | Sev | Gap |
|---|---|---|
| S8 | M | **There is no history.** `rsvps` has one row per guest with `updated_at`. Nothing records the previous answer, who changed it (guest on the web, WhatsApp reply, API, host) or when. The host's alert says "X responded Decline" with no hint that it is a *change* or what it was before, which is what a caterer needs to know. |

### Not covered by tests

| # | Sev | Gap |
|---|---|---|
| S9 | M | There is no test matrix for the transitions themselves: each pair of answers × approval on/off × before deadline / after deadline / after start × guest limit and seat pool × channel (personal link, open form, WhatsApp, API). The deadline tests cover reductions; nothing covers S1, S2, S4 or S5. |

## Plan

### Phase 1: pin today's behaviour (S9): BUILT (`RsvpStatusTransitionsTest`; the `pinned_` tests are flipped by Phase 3 and 5)

`tests/Feature/RsvpStatusTransitionsTest.php`, data-provider driven.

1. Each transition in the table above, with and without `require_rsvp_approval`: stored status, seats, `host_approval_status`, pass eligibility, notifications sent.
2. Each of those after the deadline and after the start, for the personal link, open form and WhatsApp.
3. Seat limit and seat pool on Declined / Maybe → Accepted.
4. Tests for S1, S2, S4, S5 that **assert the current behaviour** and are flipped by the phases below.

### Phase 2: the door respects the RSVP (S1, S2): BUILT (`CheckInRsvpState`, `CheckInNotAllowedException`, `RsvpCheckedInException`, `CheckInRsvpStateTest`)

1. `CheckInService::confirm()` gets the RSVP state. The scan payload gains `rsvp_status` and `rsvp_note`; the web and staff scanners and the API show it.
2. **Declined** and **host-rejected** are refused with a clear reason (new `CheckInNotAllowedException`, same rendering as `CheckInClosedException`). **Pending**, **Maybe** and **no answer** are allowed but flagged "RSVP not confirmed", so staff can still let a walk-in through. (Decision 1, made.)
3. A host-side "check in anyway" for a refused guest is a separate, logged action, not the scanner's default.
4. `submit()` refuses a decline or reduction once `checked_in_at` is set, from guest channels only (`RsvpCheckedInException`, rendered like `RsvpClosedException`: "You are already checked in. Ask the host to change your response."). The host path (Phase 5) is exempt.
5. Tests: declined guest's old PDF pass is refused at the door; Maybe guest checks in with a flag; checked-in guest cannot decline.

### Phase 3: a host's decision sticks (S4, S5) : BUILT, not yet run (tests are run once after all phases; `approved_seats`, rejection is final, extra seat only)

1. **Approved is per guest, not per answer.** Re-accepting after Declined / Maybe keeps **Approved** when the seats asked for do not exceed the approved count (store `approved_seats` on approval); asking for more reopens review for the extra only.
2. **Rejected is final** for that guest unless the host reverses it: a rejected guest who re-accepts sees "The host has already declined this request" and nothing is queued. The note is kept. (Decision 2, made.)
3. When a change does reopen review, the guest is told ("Your response is back with the host, your pass will return once they approve it").
4. Tests: Approved → Maybe → Accepted keeps the pass; Rejected → Declined → Accepted stays rejected with the note intact; reopened review sends the guest message.

### Phase 4: a change log (S8) : BUILT, not yet run (`rsvp_changes`, `RsvpChange`, `RsvpChangeLogTest`)

1. New table `rsvp_changes` (`rsvp_id`, `guest_id`, `event_id`, `from_status`, `from_seats`, `to_status`, `to_seats`, `channel` of web-token / web-open / group / whatsapp / api / host, `actor_user_id` nullable, `created_at`). Written inside `submit()` under the lock, only when something changed (the Phase 1 change-detection already knows).
2. `NewRsvpReceivedNotification` says "changed from Attending (2) to Not attending" when there is a previous row.
3. The guest edit page shows the history; the CSV export gains "last changed".
4. Prune with the guest (cascade); nothing else reads it.

### Phase 5: host override (S6, S7), after Phase 4 : BUILT, not yet run (`HostRsvpOverrideService`, `events.guests.rsvp.set`, `HostRsvpOverrideTest`)

1. `PATCH events/{event}/guests/{guest}/rsvp` (web) and the API twin: set status, seats and an optional note. Goes through `RsvpSubmissionService::submit(..., enforceDeadline: false)` with a new actor argument, so the lock, seat-limit and seat-pool rules still apply (an explicit "allow over the guest limit" tick is the only bypass).
2. Recorded in `rsvp_changes` as `host`, with the host as actor. Allowed at any time, **including after the event starts** (this is how a late no-show or walk-in is recorded); it does not need the event to be open.
3. Optional "tell the guest" checkbox sends the usual confirmation. Default off: a phone-call correction usually should not email.
4. Bulk "mark attending / declined" is out of scope unless asked.
5. Tests: host changes Accepted → Declined after the deadline and after the start; seat limit still enforced; history row says `host`; guest is told only when ticked.

### Phase 6: tables (S3)

Confirm the tables page and seat counts exclude Declined / Maybe / Rejected guests; if not, count only held seats (`Rsvp::heldSeats()`). Offer to clear a declined guest's table when they decline.

## Decisions (made)

1. **Door:** refuse Declined and host-rejected guests, flag Maybe, awaiting approval and no answer. The host's own scanner can "Check in anyway"; the override is written on the check-in (`checked_in_via_label`). Staff-link scanners cannot override. Built in Phase 2.
2. **A host's rejection is final.** A rejected guest who re-accepts (even via Declined) is told the host already declined and nothing is queued; the note is kept. Phase 3.
3. **Approval follows seats, not answers.** An approved guest who later adds a plus-one needs approval **for the extra seat only**: the approved seats stay approved and the guest keeps their pass; the extra seat waits for the host. Re-accepting after Declined / Maybe keeps the approval up to the approved seat count (`approved_seats`, stored on approval). Phase 3.
4. **Host override and the guest limit (decided): the host may exceed it, only with an explicit "allow over the guest limit" tick.** The limit is the host's own capacity setting, not a platform cap, and the host is the one person who knows about a walk-in or a phone-call addition. Without the tick the limit still applies; every override that goes over is recorded as such in `rsvp_changes` (Phase 4). Phase 5.
5. **A host override does not email the guest by default.** A "tell the guest" checkbox is available, off. Phase 5.

## Suggested order

1 (cheap, shows what is really broken), then 2 (the only gap that affects who gets in), then 3, then 4 and 5 together, then 6.
