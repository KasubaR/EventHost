# Editing guests: gaps and plan

**Status: Phases 1 to 5 are BUILT; Phase 6 (docs) was written alongside each phase** (tests: `GuestContactChangeTest`, `GuestGroupMoveTest`, `GuestPlusOneChangeTest`, `GuestEditVersionAndHistoryTest`). Two changes from the plan: the version is a content hash of the edited fields (not `updated_at`, which is second precision), and bulk group moves are recorded in the history too. Two changes while building: the API also got `POST .../regenerate-link` (the resource already carried `personal_rsvp_url`, so no new link field), and `GuestUpdater` already takes the event lock and re-reads the guest under it, which Phase 5 builds on.

Scope: the "Guest Management / Editing Guests" edge-case list.

- Guest is edited after RSVP
- Guest's email is changed after the invitation was sent
- Guest's phone number is changed after a WhatsApp link was generated
- Guest is moved to another group
- Plus-one permission is changed after RSVP

Follows `plans/guest-adding-gaps.md` (contact rules, duplicate checks, `GuestCreator`) and `plans/plus-one-edge-cases.md`. Code read:
`GuestController::update`, `Api\V1\GuestController::update`, `UpdateGuestRequest`, `GuestBulkActionController` (`assign_group`),
`Guest` (fillable, casts, `checkInQrUrl`), `GuestGroup` (`seatsTaken`, `seatsRemaining`), `RsvpSubmissionService` (approval keyed on
`group_link_joined_at`), `CommunicationService`, `NotificationLog`, the guest edit view. Nothing has been run; "by reading" means read from the code.

## What happens today

`GuestController::update()` fills the guest and saves it. No transaction, no event lock, no version check, nothing recorded.

| Edit | Today |
|---|---|
| Name | Saved. Pass PDF/PNG caches refresh (the fingerprint includes the name). Already-sent emails keep the old name (unavoidable). |
| Email | Saved, duplicate-checked. **Everything else about the guest is untouched**: the personal link token, `invitation_sent`, the reminder markers, the reminder idempotency log rows, the unsubscribe flag. |
| Phone | Same: saved and duplicate-checked, nothing else moves. The `wa.me` link is built from the phone each time the list renders, so it is never stale. |
| Group | Saved. No pool check, no lock; bulk `assign_group` is a raw `update()`. |
| Plus-one flag | Saved. Off while they hold 2 seats: seats stay and the host is flashed `plus_one_kept` (web only). On: nothing happens and nobody is told. |

## Gaps

Severity: **H** = a guest loses email or access, or a limit is passed; **M** = confusing or lossy; **L** = polish.

| # | Sev | Gap |
|---|---|---|
| E1 | **M** | **The old mailbox keeps access after an email change.** The token is unchanged, so whoever owns the old address can still open the guest's link, change the RSVP and see the pass QR. After fixing a typo the old address is often a stranger's. "Generate a new link" is an unticked box, and its warning only says the link stops: it does not say that the QR on any pass PDF/image already sent stops working too, because the check-in QR *is* the token (`Guest::checkInQrUrl()`). |
| E2 | **M** | **"Sent" stays true after the contact changes.** `invitation_sent` / `invitation_sent_at` are untouched, so the list says the invitation went out when the new address or number has received nothing. The platform has no invitation-email send (only reminders and updates), so the host is given no cue that anything is owed. |
| E3 | **M** | **Reminder state follows the guest, not the contact.** `rsvp_reminders_sent`, `whatsapp_event_reminders_sent`, the per-guest `notification_logs.idempotency_key` rows (a `sent` row blocks a repeat) and `email_reminders_stopped_at` all survive. A new address skips every reminder window the old one "got", and **inherits an unsubscribe made from the old mailbox**: if the old address was a stranger's, they can switch the real guest's reminders off. |
| E4 | **M** | **A group move ignores the seat pool.** `update()` and the bulk `assign_group` take no lock and make no check. Moving a guest who holds accepted seats into a pooled group can push `seatsTaken()` past `seat_limit`; moving one out frees seats silently. A guest who joined through a group link keeps `group_link_joined_at`, so a host who moves them by hand into another pooled group sees them forced into approval, as if they had self-joined. |
| E5 | M | **Plus-one changes are invisible to the guest and sometimes to the host.** Turning it on after the guest answered tells nobody and the guest only finds out if they reopen the form. Ticking it for a guest while the event's own plus-one switch is off does nothing, with no warning. The API update has no counterpart to the web `plus_one_kept` signal. |
| E6 | M | **No lock, no version.** Two tabs on the same guest overwrite each other, and an edit can race a guest's own submit (a plus-one flag turned off at the instant a 2-seat RSVP is saved). The invitation design form already has an optimistic token; the guest form does not. |
| E7 | L | **No record of edits.** RSVP changes are logged (`rsvp_changes`); changes to name, email, phone, group, table and plus-one are not, with no old and new values. |
| E8 | L | Replies to the bot from the *old* phone stop matching the guest after a phone change (matching is by current phone). Accepted: the guest is told how to reach the host by the existing unmatched reply. |
| E9 | L | A name change cannot recall the PDFs and emails already delivered. Accepted. |

