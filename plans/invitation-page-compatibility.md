# Invitation page compatibility

Status: all phases built (see `CLAUDE.md` → Invitation page → Resilience → Compatibility for what shipped; `docs/deployment.md` §3d for
the real-device checklist). Origin: review of how the guest-facing invitation behaves on an old Android phone, in
WhatsApp's browser, in Facebook's browser and on desktop. Builds on `plans/invitation-page-resilience.md` (that plan
made the page survive slow networks, failed images and no JavaScript; this one makes it survive old engines and in-app
browsers). Pages affected: the same guest pages (`events/public`, `rsvp/token-show`, previews) and their layouts.

## Support floor (decided)

**Readable, RSVP works, looks plainer.** On an old browser the invitation must show all its text and controls and
the RSVP form must submit; it does not have to look identical. So fallbacks go only where the missing feature would
break the page — overlays that stop covering, text that becomes invisible, sizes that collapse, images with no frame.
Pure decoration (a tinted border, a soft shadow) may simply drop. We do **not** rewrite ~200 declarations for visual
parity. "Old" here means roughly Chrome/WebView before v90 (2021) and iOS before 15.4.

## What was found

Counted in the invitation stylesheets, none with a fallback: `color-mix()` 98 (Chrome 111+), `clamp()` 67 (mostly
`font-size` and `padding`), `inset` 28 rules with no `top/left` (Chrome 87+), `aspect-ratio` 17 with no height (Chrome 88+),
plus flex `gap`, `:has()` and `100vh`. `boot()` in `invitation-public.js` runs nine steps with no `try/catch`. There is no
`@media print`. `og:image` is a WebP. Font Awesome (41 icons on guest pages) and Google Fonts load as render-blocking CSS from
third-party hosts, which contradicts the rule in `CLAUDE.md` that nothing on a guest page depends on a third-party host.

## Decisions

1. Support floor as above (approved).
2. **Share image:** a 1200×630 JPEG per event for `og:image` (approved).
3. **Font Awesome:** self-host (approved). Decided approach: download the ~41 icons actually used as SVG files and turn them into
   **one small CSS file for guest pages** that draws each `fa-*` icon with `mask-image`. Templates keep their existing
   `<i class="fa-solid fa-music">` markup, so no per-template edits. Chosen over self-hosting the three full webfonts (about 300 KB
   of fonts plus a 100 KB stylesheet for 41 icons). The app's own pages keep the full CDN stylesheet.
4. **Background video where the connection cannot be measured (my call):** when the Network Information API is missing, start
   media automatically only on a **desktop-class** device (fine pointer, wide screen); on a phone, or in a recognised in-app
   browser (Facebook, Instagram), show "Play video" instead. Reason: iOS, WhatsApp and Facebook are exactly where guests are
   on cellular data and we cannot see the speed; the cost of asking is one tap, the cost of guessing wrong is megabytes.
   WhatsApp's own browser cannot be detected reliably (iOS sends no marker), so this rule is "phone and unmeasurable", not a
   user-agent list.

## Phase 1 — One failure must not stop the rest (gap 2)

- `boot()` runs every step in its own `try/catch` (a small `safely(name, fn)` helper that swallows and, in a dev build, logs to
  `console.warn`). The reveal steps run **first**, then countdown, then the heavy optional ones (gallery, hero media, audio, lightboxes).
- `data-inv-ready` is set **after the reveal steps have run**, not before, so the stall guard still rescues a page where those
  steps themselves threw.
- Tests: a step that throws does not prevent the following steps (run the script in Node with a stubbed `document` where
  `Swiper` throws; assert reveal still runs), and `data-inv-ready` is set after the reveals.

## Phase 2 — Fallbacks only where the page breaks (gaps 1, 3)

Mechanical, safe codemods first, then hand-reviewed colours.

- **`inset` → longhands.** Replace every `inset: X` in the guest stylesheets with `top/right/bottom/left` (all 28 rules). No
  fallback line needed: the longhands work everywhere.
- **`clamp(min, pref, max)` → a fixed line before it** on `font-size`, `padding`, `width`, `height`, `gap`. The fallback is the `min`
  (old phones are small). Because a browser that understands `clamp()` takes the later declaration, nothing changes for modern ones.
- **`aspect-ratio` → a `height`/`min-height` fallback** where the frame would otherwise collapse (image frames, gallery cells,
  hero blocks); with `@supports (aspect-ratio: 1)` not needed since a later `aspect-ratio` simply wins.
