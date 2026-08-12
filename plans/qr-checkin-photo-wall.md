# Premium Feature Plan: QR Check-in + Table Photo Wall

Status: **Phase 1 and Phase 2 shipped** (2026-08-13). Real-time gallery push (was optional in Phase 2)
was deliberately skipped — polling stays, since it works on plain cPanel hosting and a websocket layer
doesn't. See "Phase 2 — what shipped" near the bottom for specifics.
Gate: `pro` tier and above (`SubscriptionTier::Pro`) — matches the "Photo gallery" line already listed as
a `pro`-plan feature in `config/billing.php`, and folds in the "QR Event Check-in" item already sitting in
`plans/features.txt` under Advanced Features.

Two sub-features ship together as one premium package:

1. **Invitation QR check-in** — staff scan a guest's personal QR at the door to mark them arrived.
2. **Table Photo Wall** — each physical table gets its own QR code; guests scan it with their own phone,
   no login, and drop a photo straight into a live shared gallery for that event.

---

## 1. Why this fits the existing architecture

- Guests already get a unique `invitation_token` (`app/Models/Guest.php`) and there's a literal `TODO`
  on `personalRsvpUrl()` about wiring up a QR code — this plan finally answers it, but points the QR at a
  **check-in** URL, not the RSVP URL (see §4 on why those must stay separate).
- Plan gating in this app is rank-based (`User::subscriptionTierRank() >= requiredRank`), not per-feature
  booleans — see `User::canUseInvitationTemplate()` (`app/Models/User.php:138-141`). This plan reuses that
  exact pattern rather than inventing a new gating mechanism.
- Image handling already has a working pipeline (Intervention Image, GD/Imagick, WebP re-encode, `public`
  disk) in `EventController::storeCoverImage()` — table photo uploads reuse it as-is.
- No seating/table entity exists yet. This plan introduces the minimum table concept needed for QR codes
  (a named table with a code), without building the full "Seating Arrangement System" also listed as a
  separate future feature in `plans/features.txt`. That system can layer guest-to-table assignment onto
  the `event_tables` table this plan creates, later, without rework.

---

## 2. Data model

### New table: `event_tables`
| column | type | notes |
|---|---|---|
| id | bigint pk | |
| event_id | FK → events, cascade delete | |
| label | string | e.g. "Table 5", "Bar", "Photo Booth" — host-defined, free text |
| code | string, unique | short random slug (8 chars, unambiguous alphabet) used in the public QR URL |
| sort_order | unsigned int | for listing/print order |
| photos_count | unsigned int, default 0 | denormalized counter, kept via model events |
| timestamps | | |

### New table: `event_photos`
| column | type | notes |
|---|---|---|
| id | bigint pk | |
| event_id | FK → events, cascade delete | |
| event_table_id | FK → event_tables, nullable, set null on delete | nullable so a table can be removed without losing photos |
| path | string | webp, `public` disk, same pattern as event cover images |
| thumbnail_path | string | small webp for grid rendering |
| uploader_name | string, nullable | optional free-text the guest can type, not verified |
| status | enum: `pending`, `approved`, `hidden` | default per event setting, see §6 |
| ip_hash | string | `hash('sha256', ip.salt)` — abuse tracking without storing raw IP |
| created_at / updated_at | | |

### `guests` table — add columns
| column | type | notes |
|---|---|---|
| checked_in_at | timestamp, nullable | null = not arrived |
| checked_in_by | FK → users, nullable | which staff account scanned them |

### `events` table — add columns
| column | type | notes |
|---|---|---|
| photo_wall_enabled | boolean, default true | host on/off switch, independent of plan gate |
| photo_wall_requires_approval | boolean, default false | if true, photos land as `pending` and need host approval before showing in the gallery |

---

## 3. Package additions

- **`bacon/bacon-qr-code`** (composer) — generates QR as SVG server-side. SVG is preferred over a raster
  PNG lib because it prints crisp at any size (table tents, badge sheets) and needs no GD/Imagick call —
  keeps QR generation independent of the image pipeline used for photos.
- **`jsQR`** (vendored single-file JS, MIT, no deps) under `public/js/vendor/` — same vendoring pattern
  already used for `sortable.min.js`. Used only on the staff scanner page to decode camera frames client
  side; matches the "no framework, vanilla JS" rule in `CLAUDE.md`. Guests never need a scanner — their
  phone's own camera/QR app opens the table URL directly, no in-app scanning required on their side.

No new backend queue/broadcast infra — see §7 on why the gallery uses polling, not websockets.

---

## 4. Two QR flows, kept deliberately separate

