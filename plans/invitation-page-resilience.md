# Invitation page resilience

Status: all phases built (see `CLAUDE.md` → Invitation page → Resilience for what shipped; `docs/deployment.md` §3d for the real-browser checklist). Origin: review of how the guest-facing invitation page behaves on a slow
connection, when an image fails to load, and with JavaScript off. Related: `plans/invitation-page-edge-cases.md`
(missing files are already skipped when the page is built; this plan is about what happens in the browser).

Pages affected: `events/public.blade.php`, `rsvp/token-show.blade.php`, `events/preview.blade.php`,
`templates/preview.blade.php` (all load Swiper, GLightbox and `invitation-public.js`), plus the layouts under
`events/invitations/`. Test with the guest-facing pages only; the host editor is not in scope.

## Principles

1. **Content first, enhancement second.** The page must be readable and the RSVP form usable with no JavaScript and
   with every third-party host unreachable. Scripts add motion, a slider and a lightbox on top.
2. **Nothing the guest needs may depend on a CDN.** Swiper and GLightbox move to our own `public/vendor/`.
3. **Heavy media waits for the guest.** A background video or YouTube player never starts by itself on a slow or
   data-saver connection.
4. **A failed image degrades quietly:** it is hidden or replaced by a calm placeholder, and the layout does not jump.

## Decisions

1. **Declined / Maybe means zero seats, whatever count was sent.** The server stops rejecting it (approved).
2. **Background video and YouTube:** on a slow or data-saver connection the cover image shows and the guest starts
   the video themselves; elsewhere it behaves as today (approved).
3. **Smaller gallery copies:** each gallery photo gets a ~600px WebP copy next to the existing one, served through
   `srcset` (approved). Hero cover, portraits and couple photos keep a single size.
4. **My calls:** self-host Swiper and GLightbox and load them only on pages with a gallery; invitation content is
   visible with JS off; a static countdown without JS; an image that fails to load is hidden without a layout jump.

## Phase 1 — Content is visible and correct without JavaScript (gaps 9, 10, 13)

- **Reveal layouts.** `events-invitation-layout-wedding-invitation(.noir).css` hide `.wi-reveal` / `.wi2-reveal` at
  `opacity: 0`. Scope the hidden state to `html.js` (a class a one-line inline script in `<head>` adds before first
  paint), so with JS off, with the script slow, or with it failing, the content simply shows. Keep the existing
  reduced-motion override. Add a safety net: if `invitation-public.js` has not run within a few seconds, the same
  script reveals everything (a `setTimeout` guard in the inline snippet).
- **Countdown.** Render a real, static value server-side ("Saturday, 20 November 2026 at 3:00 PM", the markup the
  `countdown_enabled = false` branch already has) and let `invitation-public.js` swap in the live ticker. No more
  "0 days 0 hours 0 minutes 0 seconds" before the script runs or with JS off. Mark the ticker `hidden` until JS
  enhances it.
