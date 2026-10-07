# RSVP submission edge cases — gap review and plan

Scope: the "Submission" block of the RSVP edge-case list. Paths covered: personal link
(`POST /rsvp/{token}`), open link (`POST /e/{slug}/rsvp`), group link (`POST /g/{token}`).
Code read: `RsvpSubmissionService`, `ValidatesRsvpPayload`, `StoreRsvpByTokenRequest`,
`StoreOpenRsvpRequest`, `RsvpController`, `CommunicationService::dispatchRsvpNotifications`,
`rsvp/partials/{form-fields,token-rsvp-form,open-rsvp-form}`, `public/js/rsvp-form.js`,
the `rsvp-submit` limiter, `bootstrap/app.php` exception handlers.

## What already holds

- **Data is safe against doubles.** Every `submit()` locks the event row, then `updateOrCreate` on the
  unique `guest_id`, with a narrow `UniqueConstraintViolationException` fallback. Two taps never produce
  two RSVP rows or a double-counted seat.
- A write is one transaction, so a dropped connection mid-write is all-or-nothing.
- A stale CSRF token is handled (sends the user back with input) — but see G9.
- Notifications are queued (`ShouldQueue`), so mail latency does not hold the response open.
- The token page prefills the guest's existing answer (`existingRsvp`) and `/rsvp/{token}/thanks` is
  refreshable.

## Gaps

Severity: **H** = visible harm to guests/hosts or money, **M** = confusing or lossy, **L** = polish.

### Case 1 — submits without selecting attendance

| # | Sev | Gap |
|---|---|---|
| G1 | M | **A guest cannot "not choose", because "Attending" is pre-checked** (`form-fields.blade.php`: `old('status', existing ?? preselected ?? Accepted)`). The `required` on the radios never fires. A guest who skims and presses Send is recorded as *Accepted, 1 seat*, which for a no-plus-one event consumes a seat and (Pro) triggers the pass email. On the open form, where the guest has no prior answer, this is a silent "yes". |
| G2 | L | The server rule works (`status` is `required`, so a hand-built request fails), but the message is the raw framework text ("The status field is required.") and no test covers it. |
| G3 | M | **The error may not be visible.** A validation failure redirects to `url()->previous()`, i.e. the top of the invitation page; the form sits at `#rsvp`, often far below a hero. The guest sees an unchanged page and no feedback. Needs checking per layout; the fix is to append `#rsvp` to the redirect. |

### Case 2 — submits twice

| # | Sev | Gap |
|---|---|---|
| G4 | **H** | **Every submit re-sends everything, even when nothing changed.** `dispatchRsvpNotifications()` runs after every `submit()`: guest confirmation email (+ PDF/PNG pass), the WhatsApp pass (a paid Twilio message), and the host's "new RSVP" email/push. `startLog()` is called with a null idempotency key, and a code comment calls this deliberate ("an edited/resubmitted RSVP notifies again"). That was written for *edits*; it also covers a double-click, a back-and-resubmit and a retry. Result: two confirmation emails to the guest, two pings to the host, two WhatsApp charges. |
| G5 | M | An unchanged re-submit should be a no-op for the host's attendance numbers and reminders; today `updateOrCreate` bumps `updated_at`, which the guest list may sort or flag on. Verify what reads `rsvps.updated_at`. |

### Case 3 — refreshes immediately after submitting

| # | Sev | Gap |
|---|---|---|
| G6 | M | Token guests are fine (POST then 302 then refreshable GET). **Open-link guests are not:** the confirmation is a one-shot session flash of three Eloquent models (`thanks_event/guest/rsvp`). Refresh renders the empty "no confirmation" branch, so the guest cannot tell whether they registered. For public free-registration events there is no token, so no way back at all. Flashing whole models into the session is also fragile (stale serialised state, size). |
| G7 | L | Back button after the thanks page returns to the form; resubmitting is not blocked (the session CSRF token is not rotated), which is the G4 path. |

### Case 4 — presses submit multiple times

