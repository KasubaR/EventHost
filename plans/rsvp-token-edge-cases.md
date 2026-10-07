# RSVP token and tampering edge cases — gap review and plan

Scope: the second block of the RSVP edge-case list.

- RSVP request is duplicated
- RSVP token doesn't exist
- RSVP token belongs to another event
- Guest modifies the URL/token manually

Follows `plans/rsvp-submission-edge-cases.md` (all six phases built). Code read: `RsvpController` (web and
`Api\V1`), `StoreRsvpByTokenRequest`, `StoreOpenRsvpRequest`, `GroupRsvpResolver`, `PublicCheckInController`,
`CheckInController`, `Api\V1\EventCheckInController`, the `rsvp-submit` limiter, `routes/web.php`,
`errors/403` and `errors/404`, the `guests` migration and `Guest` model.

## What already holds

- **A token cannot belong to "another event" in a way that matters.** `/rsvp/{token}` has no event in the URL: the
  event is read from the guest row, so a token only ever acts on its own guest and event. The open form takes
  no token. A group token (`guest_groups.rsvp_token`) and a guest token live in different columns, so one never
  opens the other's route.
- **Scanner/check-in routes are event-scoped.** `PublicCheckInController::confirmToken`, `CheckInController` and
  the API check-in all query `where event_id = {link's event} and invitation_token = {token}` and answer 404
  "No matching invitation for this event". `confirmGuest` re-checks `guest->event_id === event->id`.
- **The token form has no identity fields**, so editing the page can only change the guest's own answer. `validated()`
  drops extra POST fields; `status` is an enum and `attendee_count` is range-checked against the guest's own maximum.
- **Tokens are unguessable:** `Str::random(48)`, unique index.
- **Duplicates** are covered by phases 1 to 4 of the first plan.

## Gaps

Severity: **H** = harm, **M** = confusing or lossy, **L** = polish or hardening.

### Token doesn't exist (or no longer does)

| # | Sev | Gap |
|---|---|---|
| T1 | M | **A dead token gets the generic site 404** ("The link may be wrong, or the event isn't public. Head home, or browse what's on."). That copy is written for public events. A guest whose host **deleted** them (guests hard-delete, no `SoftDeletes`) or **regenerated** their link (`regenerate_invitation_token` on the guest edit form) sees the same thing, with no hint to contact the host, and an invitation link from WhatsApp is the only thing they have. |
| T2 | M | **Links damaged in transit are not recovered.** A trailing `.`, `)` or `>` copied with the URL, a trailing space (`%20`), or a link cut by a line wrap all become a different `{token}` string and a 404. No normalisation, and no early reject: a 10 000-character token still goes to the database. |
| T3 | L | **Case behaviour depends on the database.** MySQL's default collation is case-insensitive, so `ABC...` finds the same guest as `abc...`; SQLite (the test database) does not. Behaviour differs between production and tests, and the unique index treats two tokens differing only by case as equal. Not exploitable at 48 characters, but nothing pins it. |

### Guest modifies the URL/token manually, or a stale page is used

| # | Sev | Gap |
|---|---|---|
| T4 | M | **Stale or edited submits get the wrong error page.** `StoreRsvpByTokenRequest::authorize()` and `StoreOpenRsvpRequest::authorize()` call a bare `abort(403)` for a deleted or unpublished event, and the open form does the same when a private event's guest list is full. `errors/403` says "Access denied. That link isn't valid anymore... Verification and some other links only stay valid for a short time" with a "Request a new link" button for signed-in users. That is verification-email copy on an RSVP form. The matching **GET** already shows a proper status page ("Event cancelled", "Invitation unavailable"), so the two disagree. |
| T5 | M | **The per-token POST limit does not limit token guessing.** `rsvp-submit` keys the personal link on `ip|token:{token}`, so each new token string is a fresh bucket and an attacker (or a bot) can POST unbounded wrong tokens. Each one also writes a new rate-limiter cache key, so the cache grows with every attempt. The GET routes (`/rsvp/{token}`, `/thanks`, `/pass`, `/pass/download` aside, the QR routes) have **no throttle at all** ("deliberately", for Twilio and returning guests). Guessing is infeasible at 48 characters; the cost is cache churn and database lookups, not a breach. |
| T6 | L | `thanksByToken()` lacks the `isInvitation()` check that `showByToken`, `pass`, the PDF/PNG routes and the API twin all have. Ticketed events have no guests in practice, so this is consistency, not a hole. |
| T7 | L | `StoreRsvpByTokenRequest` resolves the guest from the token three times per request (`authorize`, `rules`, the controller), and the API twin repeats it. Harmless, but it is the place a future check gets added to one copy and not the others. |

### Not covered by tests

| # | Sev | Gap |
|---|---|---|
| T8 | M | **No tests** for: unknown token on GET/POST, a group token on `/rsvp/`, a guest token on `/g/`, a staff or check-in token on `/rsvp/`, an event slug on `/rsvp/`, a guest token used on another event's check-in link, tampered POST fields (`status=garbage`, `attendee_count=99`, injected `guest_id` / `event_id` / `host_approval_status`), or a regenerated token. Nothing pins today's correct behaviour. |
| T9 | L | **Group-link and API double submits have no test.** The group path reaches `submit()`, so it should be covered by the first plan's change, but a double tap on `/g/{token}` (guest created by call 1, found by call 2) and an Android retry on `/api/v1/rsvp/{token}` are unproven. |
| T10 | L | **Duplicate guests by email case.** The open form lowercases emails; the host add form, import and API may not. On MySQL (case-insensitive) that is one guest; on a case-sensitive collation it would be two. Verify the host paths normalise before storing. |

