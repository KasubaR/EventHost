# Deploying a code update — checklist

Read this before uploading/pulling any change to eventhostzm.com (or any other production host).
It exists because skipping the steps below has caused real outages: on 2026-07-19 the `/contact`
page went down site-wide for over three weeks (`Target class [App\Http\Controllers\ContactController]
does not exist`) because new code was deployed without regenerating the Composer autoloader — the
class existed on disk but nothing told the optimized classmap it was there. The same failure mode
(class file present, autoloader stale) also took out the Vite build on day one and dompdf on 2026-05-24.
All three are the same mistake in different clothes — run every command below, every deploy, in order.

## 1. Every deploy — run these in order

```bash
composer install --no-dev --optimize-autoloader
composer deploy
```

`composer deploy` (see `composer.json`) runs `migrate --force`, `storage:link`, `optimize:clear`,
`config:cache`, `route:cache`, `view:cache` and `queue:restart` in that order — it's the second half
of this checklist as one command, so there's nothing left to mistype or forget. It cannot come first:
`composer install` has to finish before `composer deploy` (or any `artisan` command) can run at all,
since that's what generates the autoloader those commands depend on. If you'd rather run the steps
individually or see exactly what runs, the equivalent is:

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan storage:link
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

- **`composer install` must run first**, before any `artisan ... :cache` command. Route caching and
  config caching don't themselves fail if a class is missing, but the very next request that hits
  that class will 500 until the classmap is regenerated — that's exactly what happened with
  ContactController. Running it first removes the window entirely.
- `storage:link` is safe to re-run — Laravel prints "The [public/storage] link already exists." and
  exits cleanly if it's already there.
- `queue:restart` picks up code changes for already-running queue workers (Supervisor keeps them
  alive across the restart signal — see §"Queue workers" in
  [authentication-deployment.md](authentication-deployment.md)). Skipping this means workers keep
  running the *old* code in memory until something else restarts them.

If you only changed Blade/CSS/JS with no new PHP classes, the full sequence is still cheap enough
to run every time — don't try to guess which steps you can skip. Guessing wrong is exactly how the
ContactController outage happened.

## 2. When the change touches frontend assets (`resources/js`, `resources/css`, anything Vite bundles)

```bash
npm ci
npm run build
```

Run this **before** `php artisan view:cache` above if you're doing a one-shot deploy, so the first
cached view render already sees the new `public/build/manifest.json`. If Node isn't available on the
production host, build assets locally or in CI and upload the resulting `public/build/` directory —
either way, confirm `public/build/manifest.json` exists and is fresh *before* traffic hits the site.
A missing manifest is the `Vite manifest not found at: .../public/build/manifest.json` error.

## 3. Sanity check after every deploy

- [ ] Load `/` and `/contact` in a real browser (not just `curl` — confirm no 500, no stale-looking assets)
- [ ] Tail the log for a minute: `tail -f storage/logs/laravel.log` and click around the nav
- [ ] Confirm queue workers are alive: `php artisan queue:monitor` or check Supervisor status
- [ ] If migrations ran, spot-check one changed table in `php artisan tinker` or phpMyAdmin

## 3b. Deleted-event purging (off by default — read before enabling)

`events:purge-deleted` runs daily but does nothing while `EVENT_TRASH_RETENTION_DAYS=0` (the default). It
**permanently deletes** events that have been in Recently deleted longer than that many days. Everything it needs
is built (countdown UI, warning email, Privacy wording, account guard — `plans/event-retention.md`); the Privacy
page's retention section changes with this setting, so turning it on also publishes the 30-day wording. Get that
wording reviewed first if you need it to be. To go live, in order:

1. Set `EVENT_TRASH_RETENTION_STARTS_AT=YYYY-MM-DD` (the release date) in `.env`
2. Set `EVENT_TRASH_RETENTION_DAYS=30` and `php artisan config:cache`
3. `php artisan events:warn-pending-purge --dry-run` — lists who would be emailed about which events; nothing is sent
4. The scheduler now does the rest: each night it emails hosts a week before removal (02:30) and then purges (03:00).
   `events:purge-deleted` only deletes an event whose host was warned **at least 24 hours earlier**, so it will
   correctly report nothing purgeable until the first warnings have gone out; `events:purge-deleted --dry-run`
   is never refused and shows what it would take

A suspended host, or one with no email, is never warned — and their deleted events are therefore never purged.

With days > 0 and the date unset, the command refuses to run while old trash exists — deliberately.

## 3b-2. Card payments through Astragate (off by default)

Card is the only thing Astragate handles (billing + ticket checkout); mobile money and bank transfer stay on Lenco.
Settings live in `config/astragate.php`, routes in `routes/astragate.php`.

1. Set `ASTRAGATE_CLIENT_ID`, `ASTRAGATE_CLIENT_SECRET`, and a random `ASTRAGATE_WEBHOOK_SECRET`
   (`php -r "echo bin2hex(random_bytes(24));"`). For production also switch `ASTRAGATE_API_BASE_URL` / `ASTRAGATE_AUTH_URL`
   from the `*.dev.astragate.africa` sandbox hosts to the live ones.
2. In the Astragate merchant portal register the callback URL `{APP_URL}/webhooks/astragate/{ASTRAGATE_WEBHOOK_SECRET}`.
   Callbacks are unsigned: the secret in the URL is the credential, and the app re-reads every payment's status from
   Astragate before acting on a callback.
3. Run `php artisan migrate` (adds `gateway` + `checkout_session_id` to `payments` and `ticket_payments`) and `php artisan config:cache`.
4. Last, set `ASTRAGATE_CARD_ENABLED=true`. Until then no "Card" option is shown and the Privacy/Terms/Cookies copy does not mention Astragate.
5. The pollers (`payments:poll-pending`, `tickets:poll-pending`) also cover card payments if a callback is missed.

## 3c. Guest email reminders (off by default)

`events:send-guest-email-reminders` runs daily at 09:00 Africa/Lusaka and does nothing while
`COMM_GUEST_EMAIL_REMINDERS_ENABLED=false` (the default). It emails Accepted guests of Pro+ hosts 7 days, 1 day and 0 days
before an event. Everything it needs is built (the email, the guest "stop reminders" link, the Privacy wording —
`plans/guest-email-reminders.md`), and the Privacy page's reminder text changes with this flag, so turning it on also publishes
that wording. Get that wording reviewed first if you need it to be. To go live, in order:

1. `composer deploy` has run the `guests.email_reminders_stopped_at` migration (part of the normal deploy)
2. The scheduler and a queue worker are running — the emails are queued on `default`, exactly like the WhatsApp reminder
3. `APP_KEY` is the production key and `APP_URL` is right: every email carries a signed "Stop reminder emails" link and
   `List-Unsubscribe` headers, and a wrong `APP_URL` makes those links point at the wrong host
4. Rehearse — works while the flag is still off, sends and logs nothing:

   ```bash
   php artisan events:send-guest-email-reminders --dry-run
   ```

   It prints how many guests of which events would be emailed today (add `-v` for the addresses). An empty result is normal
   unless an event is exactly 7, 1 or 0 days away
5. Set `COMM_GUEST_EMAIL_REMINDERS_ENABLED=true` and `php artisan config:cache`. The scheduler does the rest at 09:00. Each guest
   gets each reminder once, and a moved event is reminded again for its new date

Check it afterwards in `notification_logs` (type `guest_event_reminder_email`). To turn it off, set the flag back to `false` and
`config:cache`; the Privacy wording reverts with it. The RSVP-deadline reminder email is separate and is already on for Pro+ hosts —
it now carries the same stop link.

## 3d. Invitation page: assets, small photo copies, caching, and a real-browser check