| # | Sev | Gap |
|---|---|---|
| G8 | **H** | **No client-side submit lock.** `rsvp-form.js` only syncs the count select; the button is never disabled and nothing says "Sending…". Combined with G4 this is the most likely real-world duplicate. |
| G9 | M | **The throttle punishes double-taps and shared networks.** `rsvp-submit` is 10 requests/minute keyed on `ip|slug` or `ip|token`. The open and group links are keyed on the **slug/token only**, so everyone behind one office, church or mobile-carrier NAT shares 10 per minute for the whole event. At an announcement moment ("scan the QR now") guests get a 429. |
| G10 | M | **There is no friendly 429 page** (`resources/views/errors` has only 403 and 404). The guest gets the framework default, loses what they typed, and has no "try again in N seconds" message. Same for 419 on guest forms: the handler's fallback flashes an error under `email`, which the token form never renders, so the 419 on a guest form shows nothing. |
| G11 | M | Concurrent requests that both pass the pre-lock checks both reach the notification step. Once G4 is fixed by comparing before/after under the lock, this closes with it. |

### Case 5 — slow connection and retries

| # | Sev | Gap |
|---|---|---|
| G12 | M | Retry after a timeout is the same as G4/G8: request 1 may have committed while the response was lost. The retry then looks like a duplicate and re-notifies. Fixed by the same "changed?" check; no client token needed. |
| G13 | L | No timeout/offline feedback: if the request hangs or fails at the network level the page just spins or shows the browser error page, and the form content is gone on some browsers. A small `pageshow`/failure re-enable and an inline "still sending" message would help. |
| G14 | L | `submit()` takes an **event-wide** row lock. Correct, but a viral event serialises every RSVP. Fine at current scale; note it, don't change it. |

### Case 6 — browser closes during submission