- **`color-mix()` — hand-reviewed per file, by property:**
  - `color`: fallback is the nearest plain colour already used in that rule (usually `inherit` or a hex), chosen so text stays readable
    on whatever the background becomes if its own declaration is also dropped.
  - `background` / `background-color`: fallback is `transparent` for tints and the surface colour for solid cards, again checked together
    with the text colour in the same rule (a dropped dark background under white text is the failure to avoid).
  - `border-color`, `box-shadow`, gradients: may drop; add a fallback only if the border or shadow is what separates two blocks.
  - **Custom properties** (`--x: color-mix(...)`) do not fall back by repeating them (any token stream is a valid value, so the later
    declaration wins and `var(--x)` then goes invalid). Put plain-colour fallbacks in an `@supports not (color: color-mix(in srgb, red, blue))`
    block per layout instead.
- **Flex `gap` → margins** only where touching items would be unreadable (button rows, icon + label, metadata lines).
- **`:has()`** only if a rule's absence breaks something; otherwise leave.
- **Static guard test:** every `color-mix`, `clamp`, `inset` and `aspect-ratio` declaration in the guest stylesheets either has a fallback
  line immediately before it or sits in an allow-list of decoration-only properties (`box-shadow`, `border-color`, `filter`, …). Adding an
  unprotected one fails the build.