**Guest invitation QR → check-in only, never self-service.** The QR encodes a URL like
`/events/{event}/checkin/{token}` that is **auth-protected** (event owner or staff). A guest who scans
their own invitation just gets a login wall — they can't self-check-in remotely before arriving. Actual
check-in is a POST fired from the *staff's* scanner page, only after staff confirms the decoded name on
screen. This is the reason it must not reuse `personalRsvpUrl()`/the public RSVP token route — that one
needs to stay guest-accessible for RSVP purposes and must not double as an attendance trigger.

**Table QR → public, anonymous, one specific table.** The QR encodes
`/e/{event:slug}/table/{code}` — a fully public route (same trust level as the existing `/e/{slug}` public
invitation page). No login, no invitation lookup, matches how a normal shared-album/wedding-photo app
works. `code` identifies the table only, not a person.

---

## 5. Routes

**Owner-authenticated** (`auth`, `verified`, existing `events.*` scoping pattern):

```
GET    events/{event}/tables                       events.tables.index   (manage + QR previews)
POST   events/{event}/tables                        events.tables.store
PATCH  events/{event}/tables/{table}                 events.tables.update
DELETE events/{event}/tables/{table}                 events.tables.destroy
GET    events/{event}/tables/{table}/qr.svg          events.tables.qr        (single printable QR)
GET    events/{event}/tables/qr-sheet.pdf            events.tables.qr-sheet  (all tables, one PDF via dompdf — already a dependency)

GET    events/{event}/checkin                        events.checkin.scan     (camera scanner UI)
POST   events/{event}/checkin/{token}                events.checkin.confirm  (AJAX: mark arrived)
GET    events/{event}/checkin/lookup?q=              events.checkin.lookup   (manual name/token fallback when a code won't scan)

GET    events/{event}/photos                         events.photos.index     (moderation grid)
PATCH  events/{event}/photos/{photo}                  events.photos.update    (approve / hide)
DELETE events/{event}/photos/{photo}                  events.photos.destroy
```

**Public** (no auth, alongside existing `/e/{slug}` routes):

```
GET  e/{event:slug}/table/{code}            table.upload.show
POST e/{event:slug}/table/{code}/photos     table.upload.store    (throttle:table-upload)
GET  e/{event:slug}/gallery                 event.gallery.show
GET  e/{event:slug}/gallery/feed            event.gallery.feed    (JSON, polled)
```

All owner-side routes go through the existing scoped-binding pattern used for `events.guests.*`.

---

## 6. Feature gating

Add to `User` model, next to `canUseInvitationTemplate()` / `canCreateEvent()`:

```php
public function canUsePremiumEventTools(): bool
{
    return $this->subscriptionTierRank() >= SubscriptionTier::Pro->rank();
}
```

Every owner-side controller in this plan (`EventTableController`, `CheckInController`, `EventPhotoController`)
authorizes against this in a form request / policy, redirecting to `billing.show` with a flash message on
failure — same UX as the existing template-tier gate. Public routes (`table.upload.*`, `event.gallery.*`)
check the *event owner's* current tier at request time (not a snapshot from event-creation time), consistent
with how `canUseInvitationTemplate()` already re-checks live.

If the owner's plan lapses below Pro after the feature was used, existing tables/photos aren't deleted —
QR codes just stop accepting new uploads and the scanner page shows an upsell instead of the camera view.

---

## 7. Table Photo Wall — upload & gallery UX

- Upload page (`table.upload.show`): minimal mobile page, `<input type="file" accept="image/*" capture>` for
  direct camera capture, optional name field, submits via `fetch` to `table.upload.store` so the guest sees
  an inline "uploaded!" state without a full page reload. Shows table label so the guest knows they're
  posting to the right feed.
- Upload validation: image mime whitelist, 8MB cap, re-encoded through the same Intervention Image pipeline
  as `EventController::storeCoverImage()` — `->orient()` to fix phone EXIF rotation, then re-encode to WebP,
  which also strips EXIF/GPS metadata (privacy — don't leak guest location data from photo metadata).
  Generates both the display image and a lightweight thumbnail for the grid.
- Rate limiting: `throttle:table-upload` (e.g. 10 uploads / 10 minutes per IP per event) to blunt spam/abuse
  from an anonymous, unauthenticated endpoint.
- Moderation: controlled by `events.photo_wall_requires_approval`. Default **off** (auto-approve, lowest
  friction — most hosts want the wall to feel instant). Hosts running an open/public event can flip it on;
  photos then sit as `pending` until approved from `events.photos.index`, which also lets the host `hidden`
  anything after the fact even when auto-approve is on.
- Gallery (`event.gallery.show`): public grid page, polls `event.gallery.feed` every ~8s for newly approved
  photos and appends them — no websocket/broadcast infra needed for MVP. If real-time-without-polling
  becomes a priority later, this is the seam where Laravel Reverb/Echo would slot in; nothing here blocks
  that upgrade.

---

## 8. Check-in UX

- `events.checkin.scan`: full-viewport camera view via `getUserMedia`, `jsQR` decodes frames client-side.
  On a decoded value matching the check-in URL pattern, the page extracts the token and POSTs to
  `events.checkin.confirm` over `fetch` — shows the guest's name and a confirm state inline, then
  auto-resumes scanning for the next guest. No full-page navigation per scan (keeps the door line moving).
- Manual fallback (`events.checkin.lookup`): a plain search box for guests whose code won't scan (glare,
  screen brightness, printed card creased, etc.) — search by name, tap to check in.