## Plan

### Phase 1 — Pin current behaviour with tests (T8, T9, T6, T3) — BUILT (`RsvpTokenEdgeCasesTest`; found and fixed a 500 on `status[]=x`)

Add `tests/Feature/RsvpTokenEdgeCasesTest.php`. These should mostly pass today; any that fail are real bugs to fix first.

1. **Unknown token:** `GET /rsvp/{x}`, `/rsvp/{x}/thanks`, `/pass`, `/pass.png`, `/entry-pass.*` all 404; `POST /rsvp/{x}` 404 and writes nothing; same for the API twin.
2. **Wrong kind of token:** a group `rsvp_token` on `/rsvp/`, a guest token on `/g/`, an `event_staff_links` token on `/rsvp/`, an event slug on `/rsvp/` all 404.
3. **Another event:** guest token of event A used on event B's staff check-in link (`confirmToken`, web and API) is 404 "No matching invitation for this event", and the guest's check-in state is untouched.
4. **Tampered POST:** `status=garbage` rejected; `attendee_count=99` and `-1` rejected; extra `guest_id`, `event_id`, `host_approval_status`, `host_reviewed_by` ignored and the stored row unchanged; a token form cannot change name or email.
5. **Regenerated token:** old token 404s, new token works, the old pass image URL 404s.
6. **Group and API double submit:** two identical posts leave one guest, one RSVP and one set of notifications.
7. **Case:** document with a test that tokens match exactly (skip the case-folded assertion on SQLite, or assert exact-case lookups succeed, and note the MySQL difference).
8. Add the missing `isInvitation()` guard to `thanksByToken()` (T6) with a test.

### Phase 2 — A useful "link not found" for guests (T1, T2) — BUILT (`GuestLinkToken`, `guest.token` middleware, `rsvp/link-not-found`)

1. **Normalise before lookup.** One helper, `GuestLinkToken::clean()`: trim whitespace, strip trailing `. , ; : ) ] > " '`, and return null (404 with no database query) when the result is empty, longer than 64 characters or not `[A-Za-z0-9_-]`. Used by every `where('invitation_token', ...)` on the guest-facing routes (web and API). Do not change case.
2. If cleaning changed the token and the guest exists, **redirect to the canonical URL** (302, same route) instead of 404, so a link with a trailing `)` just works.
3. **RSVP-specific not-found page** for `rsvp.token.*` and `group-rsvp.*` routes (rendered from `bootstrap/app.php` when a `ModelNotFoundException` or 404 comes from those routes, not a global 404 change): "We couldn't find this invitation. The link may be incomplete (copy the whole link from your message) or the host may have replaced or removed it. Ask the person who invited you to send it again." No host name or contact (the event is unknown, and revealing it for a bad token would leak). Keep the home link.
4. Hosts: the regenerate checkbox on the guest edit form gets one line of help: "The old link will stop working. Send the guest the new one."

### Phase 3 — Stale submits land on the right page (T4, T7) — BUILT (`RsvpUnavailableException`)

1. Introduce `RsvpUnavailableException` (twin of `RsvpClosedException`). The two form requests throw it instead of `abort(403)` for deleted, unpublished and full-guest-list cases.
2. Render it once in `bootstrap/app.php`: redirect to the matching GET page (`rsvp.token.show` / `rsvp.open.show` / `group-rsvp.show`), which already renders the right status view; JSON gets 403 `{message, code: "rsvp_unavailable"}` (same status code, additive).
3. Resolve the guest once per request: a request-scoped memo on the form request, reused by `authorize()`, `rules()` and the controller via `$request->guest()`; the API twin does the same.
4. Tests: guest with the form open while the host deletes, unpublishes or cancels the event gets the status page, not the verification 403; JSON clients keep status 403.

### Phase 4 — Throttle token guessing without hurting real guests (T5) — BUILT (miss bucket skipped)

1. `rsvp-submit`: add an IP-wide ceiling to the personal branch as well, e.g. `Limit::perMinute(10)->by(ip|token)` **plus** `Limit::perMinute(60)->by(ip)`. A shared venue IP stays well inside 60 submits per minute.
2. New `guest-link` limiter on the guest GET routes (`rsvp.token.show`, `rsvp.token.thanks`, `rsvp.token.pass`, the PDF is already throttled) at about 120 per minute per IP. **Not** on `pass.png` or `entry-pass.png` (Twilio fetches them from a few IPs, as the route comment explains).
3. Count only misses against a tighter bucket if you want to be stricter: a 404 lookup consumes from a 30/min per-IP "bad link" bucket. Optional; decide below.
4. Tests: 61st POST with varying tokens from one IP gets the 429 page; a normal guest and a second IP are unaffected; `pass.png` is never throttled.

## Open decisions

1. Phase 2 redirect for a cleaned token (302 to the canonical URL) versus serving the page in place.
2. Whether a "bad link" miss bucket (Phase 4, step 3) is worth the extra limiter.
3. Whether a regenerated token should keep working for a short grace period (needs a `previous_invitation_token` column; not planned).
4. T10: confirm every host-side path lowercases the email before storing; fix any that do not.

## Suggested order

1 first (cheap, shows what is really broken), then 3 (wrong error page is the most visible), then 2, then 4.
