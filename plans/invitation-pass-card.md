# Feature Plan: Digital invitation pass card (private events)

Status: **Shipped** (2026-09-26). All five phases built (see the note under each). Full suite green.

Today an accepted guest of a private (invitation) event gets a bare QR image: a white square, their name and a
table label on a grey panel (`rsvp/partials/entry-pass.blade.php`), a raw `.svg` download, and a bare `.png`
attached to the confirmation email / WhatsApp reply. Nothing on it says *which event* it is for. Ticketed events
already have the model to copy: a `/t/{token}` page that reads as a ticket (event, date, venue, attendee, status,
QR) plus a downloadable, cached PDF that is also attached to the order email.

**Goal:** make the guest's entry pass read as a *digital invitation card with a QR code*, using the same
three-surface shape tickets have — a web page, a downloadable PDF, and the same card in email/WhatsApp — without
touching what the QR encodes or who is allowed to see it.

**Decided:** the full card is rendered both as a **PDF download** (Phase 2) and as a **PNG image** (Phase 3).
The PNG is what WhatsApp sends and what a guest saves to their phone gallery.

---

## 0. Decisions taken (assumed — flag any you disagree with)

| Question | Decision |
|---|---|
| Whose view is this? | The **guest's** pass (RSVP page, email, WhatsApp, app). Assumed because that is the only place a private event's QR is shown as a standalone image to the person attending. The host's badge sheet is §7, optional |
| Who gets a pass? | **Unchanged.** `Guest::hasEntryPassFor()` — accepted RSVP, has `invitation_token`, host on Pro+ (`ownerHasPremiumEventTools()`). The card changes presentation, not eligibility |
| What does the QR encode? | **Unchanged.** `Guest::checkInQrUrl()`, still the staff-only, auth-gated confirm URL. A guest viewing a prettier card is exactly as safe as viewing the bare QR today (see `plans/guest-entry-pass.md` §1) |
| One card design or per-template? | **One card, themed from the invitation's own colours** (`InvitationCustomizationService::merge()` → `theme.primary` / `accent` / `background`). Per-template layouts are out of scope — that is Enterprise bespoke territory |
| PDF and/or image? | **Both.** PDF (Phase 2) to print/keep; full-card PNG (Phase 3) for phone galleries, WhatsApp and email |
| Web page or panel? | **Both, one partial.** New dedicated page for a shareable/bookmarkable pass; the existing panel on the RSVP page renders the same card partial so there is one design to maintain |

---

## 1. What the card shows