Added with `plans/invitation-page-resilience.md`. Nothing here is a migration; none of it can break a deploy.

**Upload `public/vendor/`.** It holds Swiper and GLightbox (served by us, no CDN). It is not built by Vite, so a
`npm run build` does not create it. A missing file only costs the gallery slider (the photos still show as a grid).

**Backfill the small gallery copies once**, after the first deploy of this change:

```bash
php artisan invitation:make-gallery-variants --dry-run   # what it would write
php artisan invitation:make-gallery-variants             # writes the 600px copies
```

New photos get their small copy automatically (queue worker: `ProcessInvitationDesignImageJob`). The command is safe to
re-run; it skips photos that already have one or are not wider than 600px. Until it runs, old photos simply load at full
size. The nightly `invitation:prune-orphaned-files` keeps a small copy while its photo is in use.

**Backfill the link-preview images once** (`plans/invitation-page-compatibility.md` Phase 5). WhatsApp and Facebook previews use a
1200×630 JPEG per event, written by a queued job when the cover or design changes; existing events need one run:

```bash
php artisan invitation:make-share-images --dry-run   # which events would get one
php artisan invitation:make-share-images
```

Then check one event's page: `curl -s https://<host>/e/<slug> | grep og:image` must show an **`https://`** URL ending in
`invitation-share/<id>/<hash>.jpg`. Behind a proxy a wrong `APP_URL` makes it `http://`, which WhatsApp may ignore. WhatsApp and Facebook
cache a preview by image URL; a changed picture gets a new URL by design. To refresh one already shared, use Facebook's Sharing Debugger
(https://developers.facebook.com/tools/debug/) and "Scrape Again".

**Guest icons are built, not edited.** `public/css/guest-icons.css` comes from `resources/icons/fa` via
`php artisan icons:build-guest-css` (output is committed, so nothing runs at deploy). If you add an icon to a guest page, add its SVG,
run the command and commit both; `php artisan icons:build-guest-css --check` fails when the file is stale.

**Long cache lifetime on photos and libraries (server config, not app code).** Gallery, hero and couple photos get a new
random file name on every upload, and the vendor libraries are requested with `?v=<version>`, so both can be cached for a
year without ever being stale. On Apache / cPanel, add this to `public/.htaccess` **above** the Laravel rewrite block:

```apache
<IfModule mod_rewrite.c>
    RewriteRule ^(storage/invitation-(gallery|hero|couple)/|vendor/) - [E=INV_LONG_CACHE:1]
</IfModule>
<IfModule mod_headers.c>
    Header set Cache-Control "public, max-age=31536000, immutable" env=INV_LONG_CACHE
</IfModule>
```

Do **not** extend it to the rest of `/storage` (profile photos and covers may reuse a name) or to `/css` and `/js` (their
URLs carry no version). Confirm with `curl -I https://<host>/storage/invitation-gallery/<id>/<file>.webp` and look for the
`Cache-Control` header.

**Check it in a real browser, once per release that touches the invitation page.** The automated tests check markup and
code, not how a browser behaves. Use a gallery-heavy wedding invitation and the standard one:

- [ ] DevTools → Network → **Slow 4G / Slow 3G**, cache disabled: text and the cover show before the gallery; gallery
      images are the `-600` files on a phone-width viewport
- [ ] Network → **Offline** after load: no blank page, no broken-image icons, nothing stuck invisible
- [ ] **Block `fonts.googleapis.com`**: the page still reads (fallback font)
- [ ] **JavaScript disabled** (DevTools → Command menu → "Disable JavaScript"): every section visible on the Wedding
      Invitation and Noir layouts; the countdown shows "Starts …" as a sentence; the gallery is a grid; a YouTube
      background shows a "Watch the video" link; **Not attending** with the default count saves; the `<noscript>` note shows
- [ ] Network → throttle to **2g** or tick **Save-Data**: the background video does not start by itself and a **Play video**
      button appears; tapping it starts it
- [ ] Rename one gallery photo on disk to simulate a failure: that photo disappears from the slider, nothing else moves
- [ ] Repeat the gallery check on the layouts that use only GLightbox (wedding, modern minimal, botanical, dusty blue)

**Old phones, in-app browsers and desktop** (`plans/invitation-page-compatibility.md`). None of this can be tested from a laptop; use real
devices, or at least a recent Android Chrome with an old WebView / BrowserStack. For each, open a gallery-heavy wedding invitation, the
standard one and Beauty for Ashes, and check: all text readable (nothing white on white, no overlay left uncovered), the RSVP form submits,
**the chosen answer shows a tick**, calendar links work, and nothing is blank.

- [ ] **An old Android phone** (Android 7-9 with an out-of-date system WebView, or Chrome ~70): the page is plainer but complete
- [ ] **WhatsApp**: send yourself the link, open it in WhatsApp's browser (Android and iOS); the preview card in the chat shows the picture,
      title and description; a background video shows **Play video**, not autoplay; the music waits for its button
- [ ] **Facebook** (Android and iOS): post or message the link; the preview shows the picture; opened in Facebook's browser the hero is not
      cut off by the toolbar, the one-line "open in your browser" hint shows above the calendar links, RSVP submits (cookies work)
- [ ] **A laptop**: Safari, Firefox and Chrome; a video autoplays muted; **Print preview** shows every section (nothing transparent),
      no video / slider arrows, the countdown as a sentence and the gallery as a grid
- [ ] **Facebook Sharing Debugger** on one event URL: no warnings about the image, size 1200×630

**Long text and missing content** (`plans/invitation-page-content-limits.md`). On a phone, with a test event (preview is enough), try a
name of about 250 characters with no spaces, the same as the venue, and a second event with a 100-character name made of real words:

- [ ] No sideways scroll on the invitation, the RSVP page and the thank-you page; the name wraps and the heading is smaller, not clipped
- [ ] Clear the description, then clear the venue, location and pin: the invitation reads "Venue to be announced" and shows no wedding wording
      on a birthday or memorial
- [ ] Let the RSVP deadline pass: no "RSVP" button that scrolls nowhere, and the host's number is shown
- [ ] Add the event's calendar link to Apple Calendar and Outlook with the long name: the whole title arrives

*Approximating an old engine on a laptop (rough):* paste this in the DevTools console of a guest page. It removes the declarations an old engine
would drop, so you can see what the fallbacks leave. It cannot emulate the `@supports not` blocks (a modern browser supports the feature, so
those never apply), so treat colours as indicative only.

```js
(async () => {
  const drop = /color-mix\(|(?<![\w-])(clamp|min|max)\(|^\s*aspect-ratio\s*:|\bd?\d+svh\b|:has\(/;
  for (const link of [...document.querySelectorAll('link[rel=stylesheet]')]) {
    if (!link.href.startsWith(location.origin)) continue;
    const css = await (await fetch(link.href)).text();
    const style = document.createElement('style');
    style.textContent = css.split('\n').filter(line => !drop.test(line)).join('\n');
    link.replaceWith(style);
  }
})();
```

## 4. If something in this checklist was skipped and a page is now 500ing

`Target class [...] does not exist` or `Class "..." not found` in `storage/logs/laravel.log` almost
always means step 1's `composer install --optimize-autoloader` didn't run after the class was added.
Run it now — it's safe to run at any time, not just right after a deploy — then clear + rebuild the
caches (`config:cache`, `route:cache`, `view:cache`) since a stale cache can also point at the old
state. This resolves the class-not-found family of errors without a code change; there is nothing to
patch in the repository for this specific failure mode.

See also: [authentication-deployment.md](authentication-deployment.md) for auth-specific env vars and
the Supervisor worker config, [payment-production-checklist.md](payment-production-checklist.md) for
the Lenco/billing-specific checklist.