## Decisions needed

| # | Question | Recommendation |
|---|---|---|
| R1 | Reset "invitation sent" when the email or phone changes? | **Yes**, with a "Keep it marked as sent" tick on the edit form for the host who already told them. A false "Not sent" costs one click; a false "Sent" costs a guest who never got an invitation. |
| R2 | Replace the personal link when the email changes? | **Offer, pre-ticked** when the guest has answered or been sent an invitation, with honest wording ("Any pass already sent stops scanning; send them the new link"). If the host leaves it unticked, flash a reminder that the old address can still open the link, with a one-click "Replace link now". Never automatic: it breaks printed or delivered QRs. |
| R3 | A group move that overshoots a pool: block or allow? | **Block**, with an "allow over the pool" tick for the host (same idea as the RSVP override's over-limit tick). Bulk moves skip the guests who would overshoot and report them (`bulk_skipped`). |
| R4 | Tell a guest when they are given a plus-one? | **Offer, not automatic.** A "Tell them" button on the edit page sends the existing event-update email with a canned message and their link. No broadcast, same posture as the deadline reopen prompt. |
| R5 | Where do edit records live? | A small `guest_edits` table (who, when, `{field: [old, new]}`), shown under the guest's RSVP history. Not `rsvp_changes`: those are answers and drive the door and the history copy. |

## Phases

### Phase 1: contact-change housekeeping (E2, E3)
1. One service `GuestUpdater` used by the web update, the API update and (Phase 3) the bulk group move: transaction, event row lock, then the save. It reports what changed.
2. When the **email** changes to a different non-null value: clear `rsvp_reminders_sent` and `email_reminders_stopped_at`, release the guest's email-channel `notification_logs` keys (set `idempotency_key` to null and mark `meta.superseded = contact_change`, keeping the row for audit) so a reminder can reach the new address, and reset `invitation_sent` / `invitation_sent_at` (R1).
3. When the **phone** changes to a different non-null value: clear `whatsapp_event_reminders_sent`, release the WhatsApp-channel log keys the same way, and reset `invitation_sent` (R1).
4. A change to or from null resets nothing (nothing new can be sent). A change that only re-spells the same number (`GuestPhone::key()` equal) or the same email in another case resets nothing.
5. Flash `guest_contact_reset` on the list ("Marked not sent: the new address has not received an invitation") unless the host ticked "Keep it marked as sent".
6. Tests: each reset, the same-number-new-spelling no-op, the null cases, the opt-out clearing, an old `sent` log no longer blocking a reminder to the new address, the tick, and the API twin.

### Phase 2: the old link after an email change (E1)
1. Edit form: when the email field changes (JS) pre-tick "Generate a new personal RSVP link" if the guest has an RSVP or a sent invitation; rewrite the warning to say what stops working (the link, any pass PDF/image already sent, the QR on a printed pass).
2. Server: an email change saved **without** the new link flashes `old_link_still_works` naming the guest, with a "Replace link now" button (`POST events/{event}/guests/{guest}/regenerate-link`, owner only, one action, lock + save).
3. After a replacement, flash the new link with a copy button (web) / return `personal_link` (API additive).
4. Tests: pre-tick logic is JS-only, so test the server flash, the new endpoint, the old link 404ing and the new one working, the old pass QR failing the door lookup, and that an email change for a guest with no RSVP and no invitation says nothing.

### Phase 3: group moves and the seat pool (E4)
1. `GuestUpdater::moveToGroup()` (also used by bulk `assign_group` and the API): under the event lock, if the target group has a seat pool, add the seats the guest holds (`Rsvp::heldSeats()`, rejected holds none) to `seatsTaken($guest->id)` and refuse when that exceeds `seat_limit`: "Only N seats are left for this group". An "allow over the pool" tick (web and API) overrides it.
2. Always clear `group_link_joined_at` when the host moves a guest, so a hand move is never treated as a self-signup.
3. Bulk: each guest is checked in turn against the live remaining seats; violators are skipped and counted in `bulk_skipped`, with the first reason flashed.
4. Moving out of a pool needs no check.
5. Tests: refusal and override, seats left named, rejected and declined guests hold none, the bulk skip count, the flag cleared, no check for a move to an unpooled group, and a move racing a group-link request (sequential).

### Phase 4: plus-one changes (E5)
1. Edit page: a hint beside the checkbox when the event's own switch is off ("Switched off for this event, so this has no effect until you turn plus-ones on"), and the same flash on save.
2. When the flag is switched **on** for a guest who has an accepted answer of 1 seat and the event allows plus-ones: flash `plus_one_offered` with a "Tell them" button (R4) — `POST events/{event}/guests/{guest}/notify-plus-one`, which sends `CommunicationService::sendEventUpdate()` with a canned message and the personal link; refused when they have no email, closed RSVP, or the host has already sent one in the last hour.
3. API: `GuestResource` gets `plus_one_effective` (event switch and guest flag); the update response gets `plus_one_kept` (additive) when the flag was switched off while they hold 2 seats.
4. Tests: the three cases of the hint, the flash only when it matters, the notify action's refusals and its email, and the API fields.

### Phase 5: concurrency and edit history (E6, E7)
1. `guest_version` hidden field on the edit form (the guest's `updated_at` to the microsecond); `UpdateGuestRequest` and the service refuse a stale one ("changed elsewhere", link to the latest version in a new tab, the host's edits stay on screen), exactly like the design form. The API accepts an optional `version`; without it, last write wins as today.
2. The service re-reads the guest under the lock, so an edit and a guest submit serialise (the plus-one flag race).
3. `guest_edits` (R5): `event_id`, `guest_id`, `user_id` (nullable), `changes` JSON (`{field: [old, new]}` for name, email, phone, group, table, plus-one; `link_replaced: true` without the token itself), `created_at`. Written by the service when something changed; shown on the edit page under the RSVP history (last 20). A guest delete cascades them.
4. Tests: stale refusal, API without version still saves, the log rows and their content, nothing logged for a no-op save, the history on the edit page, and a plus-one flag edit serialised with a submit (sequential).

### Phase 6: docs
`CLAUDE.md` "Adding guests" gets an "Editing guests" subsection: what a contact change resets, the link-replacement flow and its QR cost, group-move pool rules, the plus-one hint and notify action, the version token and the edit log. Mark each phase built here.

## Out of scope

- Re-sending already delivered emails or WhatsApp messages (E9) and recalling printed passes.
- A platform invitation email (there is none today: hosts share the link or use reminders and updates).
- Matching replies from a guest's *previous* number (E8).
- Editing a guest's RSVP answer (the host override covers it).