- **`<noscript>`.** One short note near the top of the invitation ("Some extras, like the gallery slider and music,
  need JavaScript. Everything you need to RSVP works without it.") shown only when it matters, plus the plain
  `<a href>` image links the gallery already has.
- Tests: with the `js` class absent the reveal CSS does not hide content (assert on the stylesheet rule scoping);
  countdown markup contains the server-rendered date; the noscript note is present on the public and personal pages.

## Phase 2 — RSVP works without JavaScript (gap 12)

- `ValidatesRsvpPayload`: for **Declined** and **Maybe** the submitted `attendee_count` is ignored instead of
  failing with "Attendee count must be zero". Accepted is unchanged (1 to max).
- `RsvpSubmissionService::submit()` already stores 0 for those statuses and never trusts the count; keep a test that
  proves a Declined with count 1 is stored as 0. This is additive for the API (it accepts more, rejects nothing new).
- The form still preselects 0 with JS (`rsvp-form.js` unchanged). Without JS, the count dropdown is simply
  ignored for Declined / Maybe; update its hint to "Only used when you are attending."
- Tests: Declined with count 1 succeeds and stores 0, on the personal link, the open form, the group link and the
  API; Maybe likewise; Accepted with 0 or above max still fails.

## Phase 3 — Libraries: ours, and only when needed (gaps 1, 11)

- Vendor Swiper 11 and GLightbox into `public/vendor/swiper/` and `public/vendor/glightbox/` (CSS + JS, pinned
  versions, a short `README` with the version and licence). Remove the jsDelivr links from `events/public.blade.php`
  and the three other pages.
- One shared partial, `events/invitations/partials/gallery-assets.blade.php`, pushes the CSS and the deferred JS **only
  when the merged invitation has gallery images** (or a layout lightbox that needs GLightbox). No gallery, no
  library, no request.
- **No-JS gallery.** Style `[data-inv-gallery]` so that without `html.js` the photos are a plain wrapped grid (the
  Swiper wrapper is not a clipped flex row), and the arrow and dot controls are hidden until Swiper initialises. The
  slider layout applies only under `html.js`.
- **Library failure.** If `window.Swiper` is undefined after load, `invitation-public.js` leaves the grid in place
  (it already returns early; add the class swap so the grid is what is left).
- Fonts: one combined Google Fonts request for all of the invitation's families instead of one per family; add
  `preconnect` for `fonts.gstatic.com` (with `crossorigin`) and drop the `images.unsplash.com` preconnect from the
  invitation pages (it stays where sample photos are shown, i.e. template previews).
- Tests: a page with no gallery contains no swiper/glightbox asset; with a gallery it contains the local paths and
  no `cdn.jsdelivr.net`; the files exist under `public/vendor`.

## Phase 4 — Heavy media waits for the guest (gap 2)

- **Hero `<video>`** (`sections/hero`, `beauty_for_ashes`, `pro_magazine`): add `preload="none"`; keep the cover as
  the `poster`. `invitation-public.js` starts it only when the connection is not slow
  (`navigator.connection.saveData` false and `effectiveType` not `2g` / `slow-2g`; when the API is missing, assume
  fine). Otherwise it shows a small "Play video" button over the cover and starts on tap.
- **YouTube background `<iframe>`:** render it as a placeholder (`data-embed-src`) with `loading="lazy"` semantics,
  created by JS under the same connection test; without JS or on a slow connection the cover shows and a plain
  "Watch on YouTube" link (`InvitationVideoBackground::watchUrlFromStored()`) is offered.
- Respect `prefers-reduced-motion` for autoplay (no autoplay when set).
- Tests: markup carries `preload="none"` and no `<iframe>` in the initial HTML for a YouTube background; the fallback
  link is present; a unit test of the connection predicate is not possible in PHP, so cover it with a small JS test
  or a documented manual check (see Verification).

## Phase 5 — Images: priority, sizes, and graceful failure (gaps 3, 4, 6, 7)

- **Priority.** The hero cover (and the first portrait on layouts that lead with one) gets `fetchpriority="high"`
  and `decoding="async"`; everything below the fold keeps `loading="lazy"`. No preload tags (they cost a request when
  the layout hides the image).
- **Gallery variants.** `ProcessInvitationDesignImageJob` writes a second WebP, scaled down to 600px wide, beside
  the 1200px one: `<name>.webp` and `<name>-600.webp`. Nothing new is stored in the customization JSON: the small
  path is derived from the large one by one helper (`InvitationMediaUrl::smallVariant()`), which returns null when
  the file does not exist (old photos), so those keep a single `src`. `InvitationMediaHealth` and the prune command
  (`invitation:prune-orphaned-files`) must treat `-600` files as belonging to their parent; deleting or replacing a
  photo deletes both. An artisan command `invitation:make-gallery-variants` backfills existing photos in chunks.
- **`srcset` / `sizes`** on gallery `<img>` in all gallery partials: `<small> 600w, <large> 1200w`,
  `sizes="(min-width: 880px) 33vw, (min-width: 560px) 50vw, 90vw"`. The lightbox link keeps pointing at the large file.
- **Failure.** A tiny script (in `invitation-public.js`, plus an inline `onerror` is not allowed by CSP-style hygiene,
  so use `error` events in capture phase on `.evt-invitation img`): a gallery image that fails is removed from its
  slide/grid cell (and the slider is told to update); a hero or portrait image that fails gets a class that swaps in a
  calm theme-coloured block of the same size, so the text over it stays readable and nothing jumps. A failed image
  with width/height set already reserves its box; the placeholder keeps it.
- **Alt text.** Hero cover: the event name. Gallery photos: "Photo N of M from {event name}". Couple / portrait
  photos: their existing caption if the layout has one, else a short generic label. Decorative backgrounds stay
  `alt=""` with `aria-hidden`.
- Tests: `srcset` present only when the small file exists; deleting a photo removes both files; prune treats
  `-600` as referenced; backfill command creates variants and is idempotent; hero cover has `fetchpriority="high"`
  and gallery images do not; alt text present.

## Phase 6 — Docs, caching and verification

- `CLAUDE.md` "Invitation page": add the progressive-enhancement rule (`html.js`), the vendored libraries, the
  connection-aware media rule, the gallery variants and where to add a new reveal layout safely.
- **Caching headers** are not set in the app: add a note to `docs/deployment.md` to serve `/storage/*` and
  `/vendor/*` with a long `Cache-Control` (`public, max-age=31536000, immutable` is safe because stored filenames
  are content-unique after the WebP conversion). Verify against the real host; do not change app code for it.
- **Verification (manual, once):** Chrome DevTools "Slow 3G" + CPU 4x with cache disabled; "Offline" after load;
  JavaScript disabled; block `cdn.jsdelivr.net` and `fonts.googleapis.com`; Save-Data on. Check every layout
  variant, not only the standard one. Record the first-contentful-paint and total transferred bytes before and
  after on one gallery-heavy wedding invitation.

## Order and risk

Phase 1 (visible without JS) and Phase 2 (RSVP without JS) first: both fix outright broken pages and are small.
Phase 3 next (removes the CDN dependency); Phase 4; Phase 5 last (touches the image job and storage; the backfill
is run once after deploy). No migrations. API changes are additive. The riskiest piece is Phase 5's variant
handling in the prune command: a unit test must prove a `-600` file is never orphaned while its parent is live.
Run the full suite once at the end of the last phase, not after each one.
