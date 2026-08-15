# Feature Plan: Guest entry pass (self-serve QR) + attendee list download

Status: **Shipped** (2026-08-15). All five phases done (Phase 3's badge-sheet piece landed early,
folded into Phase 1 since the table data existed by then). Full suite green.

Phase 5 shipped smaller than planned, once the actual scanner JS was read: no session credential, no
route pulled out of `auth` middleware, no authorization code touched at all. See §6 for the corrected
mechanism — a client-side QR-shape recognition fix instead, verified end-to-end against a real
unauthenticated staff-link page (guest QR scanned → 200 OK → `checked_in_at` set, zero login session
present in that browser).

Phase 4 shipped as planned, with one naming deviation: filter values are `yes`/`no` (matching this
codebase's existing `invitation_sent`/`plus_one` filter convention), not the plan's shorthand `checked_in=1`.
Also factored the three read paths' identical filter-building code (`index`, `export`, `exportPdf`) into one
`applyGuestFilters()` helper while adding the new filter — three copies of the same five-clause chain were
already borderline before a sixth clause; leaving them as three made the next filter someone adds the one
most likely to land in two of the three and silently drift.

Phase 2 shipped as planned, with one addition beyond the original scope: the entry-pass panel also
renders on `rsvp.closed` (RSVP-deadline-passed) for an upcoming, already-accepted guest — not just on
`token-show`. The plan only named `token-show.blade.php`; extending to `closed.blade.php` closes a real
gap the plan didn't call out — the RSVP deadline typically lands *before* the event, so an accepted guest
would otherwise lose access to their own pass in exactly the days they're most likely to want it. A past
(`isLocked()`) event still shows no pass, since there's nothing left to scan it for.

Phase 1 also fixed a live, pre-existing bug it exposed: `routes/web.php`'s guests resource called
`->scoped(['guest' => 'guests'])`. `Route::resource(...)->scoped()` takes `{param: column}` pairs, not
relation names — that told Laravel to match a column literally named `guests` on the `guests` table, which
doesn't exist, so **every guest edit, update, and delete 404'd, for every host, silently** (ownership is
checked separately by `GuestPolicy`, so nothing ever surfaced the mismatch as a 403 to explain it). Fixed to
plain `->scoped()`, which correctly infers `Event::guests()` and matches on the guest's own `id`. Covered by
a new regression test (`GuestManagementTest::test_host_can_edit_update_and_delete_a_guest`).

Three things, tied together because the first two feed the third:

1. An invited guest can see their own QR code — the one scanned at the entrance — on their RSVP page.
2. That QR (and the page it lives on, and the printable badge sheet the host already downloads) shows
   the guest's **table number**, if the event has one.
3. The host can download **who actually showed up** — not just who was invited or who RSVP'd.

None of this exists today. Full research is in the two agent traces this plan was built from; the load-bearing
facts are below.

---

## 0. Decisions taken

| Question | Decision |
|---|---|
| What is a "table number"? | **Reuse `event_tables`.** Add a nullable `guest.event_table_id`; the number a guest sees is `EventTable::label`, the same free text a host already types for the photo-wall QR sign. A host's photo-wall table and a guest's seating table are now the same row by design |
| Who gets an entry pass? | **Only guests who RSVP'd attending.** Declined or unresponded guests see the RSVP form only, no pass |
| Does the QR need to work at the door for passwordless staff? | **Yes — fix it.** One QR must work whether it's scanned by a logged-in host or by door staff using the no-login staff-link flow. Approach in §6 |
| How is "who attended" downloaded? | **Both.** Extend the existing guest CSV/PDF export with check-in columns and a filter, and add a convenience "Download attendee list" button on the check-in scanner page |

---

## 1. What already exists and why it matters

| Fact | Where |
|---|---|
| Guest QR encodes a URL, not a bare token: `route('events.checkin.confirm-token', …)` | `app/Models/Guest.php:104-114` |
| That route requires login + `GuestPolicy::update` (host only) | `app/Http/Controllers/CheckInController.php:40-58`, `app/Policies/GuestPolicy.php:9-12` |
| QR image generation is host-only today — no guest-facing QR route exists at all | `GuestController::qr()/qrSheet()`, `app/Http/Controllers/GuestController.php:254-293` |
| `QrCodeService::svg()` has no caching — every call regenerates | `app/Services/QrCodeService.php:16-24` |
| Guests authenticate to their RSVP page purely via `invitation_token` in the URL — no login, no signed URL | `routes/web.php:82`, `RsvpController::showByToken`, `app/Http/Controllers/RsvpController.php:19-41` |
| `invitation_token` is `Str::random(48)` (~286 bits) with a DB-unique constraint — safe to key a second guest-facing route off the same token | `GuestController.php:189`, `create_guests_table.php:16` |
| The RSVP page never branches on "already responded" — always shows the form | `resources/views/rsvp/token-show.blade.php` |
| The post-submit `thanks()` page has no `Guest`/`Event` model at all — just two flashed strings, not revisitable | `RsvpController::thanks()`, `RsvpController.php:135-148`, `resources/views/rsvp/thank-you.blade.php` |
| Check-in itself (dashboard scanner) is already gated on `ownerHasPremiumEventTools()` | `CheckInController::scan()`, `CheckInController.php:16-33` |
| `checked_in_at` / `checked_in_by` exist on `guests` already, just unused by any export | `add_check_in_columns_to_guests_table.php:11-16` |

**Design consequence:** because the guest's own QR already encodes an auth-gated URL, letting a guest *view*
their own badge is not a new self-check-in risk — the deliberate protection is at the scanning end, not the
viewing end. The only thing missing is a route that will *render* it for the guest.

---

## 2. Phase 1 — table numbers

Doing this first since Phase 2's entry-pass panel and Phase 5's badge sheet both just read its output.

- Migration: nullable `guest.event_table_id`, FK → `event_tables`, null on delete (a table can be deleted or
  unassigned without deleting the guest).
- `Guest belongsTo EventTable`; add `tableLabel(): ?string` returning `$this->table?->label`.
- Assignment UI: a table-select dropdown in the guest edit form, a bulk "assign table" action in
  `GuestBulkActionController` for assigning a group at once, and a `Table` column accepted by
  `EventGuestsImport` for CSV import — matched against the event's existing `EventTable` labels, unmatched
  values left unassigned rather than silently creating new tables from typos.

## 3. Phase 2 — the guest-facing entry pass

- New route, same trust model as the RSVP page itself (token in URL, no login, no policy check):
  `GET /rsvp/{token}/entry-pass.svg` → renders `QrCodeService::svg($guest->checkInQrUrl())`, 404 if the token
  doesn't resolve or the guest's RSVP status isn't attending.
- `resources/views/rsvp/token-show.blade.php` gains a conditional panel above the existing form: "You're
  going! Show this at the door" with the QR `<img>`, the guest's name, and their table label from Phase 1
  when assigned. The RSVP form stays reachable below it — a guest who sees their pass should still be able
  to update their response or meal preference, not hit a dead end.
- Gate the whole panel on `$event->ownerHasPremiumEventTools()`, matching every other check-in surface. A
  guest of a non-premium host sees the form only, same as today.
- No login and (per the RSVP page's existing posture) no new throttle, but add simple `Cache::remember`
  around the SVG render — unlike the host's one-off QR download, this route will be hit repeatedly by the
  same guest reopening a bookmarked link.

## 4. Phase 3 — surface the table number everywhere the QR already appears

- Guest entry-pass panel (Phase 2).
- `GuestController::qrSheet()` — the printable PDF badge sheet the host already downloads
  (`GuestController.php:269-293`) — add the table label under each guest's name. Low-risk, same loop.

## 5. Phase 4 — download who attended

- `GuestController::export()` / `exportPdf()`: add `Checked In At` (formatted) and `Checked In By` (staff
  name) columns unconditionally; add a `checked_in` filter value alongside the existing `response`, `q`,
  `group`, `invitation_sent`, `plus_one` filters (`GuestController.php:61-166`).
- `resources/views/events/guests/index.blade.php`: a "Checked in" option in the existing filter bar next to
  the current Export CSV/PDF buttons — no new UI pattern, just one more filter value.
- A "Download attendee list" button on `events/checkin/scan.blade.php` linking to the CSV export with
  `checked_in=1` pre-applied, so the host doesn't have to leave the scanner page to get the list mid-event.

## 6. Phase 5 — make one QR work at the door regardless of who's scanning

**Shipped smaller than planned above, once the actual client code was read.** The paragraphs below were
written before inspecting `checkin-scanner.js`; keeping them for the record, followed by what actually
shipped and why it's better.

The QR's content does not change — it still points at `events.checkin.confirm-token`, and it still must
require *some* staff credential to actually record arrival. Making a guest's own scan of their badge
check them in with zero staff present is exactly the risk the current design avoids; this phase does not
reopen that. ~~What changes is what counts as a staff credential at that one endpoint: opening a staff
link would set a short-lived session value, and `CheckInController::confirmToken()` would accept either
the authenticated-owner check or a valid staff-link session.~~

**What actually needed fixing turned out to be client-side, not the auth boundary.** Both scanner pages
already had a correctly-scoped, already-working confirm endpoint for their own context — the dashboard
posts to the authenticated `events.checkin.confirm-token`, the staff-link page posts to the bearer-secured
`checkin.public.confirm-token`. The bug was that `checkin-scanner.js`'s `onDecoded()` only recognized a
scanned code if it started with *that page's own* base URL. A guest's QR always encodes the dashboard
route's shape (`Guest::checkInQrUrl()`), so on the staff-link page the prefix check silently failed on
every real guest badge — no error, no request even sent, just nothing happening.

The fix: `onDecoded()` now recognizes **either** shape — this page's own base, or the fixed shape every
guest QR carries (passed in as `data-guest-qr-base`) — extracts the token from whichever matched, and
always resubmits it against *this page's own* base, never the shape it was recognized under. The staff-link
page's own already-correct, already-authorized endpoint is what ends up called; nothing about
authorization changed. No new session state, no route pulled out of `auth` middleware, no new attack
surface — just recognizing a QR shape the page was wrongly ignoring.

Changed: `resources/views/events/checkin/partials/scanner-widget.blade.php` (new `data-guest-qr-base`
attribute), both blade views that include it, and `public/js/checkin-scanner.js`. `CheckInController`,
`PublicCheckInController`, `EventStaffLink`, and every route/middleware/policy are untouched.

---

## 7. Testing

- Feature: entry-pass route 404s for an unknown/foreign token and for a non-attending guest; renders for an
  attending guest; the SVG's embedded URL is exactly `checkInQrUrl()`.
- Feature: entry-pass panel is absent entirely when the host isn't on a premium plan.
- Feature: table label appears on the entry pass and on the badge-sheet PDF once assigned; absent (not a
  blank row) when unassigned; CSV import matches an existing table label and leaves a typo'd one unassigned
  rather than creating a table.
- Feature: exported CSV/PDF includes correct `Checked In At`/`Checked In By` values; `checked_in=1` filter
  returns only checked-in guests.
- Feature (Phase 5): `confirm-token` succeeds for a logged-in owner (unchanged behaviour); succeeds for an
  anonymous request carrying a valid staff-link session for that event; still fails for an anonymous request
  with no session and for a staff-link session scoped to a *different* event.
- Manual: full loop — assign a table, open the guest's RSVP link in an incognito window (no login), confirm
  the pass renders with the right table label; in a second incognito window open the event's staff link, then
  scan the guest's badge from that window and confirm check-in records without ever logging in; confirm the
  guest now shows up in the `checked_in=1` export and on the scanner page's attendee-list download.