- **Degraded-rendering check (manual, in the app's browser):** a small dev-only script strips every unsupported declaration from the
  loaded stylesheets and re-applies them, so each layout can be seen as an old engine would draw it. Run it on all eleven layouts.

## Phase 3 — Heroes, print and a no-JS calendar (gaps 9, 11, 13)

- **`100vh` → `100svh` progressively:** `min-height: 100vh; min-height: 100svh;` on Beauty for Ashes, Event Invite and Modern Minimal; the
  Wedding Noir fixed `height: 100vh` gets the same pair plus a `min-height` so a short screen never clips its content. Old engines keep `vh`.
- **Print:** a `@media print` block in `events-invitation.css` that makes every `.wi-reveal` / `.wi2-reveal` section opaque, removes
  animations and transforms, drops the hero's viewport height, hides video, audio, sliders' controls, the preview banner and the section
  nav, expands the gallery to a grid, and avoids breaking inside a section.
- **Thank-you page calendar menu without JS:** `html:not(.js) .rsvp-thanks-menu[hidden]` shows the panel, and the trigger button is
  hidden, so a no-JS guest still gets the Google, Outlook and `.ics` links.
- Tests: the print rules exist and cover every reveal class; the stylesheet pairs `100vh` with `100svh`; the thank-you menu rule exists.

## Phase 4 — No third-party icon or font stylesheet blocking the page (gap 14)

- **Icons:** download the Font Awesome Free 6.5.2 SVGs for the icons used on guest pages (listed by scanning guest views for `fa-*`
  classes; about 41), keep them under `resources/icons/fa/` with the licence (CC BY 4.0, attribution in the generated file header), and
  add `php artisan icons:build-guest-css`, which writes `public/css/guest-icons.css`: one rule per icon with the SVG inlined as a
  `mask-image` (with `-webkit-mask` for old Android), `background: currentColor`, and the `fa-solid`/`fa-regular`/`fa-brands` base sizing so
  existing markup lines up. `fa-fw`, spin and size helpers that guest pages use are covered; others are dropped.
- **Layout switch:** `layouts/site.blade.php` skips the cdnjs Font Awesome `<link>` and loads `guest-icons.css` when the page sets
  `$guestPage` (invitation page, personal RSVP page, previews, thank-you and closed pages). The app and admin pages keep the CDN file.
- **Google Fonts without blocking paint:** on guest pages the font stylesheet is requested with `rel="preload" as="style"` and switched to
  `stylesheet` on load, with a `<noscript>` plain link, so text paints in the fallback font immediately (`display=swap` already
  swaps it in).
- **Static guard test:** every `fa-*` class found in a guest view has a rule in `guest-icons.css`; a guest page contains no
  `cdnjs.cloudflare.com`.
- Update the rule in `CLAUDE.md`: guest pages depend on no third-party **stylesheet or script**; Google Fonts and the Google Maps iframe
  are the two accepted exceptions, both non-blocking.
- **Download step:** the SVGs come from jsDelivr (`@fortawesome/fontawesome-free@6.5.2/svgs/...`), the same version the site loads today.
  Approved in principle; the exact file list is shown before fetching.

## Phase 5 — Link previews in WhatsApp and Facebook (gaps 5, 6)

- **One source of truth for the share image.** Move the "which picture represents this event" logic out of
  `public-invitation-meta.blade.php` into `App\Support\InvitationShareImage::sourcePath()` (cover → first gallery photo; no cover → couple
  or hero portrait → gallery → default), used by the partial and by the job below.
- **A JPEG per event:** `GenerateEventShareImageJob` (dispatched after the cover or the design saves, like the WebP job) crops the chosen
  source to 1200×630, JPEG quality ~82, and writes `invitation-share/{event_id}-{hash}.jpg`, deleting the previous file. The hash is in the
  file name because WhatsApp and Facebook cache a preview by image URL; a changed picture must be a new URL.
- **The partial** uses the JPEG when it exists (falling back to today's image, so nothing regresses before a backfill) and adds
  `og:image:width`, `og:image:height`, `og:image:type` and `og:image:alt` (the event name).
- **Housekeeping:** add `invitation-share` to the directories `EventPurgeService` removes and the prune command scans, with the current
  file as the only referenced one; an artisan command `invitation:make-share-images` backfills existing events (idempotent, `--dry-run`).
- **Deployment note:** after deploy, confirm `og:image` is an `https://` URL (`curl -s <url> | grep og:image`); behind a proxy a wrong
  `APP_URL` makes it `http://`, which WhatsApp may ignore. Clear a cached preview with Facebook's Sharing Debugger.
- Tests: the job writes a 1200×630 JPEG and replaces the old file; the meta tags name it with width, height, type and alt; deleting or
  changing the cover regenerates it; prune keeps the live file and removes a stale one; purge deletes the folder.

## Phase 6 — Media defaults for unmeasurable connections (gaps 7, 10)

- `connectionAllowsMedia()` becomes a three-way answer: **yes** (API says fine), **no** (Save-Data or 2g), **unknown** (no API). Unknown
  starts media only for a desktop-class device (`(pointer: fine)` and a wide viewport); a phone or a recognised in-app browser (Facebook
  `FBAN`/`FBAV`, Instagram) gets the "Play video" button. Behaviour for Chrome-on-Android (which reports the connection) is unchanged.
- The same three-way rule gates the YouTube iframe and the music autoplay attempt (closing the open item from the resilience plan: the audio
  file is no longer fetched on page load on a phone, only when the guest taps).
- **In-app browser hint:** when a Facebook or Instagram webview is recognised, a one-line note above the calendar links says "If a link does not
  open, use the menu to open this page in your browser". `.ics` stays (Apple users need it) and stays last. WhatsApp's webview cannot be
  recognised, so it gets no note; Google and Outlook calendar links work there.
- Tests: the predicate returns the right answer for each combination (run in Node with fake `navigator`/`matchMedia`); markup unchanged.

## Phase 7 — Docs and verification

- `CLAUDE.md` "Invitation page → Resilience": add the support floor, the fallback rule for new CSS (a guard test enforces it), the guest
  icons file and how to add an icon (`icons:build-guest-css`), the share image job, and the three-way connection rule.
- `docs/deployment.md` §3d: `invitation:make-share-images`, `icons:build-guest-css` (run at build time, output committed), the `og:image`
  `https` check, and the Facebook Sharing Debugger step.
- **Real-device checklist (manual, cannot be done from here):** an Android phone with an outdated WebView (or Chrome 70-ish in an emulator),
  WhatsApp (Android and iOS) opening a shared link, Facebook (Android and iOS) opening one, an iPhone on iOS 15, Safari/Firefox/Chrome on a
  laptop, and print preview. For each: text readable, RSVP submits, calendar links work, the preview card shows the picture.

## Out of scope (deliberately)

- Visual parity on old engines; replacing all `color-mix()` with computed colours.
- The cover being soft on very large or retina screens (it is cropped to 1200×630 at upload and the content column is 720px by design).
- Opera Mini extreme mode and UC Mini: behaviour not verified here and not designed for; the no-JS work helps but is not a promise.
- A waitlist, new layouts, or changing what the host can upload.

## Order and risk

Phase 1 (small, removes a blank-page failure) then 2 (largest; mechanical codemods first, then reviewed colours, with the guard test), 3, 4
(needs the icon download), 5 (touches storage and the cover job; backfill after deploy), 6, 7. No migrations. Everything is additive for the
API. The riskiest piece is Phase 2's colour fallbacks: a wrong fallback can make text unreadable on a modern browser only if it is placed
**after** the real declaration, so the guard test also asserts the fallback line comes first. Run the full suite once at the end of the last
phase, as before.