| # | Sev | Gap |
|---|---|---|
| G15 | **H** | **Orphan and half-applied guest rows on the open form.** `storeOpen()` does `Guest::firstOrCreate()` and `$guest->fill($data)->save()` **outside** the `submit()` transaction. If `submit()` then throws (closed at the lock, guest limit, seat pool, validation of count) or the request dies, a `Guest` with no RSVP is left behind. It counts toward `hasReachedGuestCapacity()` (so abandoned attempts eat a private event's guest-list cap), shows up on the host's list as "no response", and for an *existing* guest the refused attempt has already overwritten their name and phone. |
| G16 | L | `firstOrCreate` catches the broad `QueryException`, which `RsvpSubmissionService` deliberately avoided. It should catch `UniqueConstraintViolationException`. |
| G17 | M | If the client drops after commit, the open-link guest returns to a blank form with no hint they already answered (token guests are fine). With G4 fixed the resubmit is harmless, but the thanks page should say "we already had your answer, now updated" rather than "received". |

### Out of scope, flagged

- On the open link an **email address identifies the guest**, so anyone typing another person's email changes that guest's answer and (G15) their name/phone before the deadline. The service already documents this trade-off for post-deadline reductions; the pre-deadline overwrite is the same trust model. Decide separately whether name/phone should only be set on *creation*.

## Plan

Ordered by value. Phases 1 to 3 carry the real risk; each can ship alone.

### Phase 1 — Stop the duplicate notifications (G4, G5, G11, G12) — BUILT

1. `RsvpSubmissionService::submit()` compares the stored row with the values it is about to write
   (status, attendee_count, normalised message, host_approval_status) **inside the lock** and records the
   result. Return a small result object (or set a non-persisted `wasChanged` flag on the model) so the
   signature stays source-compatible for other callers (WhatsApp inbound, API, group).
2. When nothing changed, skip the write (no `updated_at` bump).
3. Controllers (`storeByToken`, `storeOpen`, group, API, WhatsApp inbound) call
   `dispatchRsvpNotifications()` only when `changed` or `created`. Update the "no idempotency key" comments.
4. Product call to confirm: a guest who deliberately resubmits to get the email again no longer receives
   it. Mitigation, optional: a rate-limited "Email me my confirmation again" button on the thanks page.
5. Tests: second identical submit sends 0 notifications and writes 1 row; changed message/count/status
   sends exactly one; concurrent-style double dispatch (call `submit()` twice in one test) yields one
   notification set; approval-pending resubmit does not re-ping the host.

### Phase 2 — Client lock and honest feedback (G8, G13, G7) — BUILT

1. `rsvp-form.js`: on `submit`, if already submitting `preventDefault()`; otherwise disable the button,
   set `aria-busy`, swap the label to "Sending…". Re-enable on `pageshow` (bfcache back) and after about 20 s
   with an inline "This is taking a while. Check your connection, your answer is not lost" line.
   Plain ES5, as the guest-page scripts require. Do not disable fields (disabled inputs are not posted);
   disable only the button after the submit event has been captured.
2. Cover both forms and the group form (`group-show`). Skip `data-rsvp-preview`.
3. Test with the existing Node harness pattern in `tests/js/` (submit twice, one prevented).

### Phase 3 — Make the open flow transactional (G15, G16, G17) — BUILT (`OpenRsvpService`; G17 wording not done)

1. Move guest find-or-create, the `fill()` of name/phone and the token back-fill **into** the same
   `DB::transaction` as `submit()` (either a new `OpenRsvpService::submit()` wrapping both, or pass a
   guest resolver closure into the service). Event lock first, then the guest, then the RSVP.
2. Any exception rolls back the new guest and the name/phone change. Use `UniqueConstraintViolationException`.
3. Capacity (`hasReachedGuestCapacity`) is re-checked under the same lock for a genuinely new guest, so the
   cap cannot be passed by a race either.
4. Thanks page for an existing guest says the answer was updated.
5. Tests: refused submit (closed at lock / guest limit) leaves no `Guest` row and no changed name/phone;
   cap not consumed by a failed attempt.

### Phase 4 — Throttle and error pages (G9, G10) — BUILT (`errors/429`, per-email limit, 419 on RSVP forms)

1. `rsvp-submit`: key open and group links on `ip|slug|email-hash` (or add a second, much higher per-slug
   limit) so one shared IP is not the whole event's budget. Keep 10/min for the personal token, which is a
   single person. Decide the numbers with the host's likely announcement burst in mind.
2. Add `errors/429.blade.php` (guest-facing, "Too many attempts, try again in N seconds", with a Back link)
   and `errors/419.blade.php`. Make the 419 handler for `rsvp.*` and `group-rsvp.*` routes redirect back with
   a banner and the input kept, instead of the `email` error.
3. Tests: 11th request returns the friendly view; two IPs do not share a token budget.

### Phase 5 — Selection and error visibility (G1, G2, G3) — BUILT

1. Decide G1 (product): the open form should start with **no** answer selected, so a distracted tap is not
   a "yes". Keep the pre-selection for a returning guest (their stored answer) and for `?status=` deep links.
   Radios are already `required`, so no server change.
2. Override `getRedirectUrl()` on the three RSVP form requests to append `#rsvp` (and add `id="rsvp"` where a
   layout lacks one; the invitation docs list which layouts already carry it).
3. Friendlier `messages()` for `status` ("Please choose whether you can come.").
4. Tests: missing `status` returns a 302 with a `status` error and a `#rsvp` fragment; the message wording.

### Phase 6 — Open-link confirmation that survives refresh (G6) — BUILT (`rsvp.open.confirmed`)

1. Replace the flashed models with a **temporary signed URL** (`rsvp.open.confirmed`, keyed on the RSVP id,
   about 24 h) that re-queries fresh data. It is the same trust level as the personal link, and an open guest
   without a token gets a refreshable, bookmarkable page. Keep the flash route as a fallback for one release.
2. Update `confirmationViewData()` and the thank-you view's `refreshable` flag; add a test that a refresh
   renders the same details.

## Test checklist (maps to the pasted list)

| Case | Covered by |
|---|---|
| No attendance selected | Phase 5 tests; manual: submit with nothing chosen on the open form, confirm no seat is recorded |
| Submit twice | Phase 1 tests |
| Refresh right after submit | Phase 6 test; manual refresh on open and token flows |
| Submit button pressed many times | Phase 2 JS test; Phase 1 concurrency test; Phase 4 throttle test |
| Slow connection + retry | Phase 1 (no duplicate side-effects); manual with Chrome DevTools "Slow 3G" and "Offline" |
| Browser closes mid-submit | Phase 3 tests (no orphan guest); manual: abort the request in DevTools, reopen the link |

## Open decisions

1. G1: start the open form with nothing selected? (Recommended: yes.)
2. Phase 1: add the "email me again" button now or only if hosts ask?
3. Open-link overwrite of name/phone for an existing email: restrict to creation only?
4. New limits for Phase 4, and whether to throttle by email-hash or just raise the per-slug cap.

## Suggested order

1 → 2 → 3, then 4, 5, 6. Phase 1 and 2 together remove the realistic duplicate-email complaint.
