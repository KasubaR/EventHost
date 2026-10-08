# RSVP deadline moments: late submit, a moved deadline, a manual close

**Status: all three phases are BUILT.** Tests: `RsvpDeadlineMomentsTest`.

Scope: the fifth block of the RSVP edge-case list.

- RSVP form was opened before the deadline but submitted after it
- The deadline is changed while a guest has the form open
- The host closes RSVPs manually

Builds on `plans/rsvp-deadline-fixes.md` (all built: venue-clock deadline, 60 s submit grace, reduce-only after the
deadline, closed banner for hosts). Code read: `Event` (`isRsvpOpen`, `acceptsRsvps`, `acceptsRsvpSubmissions`,
`rsvpClosedReason`, `acceptsRsvpReductions`), `RsvpClosedException`, `RsvpSubmissionService::submit`, the three form
requests, `GroupRsvpResolver`, `closed.blade.php`, the invitation `rsvp` sections, `EventController::update`
(`rsvp_reopened`), `SendRsvpReminderNotificationsCommand`, `CommunicationService`, pause/unpause controllers.

## What happens today

| Case | Today |
|---|---|
| Submit up to 60 s after the deadline | Saved (grace, `RSVP_DEADLINE_GRACE_SECONDS`). |
| Submit later than that | Refused **under the lock**; web goes to the closed page with a flash, JSON gets 403 `rsvp_closed` (+ `can_reduce`). A guest who already answered on a personal link or WhatsApp may still cancel/reduce. The typed answer is not kept. |
| Deadline moved earlier while the form is open | The server always judges the live value, so the submit is refused the same way (no stale form can pass). |
| Deadline moved later or removed while a guest is on the closed page | The next load shows the form again; a submit from a form opened earlier succeeds. Nothing tells the guest. |
| Host wants to stop taking RSVPs now | **No switch.** Options are a deadline "just now" (a past deadline is refused on update beyond 5 minutes' slack, so it has to be typed to the minute, and reopening means remembering the old value) or **pausing the invitation**, which hides the whole page ("Invitation unavailable") and also blocks guests from cancelling. |

## Gaps

Severity: **H** = guests or the host get the wrong outcome, **M** = confusing, **L** = polish.

### Opened before, submitted after

| # | Sev | Gap |
|---|---|---|
| L1 | **M** | **The refusal always says "The RSVP deadline has passed".** That is wrong when the RSVP closed because the event started (no deadline), because the host pulled the deadline earlier, or (once built) because the host closed it. It also gives no date, so a guest can't tell whether they were late or the rules changed. `RsvpClosedException` has one fixed text. |
| L2 | **M** | **The guest's answer is lost.** Their name/phone/message on the open form and their note on a personal link are dropped with the redirect to the closed page, so the guest has nothing to forward to the host ("I'm coming with one guest"). The closed page does show the host's number. |
| L3 | **M** | **Guests are never shown the cut-off time.** The live forms (personal link, open, group) show no deadline at all. The designed layouts say "respond by 5th October" with the **date only**, so a guest at 20:00 on the 5th believes they are on time while an 18:00 deadline has passed. (`plans/rsvp-deadline-fixes.md` G12 fixed only the closed page.) |
| L4 | L | The grace is a flat 60 s: someone who opened the form at 17:50 and sits on it past the deadline is refused with no leeway, while one at 18:00:59 is let in. That is the intended trade-off; only the wording in L1 needs to say what happened. |

### Deadline changed under an open form

| # | Sev | Gap |
|---|---|---|
| L5 | M | **A shortened deadline refuses a guest with no explanation of what changed** (same sentence as L1). The host did nothing wrong and the guest did nothing wrong. |
| L6 | L | **The closed page doesn't update itself.** After the host extends the deadline a guest on the closed page must reload; there's no "check again" link. The closed page also says "Deadline was ..." even after being reopened to a later date when reached from a stale tab. |
| L7 | L | **Reminder emails and WhatsApp messages quote the old deadline.** Sent mail cannot be recalled; the link always shows the live state. Not fixable, but the closed/open page should state the *current* deadline (L3) so an old email isn't the only source. |

### Host closes manually

| # | Sev | Gap |
|---|---|---|
| L8 | **H** | **There is no manual close.** The workarounds in the table above are awkward (deadline typed to the minute) or too heavy (pause hides the whole invitation and takes away guests' ability to cancel). A host who has hit their catering number, or whose venue changed, has no clean way to stop new answers. |
| L9 | M | **Everything that reads "is RSVP open" needs to follow a manual close:** the guest list banner (`rsvpClosedReason()`), the three form requests, the group-link resolver, WhatsApp replies, invitation/reminder sends (already refused when closed), the deadline reminder command (it must not email "closes soon" for a closed RSVP), the event-reminder email's RSVP link, and the API (`rsvp_open`). All go through `isRsvpOpen()`, so one change there covers them, but each needs a test. |
| L10 | M | **Reopening must be as easy as closing and must not broadcast.** Today's `rsvp_reopened` prompt ("remind them from the guest list") only fires when a deadline edit reopens; reopen needs the same prompt. |
| L11 | L | **Closing must not grandfather itself away.** A guest who already answered should still be able to cancel or reduce while the event hasn't started (same reduce-only rule as after a deadline), otherwise a manual close would strand headcounts. |

## Decisions needed

| # | Question | Recommendation |
|---|---|---|
| E1 | What does a manual close block? | **New answers and increases, like a passed deadline.** Guests who already answered may still cancel or reduce on a secret link (D3 of the deadline plan). The invitation page stays visible. |
| E2 | Is there a grace on a manual close? | **No.** The 60 s grace is for a clock the guest could not see; the host's click is deliberate. |
| E3 | How is it stored? | `events.rsvp_closed_at` (nullable timestamp). Null = follows the deadline. Reopen sets null. Independent of the deadline, so extending the deadline does **not** reopen a manually closed RSVP (the host closed it on purpose). |
| E4 | Where does the host do it? | A "Close RSVPs" / "Reopen RSVPs" button in the closed/open banner area of the guest list and the event page, plus API `POST/DELETE /host/events/{event}/rsvp-closure` (additive: `rsvp_closed_at` on `EventResource`). |
| E5 | Should closing notify guests? | **No.** Same reasoning as D7: no automatic broadcast. |
| E6 | Show the cut-off time to guests? | **Yes**, everywhere a form or "respond by" line is shown, in venue time with the zone ("Monday 5 October, 6:00 PM CAT"), via `rsvpDeadlineLabel()`. |

## Phases

### Phase 1: say what actually happened (L1, L2, L5, L3, L6)
1. `RsvpClosedException` takes a reason (`deadline`, `started`, later `host`) and the closing label; wording per reason:
   "The RSVP deadline (Monday 5 October, 6:00 PM CAT) has passed", "RSVP closed when the event started", the host-closed
   sentence. The reduce-only variant keeps its second sentence. JSON adds `closed_reason` and `closes_at` (additive).
2. The closed page and the flash show the current cut-off; a "Check again" link back to the form's own URL.
3. Keep the typed fields on the redirect (`withInput`) and show them on the closed page as "What you sent" with the host
   number, so the guest can forward it.
4. Show the cut-off time on the personal-link form, the open form, the group form and in every layout's "respond by" line
   (time included, venue zone), through one helper rather than per-layout formatting.
5. Tests: refusal text per reason, JSON fields, input kept, cut-off visible on each form and in a sample of layouts.

### Phase 2: manual close and reopen (L8, L9, L11)
1. Migration `rsvp_closed_at`; `Event::rsvpManuallyClosed()`; `isRsvpOpen()` returns false when set, `rsvpClosedReason()`
   gets "You closed RSVPs on ...". `acceptsRsvpSubmissions()` applies no grace to a manual close (E2).
2. Reduce rules: `acceptsRsvpReductions()` unchanged (until the event starts), so cancel/reduce still works (E1, L11).
3. Controller actions + routes (web and API), owner only, invitation events only (ticketed events have no RSVP). Buttons in
   the banner partial; API field on `EventResource`.
4. Tests: every guest path refused when closed (token, open, group, WhatsApp, API); reduce allowed; extending the deadline
   does not reopen; deadline reminders skipped; invitations/reminders refused with the existing closed message; grace not
   applied.

### Phase 3: reopen prompt and docs (L10)
1. Reopening flashes the same "remind them from the guest list" prompt as `rsvp_reopened`; no broadcast (E5).
2. `CLAUDE.md` RSVP Deadline section: the four closure causes (deadline, event start, manual, pause/cancel/delete) and
   which gate each uses; the dedicated test list.

## Out of scope

- Auto-notifying guests when the deadline or closure changes (E5, deadline plan D7).
- Pause behaviour (stays "hide the invitation").
- Scheduled reopen/close times beyond the deadline itself.