Header: **the event name, as the card's title** — the largest text on it, wrapping onto two lines rather than
truncating (it is the one thing that says what the pass is for) — plus an event-type badge (mirrors the
ticket's type badge) and the cover image as a slim banner when set. The event name also appears in the page
`<title>` ("Your pass | {event}"), the PDF's document title and filename (`{event-slug}-pass.pdf`), and the QR's
`alt` text, so a guest with several passes can tell them apart in their downloads and screen reader.

| Field | Source | Notes |
|---|---|---|
| Event name | `Event::name` | |
| Date + time | `event_date`, `event_time` (`hasStartTime()`) | Same format as `tickets/show` |
| Venue | `venue` / `location_name` | Omit row when empty, never blank |
| Guest name | `Guest::name` | |
| **Plus one** | `Rsvp::attendee_count` | "Guest + 1" when their plus-one is coming; row omitted for a solo guest. An invitation RSVP is only ever 1 or 2 (`Event::maxAttendeeSlotsForGuest()`), so a bare "Admits: 1" told the door nothing |
| Table | `Guest::tableLabel()` | Omit when unassigned |
| QR | `QrCodeService` | Same content as today |
| State | see below | |

**States** (ticket-style): *Valid* (default) · *Checked in* (`Guest::isCheckedIn()`, show time from
`checked_in_at`, like the ticket's "Checked in 4 Jun, 6:12 PM") · *Event cancelled / over* (reuse
`Event::isCancelled()` / `isLocked()`; today the RSVP page already hides the pass once the event is locked).

**Gotcha — QR error correction.** Ticket QRs are `ECC_HIGH` precisely so a "Used" badge can be laid *over* the
modules. Guest passes use `ECC_STANDARD` (7%). Do **not** overlay a stamp on the guest QR. Either put the state
in a banner *beside/above* the code (recommended — no change to the QR, no cache invalidation), or switch guest
QRs to `ECC_HIGH` and bump both cache keys (`guest-entry-pass-qr:`, `guest-entry-pass-qr-png:`) plus regenerate
any PNGs already sent. Plan uses the banner.

---

## 2. Phase 1 — shared card partial + web page

**Built.** As planned, plus `App\Support\GuestPassCard` (the shared data object from §6) pulled forward, since the
partial, the page and the tests all needed one definition of "what's on the card". Deviations: the card resolves
its own theme with a try/catch fallback (an event with no resolvable template still gets a pass — found when a
template-less database 500'd the page); the panel keeps "Download QR code" and adds "Open full pass" until
Phase 2 swaps the download for the PDF/PNG pair. Tests: `tests/Feature/GuestPassCardTest.php`.

- `resources/views/rsvp/partials/pass-card.blade.php` — the card. Inputs: `$guest`, `$event`, `$rsvp`, `$theme`.
  Replaces the body of `rsvp/partials/entry-pass.blade.php`, which keeps its `$showEntryPass` guard and is
  still included from `token-show`, `closed`, `thank-you` and `events/invitations/sections/rsvp.blade.php`, so
  all four surfaces upgrade at once with no per-caller edits.
- New page `GET /rsvp/{token}/pass` (`rsvp.token.pass`, `RsvpController::pass()`) — twin of `TicketController::show`:
  same trust model as `showByToken` (token only, no login), `noindex`, redirects to `rsvp.token.show` when the
  guest is not eligible rather than 404-ing, so a stale bookmark lands somewhere useful.
- Card is themed by CSS custom properties set inline from the merged invitation theme
  (`--gp-primary`, `--gp-accent`, `--gp-bg`). Contrast is guarded: if `primary` on the card header fails a simple
  luminance check, fall back to the platform blue so a pale palette can't produce unreadable text.
- New stylesheet `public/css/guest-pass.css` (`.gpass-*`), loaded by the pass page and by `rsvp-public.css`'s
  pages that include the partial. **Add a row to CLAUDE.md's page-specific CSS table.** The old `.rsvp-pass-*`
  rules in `rsvp-public.css` are deleted once nothing uses them.

## 3. Phase 2 — downloadable PDF

**Built.** As planned, with these specifics: the cache file is
`guest-pass-pdfs/v1/{token}/{fingerprint}.pdf` — one directory per token, so writing a new render deletes the
stale ones in the same folder and the directory never grows past one file. Orphaned directories (guest deleted
or token regenerated) are swept by the existing daily `invitation:prune-orphaned-files` command rather than by
model hooks, which bulk deletes and event cascades would skip. The cover image is **left out of the PDF**:
covers are stored as WebP and DomPDF cannot embed it. The panel and pass page now offer "Download PDF" in place
of "Download QR code"; the raw `.svg` route still exists. Tests: `tests/Feature/GuestPassPdfTest.php`.

Copy of the ticket pipeline.

- `GuestPassPdfService` beside `TicketPdfService`: `tickets.pdf`-style Blade view `rsvp/pass-pdf.blade.php`,
  A5 portrait, DomPDF, QR embedded as a PNG data URI, logo data URI, cached on the private `local` disk.
- DomPDF has no CSS variables or flexbox: theme colours are substituted as literal hex, layout is tables — same
  constraints the ticket PDF already works within. Font stays DejaVu Sans (already handles the app's copy).
- Cache path `guest-pass-pdfs/v1/{invitation_token}.pdf`. **Must be invalidated** when anything printed on it
  changes — guest name, table assignment, `attendee_count`, event name/date/time/venue, theme — unlike a ticket
  (which is effectively immutable). Simplest correct approach: key the file on a hash of the rendered inputs
  (`sha1` of name|table|admits|event fields|theme) rather than tracking invalidation hooks. Old files fall to the
  existing orphan-prune command's pattern (extend `invitation:prune-orphaned-files` or add a TTL).
- Route `GET /rsvp/{token}/pass/download` (`rsvp.token.pass-download`), `throttle:ticket-download`-style limiter
  (add `guest-pass-download` in `AppServiceProvider`). The "Download QR code" (SVG) link on the panel becomes
  "Download pass" (PDF). The raw `.svg` route stays — nothing should break existing bookmarks.

## 4. Phase 3 — the card as an image (PNG)

**Built.** As planned, with these specifics and deviations:

- **Dynamic height, not a fixed 1520.** The canvas is 1080 wide and as tall as the content needs (≈1400–1800):
  the layout is measured first (title wrapped to at most 3 lines, venue 2, guest name 2, everything ellipsised
  past that), then drawn. A fixed height either wasted space or clipped a long title.
- **Drawn at 2× and downsampled**, because GD antialiases text but not filled shapes. The QR is pasted *after*
  the downsample at its exact pixel size so its modules stay sharp and scannable.
- **GD font sizes are points at 96 dpi**, so the layout (in px) multiplies by 0.75 — the first render drew every
  line a third too large and collided.
- **The header logo is `resources/images/eventhost-icon.png`**, the pink icon rendered once from
  `public/images/logo/EventHost Logo_Icon.svg` with a transparent background. GD cannot rasterise SVG and
  Imagick is optional, so it ships as a PNG asset. Re-render it if the brand icon ever changes.
- **Fonts are `resources/fonts/DejaVuSans{,-Bold}.ttf`**, copied out of the DomPDF package as planned.
- **Cache shared with the PDF via `GuestPassFileCache`** (new): same fingerprint, separate roots
  (`guest-pass-pdfs/v1`, `guest-pass-images/v1`), one file per format per guest. The prune command sweeps both.
- **Fallback:** if GD has no FreeType or a font is missing, `RsvpController::passImage()` reports the error and
  serves the plain QR PNG instead — and does *not* cache it as though it were the card.
- **"Admits" became "Plus one"** across web, PDF and image: an invitation RSVP is only ever 1 or 2, so the row
  reads "Guest + 1" for a guest bringing someone and is omitted for a solo guest (`GuestPassCard::partyLabel()`).
- Tests: `tests/Feature/GuestPassImageTest.php`. Checked visually, including a worst-case long title / name /
  venue, a checked-in guest, and a cancelled event.

Rendered server-side with GD (already a hard requirement, see CLAUDE.md "PHP Extensions Required") — no headless
browser, nothing new to install on cPanel. GD's FreeType support is confirmed present in the local build.

- `GuestPassImageService`: draws the card onto a fixed-size canvas (1080×1520) with `imagettftext()` — event name
  (wrapped to two lines, measured with `imagettfbbox()` so it never overflows), date/time, venue, guest name,
  "Guest + 1" (when applicable), table, the QR, and the state banner. Colours come from the same theme values as the web card.
- **Font:** `DejaVuSans` / `DejaVuSans-Bold` — the same face the PDF uses, so image and PDF match. They already
  ship inside the installed PDF package (`vendor/dompdf/dompdf/lib/fonts/`); copy the two `.ttf` files to
  `resources/fonts/` so the app doesn't depend on a vendor path. Fail soft: if a font can't be loaded, fall back
  to the existing QR-only PNG rather than 500-ing a WhatsApp reply.
- The QR is drawn from `QrCodeService::png()` (GD renderer) and copied onto the canvas; no SVG rasterising.
- Cover image (optional): drawn only if it is a local, readable file; skipped silently otherwise.
- Route `GET /rsvp/{token}/pass.png` (`rsvp.token.pass-image`), same eligibility gate as the other pass routes;
  `?download=1` sets `Content-Disposition`. Cached on the private disk keyed on the same input hash as the PDF
  (§3), so the two never disagree. Throttled like the PDF.
- The pass page and panel offer **"Save as image"** next to **"Download PDF"**.

## 4b. Phase 4 — email and WhatsApp

**Built.** As planned, with these specifics and deviations:

- **Email attaches PDF + card image; each is produced independently.** A renderer that can't run must not cost
  the guest the confirmation, so a failure is reported and skipped; only if *neither* works does the mail fall
  back to the bare QR PNG it always carried. For an eligible guest the button is now **"View your pass"**, and
  changing the RSVP drops to a markdown link in the outro (a `MailMessage` has one action button).
  Ineligible guests (declined, basic-plan host) get the old mail unchanged.
- **WhatsApp sends the card image**: `Guest::entryPassPngUrl()` was repointed at `rsvp.token.pass-image`
  (name kept so callers and tests didn't move), and the caption gains the pass-page link
  (`Guest::passPageUrl()`). The old bare-QR `entry-pass.png` route stays for messages already sent.
- **`pass.png` is deliberately unthrottled** (the PDF keeps `guest-pass-download`). Twilio fetches every guest's
  image from a handful of its own IPs, so a per-IP limit would start failing WhatsApp deliveries at any busy
  event. It is not an abuse vector: it needs a 48-char token and is served from a content-keyed cache.
- **PDF font subsetting turned on** (`enable_font_subsetting`): 885 KB → ~30 KB. The file now rides in an email,
  and DomPDF was embedding all of DejaVu.
- **The PDF is guaranteed one page.** A worst-case title/venue/name pushed the QR onto a second page; the title
  now scales with its length and the free-text fields are capped. Regression test asserts a single page.
- Also removed the `imagedestroy()` calls from the image service — a no-op since PHP 8.0, deprecated in 8.5.
- Tests: `tests/Feature/GuestPassMailTest.php`, plus assertions added to `WhatsAppInboundRsvpTest`.

*Original plan, for the record:*

- **Email** (`RsvpConfirmationNotification`): attach the pass **PDF** *and* the card PNG instead of the bare QR
  PNG; copy: "Your invitation pass is attached — show it at the door." Add a "View your pass" button to the web
  page. Eligibility stays on the existing `hasEntryPassFor()` call.
- **WhatsApp** (`WhatsAppInboundRsvpService::sendConfirmation`): send the **full-card PNG** (`entryPassPngUrl()`
  repointed at `rsvp.token.pass-image`), with the pass-page link appended to the caption. The old bare-QR
  `entry-pass.png` route stays for anything already sent or bookmarked.
- Twilio fetches the PNG by absolute URL, so it must work with no cookies/session and answer fast: it is
  cache-served, and a cold render is a few hundred ms of GD work.

## 5. Phase 5 — API

**Built.** `RsvpResource::entry_pass` (shared by `GET/POST /api/v1/rsvp/{token}` and the open-RSVP endpoints)
keeps `available` and `check_in_qr_url` exactly as they were and adds, all null when there is no pass:
`pass_url`, `pdf_url`, `image_url` (absolute) and a `card` object — `event_name`, `event_type_label`,
`starts_at` (ISO 8601, venue timezone), `has_start_time`, `venue`, `guest_name`, `party_size`, `party_label`
("Guest + 1" or null), `table`, `state` (`valid | checked_in | cancelled | ended`), `state_label`, and `theme`
(`primary` / `accent` / `background` as `#rrggbb`) — enough for a native client to draw the pass itself.
Two small deviations from the list above: `admits` became `party_size` + `party_label` (see the "Plus one"
note), and `has_start_time` was added because `starts_at` falls back to 00:00 for an event with no time.
Built from `GuestPassCard`, so the API can never disagree with the web card, PDF and image. Tests: extended
`tests/Feature/Api/V1/Rsvp/EntryPassExposureTest.php`, including that every URL it hands out serves the file.

*Original plan, for the record:*

`RsvpResource::entry_pass` gains additive fields only (the Android contract in `plans/android-app.md` is
additive-only): `pass_url`, `pdf_url`, `image_url`, and a `card` object (`event_name`, `starts_at`, `venue`,
`plus_one`, `table`, `state`). `check_in_qr_url` and `available` are unchanged. Covered by the existing
`EntryPassExposureTest` plus new assertions.

## 6. Files

| Area | Files |
|---|---|
| New | `rsvp/partials/pass-card.blade.php`, `rsvp/pass.blade.php`, `rsvp/pass-pdf.blade.php`, `public/css/guest-pass.css`, `app/Services/GuestPassPdfService.php`, `app/Services/GuestPassImageService.php`, `resources/fonts/DejaVuSans*.ttf`, `app/Support/GuestPassCard.php` (shared card data: fields, state, theme, cache hash) |
| Edit | `rsvp/partials/entry-pass.blade.php`, `RsvpController` (`pass()`, `passDownload()`, `passImage()`), `routes/web.php`, `AppServiceProvider` (limiter), `RsvpConfirmationNotification`, `WhatsAppInboundRsvpService`, `Api/V1/RsvpResource`, `rsvp-public.css` (remove old rules), `CLAUDE.md` (CSS table + a short "Invitation pass" section) |
| Untouched on purpose | `Guest::checkInQrUrl()`, `CheckInController`, `PublicCheckInController`, every policy and middleware |

## 7. Optional — host side

`GuestController::qrSheet()` (printed badge sheet) and `GuestController::qr()` (single download) could reuse the
same card so a host printing paper invitations gets the same design. Skipped by default — those are name-badge
layouts for staff, not something a guest is handed. Say if paper cards are wanted.

## 8. Testing

- Feature: `/rsvp/{token}/pass` renders event name, date, venue, guest name, plus one, table for an accepted
  guest; redirects to the RSVP page for a declined / unresponded / non-premium-host guest; 404 for an unknown token.
- Feature: card omits venue and table rows (not blank rows) when unset; shows "Checked in" state after check-in;
  cancelled event shows the cancelled state.
- Feature: PDF route returns `application/pdf`; second call is served from cache; editing the guest's table or
  `attendee_count` produces a *different* cache path (the invalidation guarantee above); throttle applies.
- Feature: `/rsvp/{token}/pass.png` returns a real PNG (`image/png`, decodes with `getimagesizefromstring`) of the
  expected dimensions; 404 for an ineligible guest; output differs when table/admits change.
- Feature: confirmation email attaches the PDF and the card PNG for an eligible guest and neither otherwise.
- Feature: WhatsApp confirmation media URL points at the card image, not the bare QR.
- Feature: the QR on the page still encodes exactly `Guest::checkInQrUrl()`.
- Feature: API `entry_pass` keeps `available`/`check_in_qr_url` and adds the new fields.
- Manual: incognito window on the token URL — card themed correctly on light and dark palettes, PDF opens and
  scans with a phone, WhatsApp caption link lands on the card.

## 9. Phasing and effort

1 (card + page) is the visible win and stands alone · 2 (PDF) is mostly a copy of the ticket pipeline plus the
invalidation hash · 3 (PNG image) is the fiddly one — hand-placed GD text, but self-contained · 4 (email +
WhatsApp) is small once 2 and 3 exist · 5 (API) is small. Ship 1 → 2 → 3 → 4 → 5; each phase leaves the app working.

## 10. Open questions

1. Confirm this is the **guest's** pass, not the host's badge sheet (§7).
2. ~~PDF vs image~~ — **resolved: both** (Phases 2 and 3).
3. ~~WhatsApp link-only vs composite PNG~~ — **resolved: full-card PNG** (Phase 4).
4. Should the pass also show the host's name (e.g. "Hosted by …")? Not in the data model as a display field
   today beyond `users.name` — proposing to leave it off.