- Already-checked-in guests show a distinct "already arrived at HH:MM" state rather than silently
  re-confirming, so staff notice a possible duplicate/gate-crash attempt.

---

## 9. Printing

- Per-guest invitation QR: add `?qr=1` render or a small QR block on the guest's invitation email/RSVP
  confirmation, and a `events.guests.{guest}/qr` printable badge for hosts who want physical badges.
- Table QR: `events.tables.qr-sheet.pdf` generates one PDF with every table's QR + label, sized for table
  tents — reuses `barryvdh/laravel-dompdf`, already a dependency, no new PDF tooling.

---

## 10. CSS (per existing page-specific pattern in `CLAUDE.md`)

| File | Loaded by |
|---|---|
| `public/css/tables-admin.css` | `events/tables/index.blade.php` — table CRUD + QR previews |
| `public/css/checkin-scanner.css` | `events/checkin/scan.blade.php` — staff scanner |
| `public/css/table-upload.css` | public table upload page |
| `public/css/event-gallery.css` | public gallery page |

---

## 11. Tests (mirroring existing `tests/Feature` naming)

- `EventTableTest` — CRUD, code uniqueness, gating (403 below Pro tier).
- `CheckInTest` — confirm marks `checked_in_at`, idempotent re-scan, unauthenticated access rejected,
  wrong-event token rejected.
- `TablePhotoUploadTest` — happy path, mime/size validation, throttle enforcement, moderation on/off
  behavior, thumbnail generation.
- `EventGalleryTest` — only `approved` photos surface when moderation is on; all non-`hidden` surface when
  it's off.
- `EventPhotoModerationTest` — owner-only approve/hide/delete, cross-tenant access rejected.

---

## 12. Phased rollout

**Phase 1 (MVP, ships the premium value)**
Migrations → `bacon/bacon-qr-code` → table CRUD + QR → check-in scan/confirm → table upload + gallery
(polling) → basic moderation → Pro-tier gating → tests above.

**Phase 2 — what shipped**
- **Shareable staff scanner link**, no login required — `EventStaffLink` model (`event_id`, `token`,
  `label`, `last_used_at`, `revoked_at`), same bearer-token-in-URL trust model as guest RSVP links.
  Host creates/revokes links from the check-in scanner page; `PublicCheckInController` serves the scan
  page and confirm/lookup endpoints at `/checkin/{staffToken}/...`, rate-limited via a dedicated
  `staff-checkin` limiter. The camera/lookup widget itself is a shared Blade partial
  (`events/checkin/partials/scanner-widget.blade.php`) so the authenticated host page and the public
  link page stay in sync. Check-ins made through a shared link record `checked_in_by = null` (no user
  to attribute it to) but still update `checked_in_at` and the link's `last_used_at`.
- **Bulk guest QR badge-sheet PDF** — `GuestController::qrSheet()` → `events.guests.qr-sheet`, mirrors
  the table QR sheet (data-URI-embedded SVGs, dompdf, Pro-gated). Linked from the guests index header.
- **Check-in arrival analytics** — `CheckInAnalyticsService::arrivalsByBucket()` groups `checked_in_at`
  into 15-minute windows in PHP (portable across SQLite tests / MySQL prod, no DB-specific date SQL).
  Rendered as a plain CSS bar chart on the scanner page — deliberately not a JS charting library, so
  the feature works without a `npm run build` dependency.
- **Multi-file drag-and-drop upload** — `table-upload.js` rewritten to queue multiple files (via file
  picker or drag-and-drop onto the existing drop zone), uploading them sequentially to the same
  single-file endpoint with a per-photo status badge (ready/uploading/added/failed). No backend change
  needed — `StoreTablePhotoRequest` and `EventPhotoUploadService` stayed single-file-per-request.

**Real-time gallery — explicitly skipped.** Reverb needs a persistent process that plain cPanel shared
hosting generally can't run; Pusher would work but needs a vendor account and keys. Decided (2026-08-13)
to keep the existing ~8s poll rather than take on either cost now. Revisit if it becomes a real complaint
— the poll endpoint (`event.gallery.feed`) is already the seam either approach would hook into.

---

## Open decisions — resolved

1. Gate floor: **`pro`**, confirmed.
2. `photo_wall_requires_approval` default: **off** (auto-approve), confirmed.
3. Gallery page tied to `is_public`: confirmed — `Event::photoWallIsLive()` requires it.
