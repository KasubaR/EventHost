# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# Start full dev environment (server + queue + logs + vite, all concurrently)
composer dev

# First-time setup
composer setup

# Post-deploy: migrate, cache config/routes/views, restart queue workers
# (run `composer install --no-dev --optimize-autoloader` first — see docs/deployment.md)
composer deploy

# Run tests
composer test

# Run a single test file
php artisan test --filter=TestClassName

# Build assets
npm run build

# Lint PHP with Pint
./vendor/bin/pint

# Run migrations
php artisan migrate

# Create storage symlink (needed for profile photo uploads)
php artisan storage:link
```

## Architecture Overview

Laravel 12 application. Auth via Laravel Breeze (Blade stack). No Alpine.js — all interactivity is vanilla JS. Tailwind is installed but barely used; all real styling is in custom CSS files under `public/css/`.

### CSS Design System

**Global and shared components** (always load in this order where applicable):

| File | Purpose |
|---|---|
| `public/css/global.css` | Design tokens (`:root`), resets, `body`, site `nav {}`, footer, shared buttons (including `.btn-hero-*` for reuse) |
| `public/css/account-components.css` | Breeze/guest control styles (`eh-*`), site header nav extras, legacy account shell |
| `public/css/dashboard-shell.css` | Authenticated chrome only: `.dash-*` sidebar and layout |
| `public/css/forms-app.css` | Profile and shared app form/card patterns (`.profile-*`, modals — also used by event forms) |

**Page-specific** (push from views or layouts):

| File | Loaded by |
|---|---|
| `public/css/home.css` | `home.blade.php` via `@push('head')` — marketing landing sections only |
| `public/css/auth.css` | `login` / `register` via `@push('head')` — auth hero + `.auth-*` fields |
| `public/css/dashboard-home.css` | `dashboard.blade.php` via `@push('styles')` — overview stats / empty state |
| `public/css/events-admin.css` | Event CRUD views (`events/*` except public) via `@push('styles')` |
| `public/css/events-public.css` | `events/public.blade.php` — public invitation page |
| `public/css/events-invitation-section-nav.css` | Section nav above Pro invitation layouts (`nav.evt-inv-nav`) — pushed by `events/invitations/renderer.blade.php` when `InvitationSectionNav::items()` returns links. Not sticky: resets `global.css`'s `nav {}`. Links come from the sections that actually rendered markup, and each section is wrapped in `<div id="inv-{type}" class="evt-inv-anchor">`, so a sibling selector between bare sections (e.g. Modern Minimal's `.mm-section + .mm-section`) needs a wrapper-form twin in this file |
| `public/css/event-cards.css` | Public event cards (`.event-card-*`) + `/discover` page — pushed by `home.blade.php` and `events/discover.blade.php` |
| `public/css/reviews.css` | Host review portal (`.rev-*`) — star picker and status pills; pair with `events-admin.css` |
| `public/css/settings.css` | Account settings tab strip (`.set-*`) — pushed by `components/settings-layout.blade.php`; the cards inside each tab reuse `forms-app.css` |
| `public/css/legal.css` | Policy pages (`.legal-*`) — sticky contents rail + prose, pushed by `legal/*.blade.php` |
| `public/css/help.css` | Get help page (`.help-*`) — request form layout, hints, submit row and the open-request card; pushed by `help/show.blade.php`, pair with `events-admin.css` |
| `public/css/datetime-picker.css` | Custom date/time picker (`.dtp-*`) — pair with `js/datetime-picker.js` |
| `public/css/custom-select.css` | Custom dropdown (`.cs-*`) — pair with `js/custom-select.js` |
| `public/css/media-uploader.css` | Upload-on-pick tiles (`.mup-*`) — pair with `js/media-uploader.js`; pushed by `events/edit.blade.php` |
| `public/css/ticket-checkout.css` | Buyer-facing ticket flow (`.tkc-*`) — picker, checkout, order status, `/t/{token}` ticket page |
| `public/css/ticket-event-public.css` | Fixed public landing page for ticketed events (`.tev-*`) — hero/about/location layout only; ticket-row text and card chrome reuse `.tkc-*` from `ticket-checkout.css` (loaded alongside) and the generic `.evt-public-*` shell from `events-public.css`. Pushed by `events/tickets/landing.blade.php` and `events/preview.blade.php` when the event is ticketed |
| `public/css/guest-pass.css` | Guest invitation pass card (`.gpass-*`) — pushed by `rsvp/partials/pass-card.blade.php` itself (`@once` + `@push('head')`), so any page that includes the card gets it. Plan: `plans/invitation-pass-card.md` |
| `public/css/contributions.css` | Contribute page, pay-installment status page (`.ctb-*`) and the invitation-page contribute banner — pairs with `ticket-checkout.css` (loaded alongside), which supplies the shared `.tkc-*` form/checkout chrome. Pushed by `events/contribute.blade.php`, `events/contribution-status.blade.php`, and by `events/public.blade.php` when the event accepts contributions |

Layouts: `layouts/site.blade.php` loads `global.css` + `account-components.css` + Vite; `layouts/app.blade.php` adds `dashboard-shell.css` + `forms-app.css`. Tailwind ships via Vite (`resources/css/app.css`) alongside these files.

### Custom Form Controls

Two reusable, dependency-free controls that progressively enhance native inputs. The native `<input>` / `<select>` stays in the DOM with its `name`, `value` and `required` intact, so controllers, validation and `old()` repopulation are unchanged — only the UI is replaced.

| Control | Opt in | Notable options |
|---|---|---|
| `js/datetime-picker.js` | `data-dtp` on `type="date"`, `time` or `datetime-local` | `data-minute-step` (default 5), `data-hour-format="24"`, `data-week-start="1"`, `data-placeholder`; native `min`/`max` are honoured and also accept `today` / `now` |
| `js/custom-select.js` | `data-cs` on any `<select>` (incl. `multiple`) | `data-cs-search="auto\|always\|never"`, `data-cs-placeholder`, `data-cs-icon`, `data-cs-size="sm"`, `data-cs-swatch-only` (tile grid of swatches, labels visually hidden); per-option `data-icon` / `data-hint` / `data-swatch` (comma-separated colours, single select only — the invitation palette picker); `<optgroup>` supported |

Both auto-initialise on `DOMContentLoaded`; call `DateTimePicker.refresh(root)` / `CustomSelect.refresh(root)` after injecting markup dynamically.

### Upload on pick (staged media)

Images on the event edit page upload the moment they are chosen, not when the form is saved. Plan and
rationale: `plans/upload-progress.md`.

- Opt in with `data-upload-slot`, `data-upload-url` and `data-upload-max-bytes` on any `<input type="file">`.
  `js/media-uploader.js` renders a tile per file with a real progress bar and appends
  `<input type="hidden" name="staged_media[]">` to that input's own form
- **`XMLHttpRequest`, not `fetch`** — `fetch` reports no upload progress, and the percentage is the point
- The native input keeps its `name` and stays in the DOM, so **without JS the form still posts binaries** and
  every controller still accepts them. Both branches are live; do not delete the file branches
- The uploader clears `input.value` in a `setTimeout` after reading the files. Clearing is mandatory (else the
  binary posts alongside the staged id and stores twice); the timeout is so other `change` listeners on the
  same input — the cover preview in `events-form.js` — still see the files whatever order they registered in
- `POST /events/{event}/media` (`EventInvitationMediaController`, `throttle:invitation-media`) validates one
  file against `InvitationMediaRules` and writes it to its **final** directory, so consuming a row later costs
  one string assignment and no filesystem work inside the save transaction
- Slots: `gallery`, `hero_portrait`, `couple`, `speaker:0`…`speaker:3`, `cover`, `audio`. Single-value slots
  (hero, cover, audio, each speaker) replace on re-upload; `gallery` and `couple` append
- Every staged lookup is scoped to **event *and* user** (`StagedMedia::scopeOwnedBy`). event_id alone would let
  a co-host consume rows staged in someone else's open form
- **Staged paths never join `$uploadedPaths`** in `EventInvitationDesignController`. That list is rolled back on
  failure, and these files must survive a rejected save so the redisplayed form still shows its tiles
- Staging caps count *staged rows only*, not saved images — otherwise "remove three, add three" is rejected,
  because staging cannot see removals the open form has not submitted. The authoritative
  saved + staged − removed check lives in `UpdateInvitationDesignRequest::withValidator()`
- `invitation:prune-orphaned-files` treats a live `staged_media` row as a **reference**, and separately expires
  rows older than `invitations.staged_media_ttl_minutes` (24 h). Without the first half it would delete photos
  the user can still see on screen, one hour after they picked them
- WebP conversion is unchanged: still `ProcessInvitationDesignImageJob`, still dispatched after the save
  commits. A staged tile reads `Ready`, never `Optimising…`. The **event cover** is the exception — converted
  synchronously in `InvitationMediaStager::storeCover()`, as it always was
- The **create** page stages nothing: there is no event id to scope an upload to, so its cover posts with the
  form. Only `/events/{event}/edit` stages

**Two gotchas worth keeping:**

1. Panels are portalled to `<body>` with `position: fixed` because `.evt-section` sets `overflow: hidden` — an absolutely-positioned panel inside the section would be clipped.
2. The native control is hidden with `opacity: 0` over the trigger's own box, never `display: none`. A `display: none` control makes Chrome throw "An invalid form control is not focusable" and silently block submission; keeping it sized means validation bubbles still point at the visible trigger.

**Critical gotcha:** `global.css` has a bare `nav {}` rule that targets every `<nav>` element, including the sidebar's `<nav class="dash-nav">`. Overrides live in `dashboard-shell.css` (`.dash-nav`). Keep that pattern when adding new `<nav>` elements inside the app shell.

### Blade Layouts

- `layouts/site.blade.php` — public-facing pages; loads `global.css` + `account-components.css` + Vite; `@stack('head')` for page CSS; includes `<x-site-header />` and `<x-site-footer />`
- `layouts/guest.blade.php` — Breeze card flows (verify email, password reset); same CSS base as site minus marketing pushes
- `layouts/app.blade.php` — dashboard shell; sticky sidebar + main content area; supports named slots `$title`, `$pageHeader`, `$slot`

### Routing

- `/` → `home` view (public)
- `/privacy`, `/terms`, `/cookies` → `Route::view` to `legal/*` (public) — see Legal Pages below
- `/dashboard` → `DashboardController@index` (auth + verified) — the **Private** portal's overview. See
  "Event Audience (private vs public portal split)" below for `/public-dashboard`, `/public-events` and
  every ticketing/staff sub-route that lives under `/public-events/...`
- `/settings/*` → `App\Http\Controllers\Settings\*` (auth + verified) — see Account Settings below
- `/profile` → **301 redirect** to `/settings/profile`, kept for old bookmarks
- Auth routes in `routes/auth.php` — standard Breeze scaffold + `PUT /password` for password updates

### User Model

`App\Models\User` implements `MustVerifyEmail`. Extra columns beyond standard Laravel:

- `phone`, `company_name`, `profile_photo` (path relative to `storage/app/public/`)
- `notification_preferences` — JSON cast, keyed array of booleans, defaults set in `booted()` via `DEFAULT_NOTIFICATION_PREFERENCES` constant
- `status` — enum: `pending` | `active` | `suspended`, defaults to `pending`
- `last_login_at`, `last_login_ip`
- `profile_photo_url` accessor returns `storage/` URL or `public/images/default-avatar.png` fallback

### Account Settings

Everything that used to live on the single `/profile` page is now four tabs under `/settings`. Plan and
remaining work: `plans/settings.md`.

| Route | Controller | Renders |
|---|---|---|
| `GET /settings` | — | `Route::redirect` to `/settings/profile` |
| `GET|PATCH /settings/profile` | `Settings\ProfileController` | photo, name, email, phone, company |
| `GET /settings/security` | `Settings\SecurityController` | password form only |
| `GET|PATCH /settings/notifications` | `Settings\NotificationController` | the five preference toggles |
| `GET|DELETE /settings/account` | `Settings\AccountController` | danger zone + delete modal |

- **`PUT /password` deliberately stays in `routes/auth.php`** with the rest of the Breeze scaffold.
  `Auth\PasswordController` returns `back()`, which lands on `/settings/security`, so it needs no changes.
  `Settings\SecurityController` only renders the form
- `<x-settings-layout>` (`components/settings-layout.blade.php`) wraps `<x-app-layout>` and draws the
  read-only identity card plus the tab strip. Each tab view supplies only its own card
- The tab strip is a bare `<nav>`, so `global.css`'s `nav {}` rule applies — `settings.css` overrides it via
  `nav.set-tabs`, the same pattern `.dash-nav` uses
- Session flash keys: `profile-updated`, `password-updated`, `preferences-updated`, `verification-link-sent`
- Each partial's password eye-toggle script is **scoped to its own form/modal**. A page-wide
  `.profile-eye` selector double-binds buttons another partial already wired up, and two toggles per click
  cancel out — that was live on the old combined page

#### Profile update flow

1. `UpdateProfileRequest` validates the profile fields only; uses `email:rfc`
2. `ProfileService::update()` runs inside a DB transaction; converts photo to 400×400 WebP via `intervention/image` (uses Imagick if available, falls back to GD); deletes old photo after commit; sends `EmailChangedNotification` to old address when email changes

#### Notification preferences flow

Preferences have their own endpoint, request and service method — **they never travel through the profile
update path**. Keep it that way: when they shared `PATCH /profile`, the form had to carry hidden `name` and
`email` inputs to satisfy that request's `required` rules, which meant a toggle save could rewrite the
account email, null `email_verified_at` and fire `EmailChangedNotification`.

1. `UpdateNotificationPreferencesRequest` validates `notification_preferences.*` as booleans and nothing else, so a `name` or `email` in the payload is ignored rather than saved
2. Its `preferences()` helper narrows the array to known keys — an `array` rule validates the whole attribute, so unknown keys survive validation and are dropped here
3. `ProfileService::updateNotificationPreferences()` merges the submitted toggles over the stored set. Only keys actually present are overwritten, so a preference added to `DEFAULT_NOTIFICATION_PREFERENCES` later keeps its default for existing users instead of silently becoming `false`
4. The toggle markup uses a hidden input (value `0`) immediately before the checkbox (value `1`) — the last submitted value wins, which is the only way an unticked box submits anything

### Registration Flow

`RegisteredUserController::store()`:
1. Creates user (notification prefs set automatically in `User::booted()`)
2. `event(new Registered($user))` — triggers the signed verification email
3. `$user->notify(new WelcomeNotification)` — separate welcome-only email (no verify link)
4. Redirects to `verification.notice`

### Sanctum

API tokens expire after 10080 minutes (7 days). `sanctum:prune-expired --hours=24` runs daily via the scheduler (`routes/console.php`).

### PHP Extensions Required

This app requires the **GD** extension (or Imagick) for image processing (profile photos, event cover images).

**Local (XAMPP):**
1. Open `C:\xampp\php\php.ini`
2. Find `;extension=gd` and remove the leading `;`
3. Restart Apache in the XAMPP Control Panel
4. Verify: `php -r "echo extension_loaded('gd') ? 'ok' : 'missing';"`

**cPanel (production):**
1. Log in to cPanel → find **"Select PHP Version"** (or "PHP Selector")
2. Make sure the PHP version matches the project requirement (8.2+)
3. Click **"Extensions"** — find `gd` in the list and tick the checkbox to enable it
4. Click **Save** / **Apply**
5. If `imagick` is available in the list, enabling it is preferred over GD for better image quality

### Event Credits (Payments)

Users have an `event_credits` column. Publishing an event costs 1 credit (`User::canCreateEvent()` checks `event_credits > 0`; `EventController` spends inside the publish transaction). Drafts are free. Admins assign credits manually via the user show page in the admin panel.

When payments are implemented, call `$user->increment('event_credits')` in the payment webhook and it will plug straight in.

### Remove Branding (paid add-on)

K250, any plan, **per event** — removes the `<x-event-host-bar>` component (logo, tagline, "Get
started free" CTA) from that event's public pages. Plan: `plans/remove-branding.md`.

- One column, `events.branding_removed` (boolean). Set only by
  `PaymentCompletionService::fulfillRemoveBranding()`, never by a host directly
- Reuses the existing self-checkout pipeline instead of forking a new one — `plan_key =
  'remove_branding'` is a **third** special case in `PaymentController::initiate()` and
  `PaymentCompletionService::complete()`/`reverse()`, alongside the normal credit-granting plans
  and the `enterprise`/`CustomQuote` flow. It grants **no credits and no tier** — `BillingPlan::getAddon()`
  reads its price from `config('billing.addons')`, a sibling of `plans`, not an entry inside it
- The event to remove branding from travels in `Payment.metadata['event_id']`, the same way
  Enterprise carries `quote_id` — `InitiatePaymentRequest` validates the event is owned by the
  buyer and not already `branding_removed` before `initiate()` re-validates it again under a row
  lock inside the transaction
- Its own small checkout page (`RemoveBrandingController`, `GET /events/{event}/remove-branding`)
  rather than a card on the tier-comparison `billing/checkout.blade.php` — this is one line item,
  not a set of plans to compare. Still posts to the same generic `payment.initiate` /
  `payment.verify*` endpoints
- A reversed payment (chargeback / failed settlement) flips `branding_removed` back to `false` and
  skips `EventCreditService::reversePurchase()` entirely — that call would just write a pointless
  0-credit refund ledger row for a purchase that never touched credits
- `<x-event-host-bar :event="$event" />` (`resources/views/components/event-host-bar.blade.php`) is
  the one copy of that bar — it used to be pasted verbatim into four views (`events/public.blade.php`,
  `events/tickets/landing.blade.php`, `rsvp/token-show.blade.php`, `events/invitation-status.blade.php`).
  Add any new public-facing event page through the component, not a fresh copy-paste
- Applies to **both** invitation and ticketed events (the bar shows on the ticketed landing page
  too) — unlike Contributions below, this isn't scoped to `isInvitation()`

### Event Contributions

**Currently switched off platform-wide** (`config('events.contributions.enabled')`, env
`CONTRIBUTIONS_ENABLED`, default `false`; `phpunit.xml` sets it `true` so the suite still exercises the
mechanism). `Event::acceptsContributions()` reads it, so the contribute page 404s and the banner, host
summary and API flags disappear. Stored settings are kept and in-flight pledges can still be paid.
See `plans/public-private-portals.md` §6.4.

Some invitation-type events (weddings, funerals, baby showers) ask guests to contribute a fixed
amount. This is **admin-only, per event** — the host never turns it on or sets the amount. Plan and
phased rollout: `plans/contributions.md` (Phases 1–3, all described below, are built).

- `events.contribution_enabled` / `events.contribution_amount` are set from `admin/events/{event}`
  (`Admin\EventContributionController`, permission `events.contribution_manage`) — `support` does
  not have this permission, same posture as `ticketing.payouts.manage`. `Event::acceptsContributions()`
  is the single gate every caller should read (invitation kind, enabled, amount set and positive)
- Scoped to **invitation-kind events only** (`Event::isInvitation()`) — ticketed events already have
  their own paid-entry commerce path and are excluded even for `event_type` values (`corporate`)
  shared between both kinds
- Guests pledge and pay from `/e/{slug}/contribute` — no login, no cart/hold step (contributions
  aren't inventory-limited, unlike tickets). One `EventContribution` row per contributor "pledge"
  holds a `target_amount` **snapshotted** from the event at creation, so an admin changing the
  amount later never rewrites a pledge already in progress
- The amount is **fixed, not guest-adjustable** — but payable in **installments**: each is a
  `ContributionPayment` row (structurally a copy of `TicketPayment`, so it drops straight into the
  existing `LencoService` / webhook plumbing). `EventContribution.amount_paid` only ever moves
  inside `ContributionPaymentStatusService::creditContribution()`, under a row lock, called exactly
  once per payment (guarded by `ContributionPayment::isTerminal()` on every re-entry) — never assign
  it directly
- A contributor returning to pay another installment is matched to their existing pledge by
  **normalized phone** (`EventContribution::normalizePhone()`) within the same event, not by an
  account — there is no login. The bookmarkable `/contributions/{reference}` status page is the
  other way back in
- `PaymentController::webhook()` now tries three tables against the one shared Lenco webhook URL —
  `Payment`, then `TicketPayment`, then `ContributionPayment` — see the comment above that dispatch
  for why it's one endpoint instead of a registered URL per domain
- No platform commission — the host gets the gross of every completed installment
- **Payouts (Phase 2, built):** Lenco settlement lands in the platform's merchant account, same as
  ticket sales, so an admin manually records disbursements at `admin/contributions/revenue`
  (`Admin\ContributionRevenueController`, permission `contributions.payouts.manage` for recording,
  `events.contribution_manage` for viewing — twin of `ticketing.payouts.manage`/`ticketing.view`).
  Unlike ticketing there is **no separate ledger table**: with no commission split,
  `contribution_payments` (status `completed`) already *is* the append-only "money in" record, so
  `ContributionRevenueAnalyticsService` derives every total live from it plus `contribution_payouts`
  ("money out") rather than maintaining a `balance_after`-style running total.
  `ContributionPayoutService::recordPayout()` re-checks the balance under a row lock on the event
  and throws `ContributionPayoutExceedsBalanceException` if the amount is <= 0 or exceeds it. The
  per-event show page also has a CSV export of completed payments
  (`admin.contributions.revenue.export`), same streaming pattern as the host-facing
  `events.tickets.export`
- **Notifications (Phase 3, built):** every completed installment fires two on-demand
  notifications from `ContributionPaymentStatusService::creditContribution()`, deferred via
  `DB::afterCommit()` (same reasoning as ticket fulfillment) and sent through
  `CommunicationService` (`sendContributionReceipt()` / `notifyHostNewContribution()`), wrapped in
  a try/catch that reports but never rethrows — a notification failure must never surface as a
  failed payment. `ContributionReceiptNotification` goes to the contributor (skipped silently if
  they gave no email — that field is optional) and repeats per installment, not just the final one.
  `NewContributionReceivedNotification` goes to the host, gated by a new
  `email_contribution_updates` key in `User::DEFAULT_NOTIFICATION_PREFERENCES` (defaults `true`,
  toggle lives on `/settings/notifications` alongside the other four)

### Subscription Tiers

`App\Enums\SubscriptionTier` ranks accounts `none < base < pro < pro_plus < enterprise` and gates
features via `User::subscriptionTierRank()`. Four gates exist, at three different floors — mind
which one a feature actually needs, the names alone don't say:
- `canMakeEventsPublic()` — **Base and above** (the lowest gate — every tier except `none`
  qualifies): whether a host may choose **free registration** (public + invitation) instead of Private on
  the create wizard's first step. This isn't the old "Public invitation" checkbox any more — that's gone
  since Phase 4 of `plans/public-private-portals.md`, and audience is immutable after creation, so
  `UpdateEventRequest` has no `audience` field at all. `StoreEventRequest::guardAudienceChoice()` is the
  live gate: it only fires for `audience = public, product_kind = invitation` (free-registration); choosing
  Private stays free at every tier, including `none`. Ticketed events are exempt entirely — a ticketed
  choice always forces `audience = public` regardless of tier, same commission-not-subscription reasoning
  as the gate below
- `canUsePremiumEventTools()` — **Pro and above** (Pro, Pro+, Enterprise all qualify): check-in, table
  assignment and the photo wall, for invitation events. `Event::ownerHasPremiumEventTools()` is the
  live, event-aware wrapper — ticketed events unlock via `ticketSalesAreApproved()` instead, regardless
  of tier, since those already pay commission
- `canChooseInvitationPalette()` and `canSendAutomatedReminders()` — **Pro+ specifically** (`Event::ownerCanSendAutomatedReminders()`
  is the live wrapper for the second one)

`base`, `pro` and `pro_plus` each have a matching entry in `config('billing.plans')`
with a fixed ZMW price, self-checkout through the Lenco flow (`billing/checkout.blade.php` renders one
card per config key), and `PaymentCompletionService` raises the buyer's `subscription_tier` on success.

**Event staff accounts are not part of this ladder at all.** `App\Models\EventStaff` /
`EventStaffController` are **ticketed-events only** — every action 404s for an invitation event,
regardless of tier, per that controller's own docblock. They unlock on ticket-sales approval, the
same commission-based gate as the premium tools above, not on Base/Pro/Pro+. The homepage
deliberately does **not** promise "team members" on any invitation-plan card for this reason — it
would be a promise no invitation-plan subscriber could ever redeem.

`enterprise` is deliberately **not** in `config('billing.plans')` — it's a Contact Sales tier for custom
templates, multi-page invitation sites and fully bespoke event builds, which are hand-built off-platform,
not something the checkout flow can sell automatically. The homepage pricing card links straight to
`/contact` instead of `billing.show`. Because there's no payment event to hook into, an admin with
`users.manage_status` assigns the tier by hand from the user show page (`PATCH /admin/users/{user}/tier`)
once a custom deal is agreed — the same page also grants event credits, since an Enterprise account still
needs credits to publish.

### Event Audience (private vs public portal split)

`events.audience` (`App\Enums\EventAudience`: `private` | `public`) says who an event is for; it is
orthogonal to `product_kind`. Plan and phases: `plans/public-private-portals.md` — Phases 1–4 and 4c are
built; Phases 5–9 are still just planned.

**Since Phase 4, audience is immutable in practice.** `/events/create` is a two-level chooser — Private vs
Public, then (Public only) Ticketed vs Free registration — and neither `StoreEventRequest` nor
`UpdateEventRequest` has an `is_public` field any more; there is no longer any form path that can set it
after creation. `StoreEventRequest`'s `audience` field is `required`, but is **shared with the Android API's
`POST /api/v1/host/events`** (`/api/v1` is a JSON API built ahead of an Android client per
`plans/android-app.md` — no such app is actually built or shipped yet, so "the Android app" elsewhere in
these docs really means "this API's existing contract," enforced today only by its own feature test suite),
which predates the `audience` field and never sends one — `prepareForValidation()` derives a missing
`audience` from `is_public` (via `EventAudience::derive()`, the same helper the model's saving hook uses) so
that endpoint's behavior is byte-for-byte unchanged. Submitting `audience=private` with
`product_kind=ticketed` is **not** a validation error — `TicketedEventCreator` silently overwrites it to
`public` regardless (matching how it already overwrites every other invitation-only field), so that
combination never reaches `Event::save()` at all.

**The two portals, as they exist today:**

| | Private | Public |
|---|---|---|
| Dashboard | `GET /dashboard` (`DashboardController::index`) — guest/RSVP-shaped, `DashboardAnalyticsService::forUser($user, EventAudience::Private)` | `GET /public-dashboard` (`DashboardController::publicOverview`) — commerce-shaped, `PublicDashboardAnalyticsService` |
| My Events | `GET /events` (`events.index`) | `GET /public-events` (`public-events.index`) |
| Both routes | Same `EventController::index()`, audience comes from a route default (`->defaults('audience', ...)`), not a query string |

- `PublicDashboardAnalyticsService` also returns a `registrations` block for free-registration events (registered,
  expected headcount incl. plus-ones, awaiting approval, checked in, 14-day daily series, top 5 events). Self-signups are
  ordinary `rsvps`; a registration counts when Accepted and not `Pending`/`Rejected` host approval. `public-dashboard.blade.php`
  shows it only when the host has at least one free-registration event
- **What the public overview shows is decided by `App\Support\PublicDashboardProfile`**, from the host's own events:
  `KIND_WIDGETS` maps a product kind to widget groups (`tickets`, `revenue`, `registrations`), so a free-only host no longer
  sees empty ticket/revenue tiles; `TYPE_LABELS` rewords the registration widgets per event type (church and funeral say
  "Attendees"/"Expected attendance"), used only when all the host's free events share one type, otherwise `DEFAULT_LABELS`.
  Add a type's wording or a kind's widgets there, not in the view. The private dashboard is not driven by it yet
- `DashboardAnalyticsService::forUser()`'s `$audience` parameter is optional and trailing — every call
  site except the web `DashboardController` omits it and keeps seeing every owned event, unfiltered. This
  is deliberate: the Android API's own host dashboard endpoint (`Api\V1\DashboardController`) must stay
  additive-only and untouched — see the note above on what "the Android app" means in these docs. (The
  plan's Phase 7, which would have added `audience` to that API's own resources, was dropped as unneeded —
  nothing consumes that API for real yet.)
- `PublicDashboardAnalyticsService` is a **separate** service, not `DashboardAnalyticsService` with a
  different filter — ticketed events have no `Guest`/`Rsvp` rows at all, so the private dashboard's shape
  (RSVP chart, guest groups, daily RSVPs) doesn't fit. It reads ticket/check-in counts plus
  `TicketRevenueLedgerService::summaryForEventIds()` (the multi-event sibling of `summaryFor()`, which is
  now a one-id call to it)
- The sidebar (`layouts/app.blade.php`) shows a `.dash-portal-switch` toggle and swaps its nav section based
  on `request()->routeIs(...)`. Neither tab is forced active on a page shared by both portals (Billing,
  Settings, Reviews) — that's intentional, not a bug
- Every redirect/back-link that used to assume a single "My Events" list now checks the event's own
  `audience` (or, before the event exists, the submitted/queried `product_kind`) to send the host to the
  right one: `EventController::destroy()`, `EventTicketingController::submit()`, the draft-limit guards in
  `create()`/`store()`, and the "All events" link on the edit/show pages
- **Base event CRUD is not split.** `/events/{event}/edit`, `show`, `update`, guests, tables, media, etc.
  still serve both audiences on the same URL — only the top-level list and dashboard, plus the ticketed
  sub-pages below, are audience-scoped
- **Ticketing/staff/ticket-check-in sub-pages live under `/public-events/...` (Phase 3b, shipped
  2026-09-22).** `events.ticket-types.*`, `events.ticketing.*`, `events.tickets.*` (including
  `events.tickets.checkin.*`) and `events.staff.*` all became `public-events.*`, confirmed ticketed-only
  first by checking every controller's own `abort_unless($event->isTicketed(), 404)`. An `EnsureEventAudience`
  middleware (alias `audience:`) gates the whole group as pure defence in depth — a ticketed event is always
  public audience already, so it never actually fires. Old `/events/{event}/...` **GET** URLs 301-redirect
  to the new path (the one route linked from an email, `TicketingRejectedNotification`, plus anything
  bookmarked); state-changing verbs got no redirect — a stale form action only exists on a page left open
  across the exact deploy moment, 404s, and self-heals on refresh. **`events.checkin.links.*` did NOT
  move** — `EventStaffLinkController`'s scanner-link actions gate on `ownerHasPremiumEventTools()`, which is
  also true for a Pro+ **invitation** event, and both `events/checkin/scan.blade.php` (private) and
  `events/tickets/checkin/scan.blade.php` (ticketed) post to the same two routes, so it stays on
  `/events/{event}/checkin/links` for both audiences. Blade **view** paths (`events.tickets.index`,
  `events.tickets.partials.*`, `events.staff.index`, …) are unaffected — only route names moved, the view
  files are still at their old location

- On a **new** row `Event::booted()` derives `audience` from `product_kind` + `is_public` (the API still sends
 only `is_public`); an explicitly assigned `audience` wins and drives `is_public` instead. **Once a row exists its
 audience is locked**: any save that would move it — via `audience`, `is_public`, or flipping a private event to
 ticketed — throws `LogicException`, and every other save re-pins `is_public` to the stored audience. The update
 forms and API simply ignore a submitted `audience`/`is_public`. Never write either without a model save — a
 query-builder `update()` skips the hook and lets them disagree
- **A ticketed event is always public, on every save.** The hook checks this first and forces
  `is_public = true` / `audience = public` whatever was touched — a new row, `product_kind` flipped on an
  existing one, or `is_public` cleared. Assigning `audience = private` to a ticketed event throws
  `LogicException`. Without this a ticketed row could end up `audience = public, is_public = false` and
  `scopePubliclyListed()` (which still reads `is_public`) would drop it from discover
- The "private ticketed event 403s" tests build that row with a raw `DB::table('events')->update()`, since
  the model can no longer produce it; the runtime `! is_public` gate stays as defence in depth
- `scopePubliclyListed()` requires **both** `audience = public` and `is_public` — fails closed if a raw write
  ever makes them disagree. Every other gate still reads `is_public` / `product_kind`
- `EventFactory` defaults to a **private** invitation event; use `->publicAudience()` (or `->ticketed()`)
  when a test needs the public page, discover or open RSVP

**Private event types always include the seven static types.** Each template carries **one category** for
now (Wedding: Classic, Wedding Standard, Ivory & Gold, Noir & Gold, Modern Minimal, Midnight Gold, Dusty Blue,
Pro Magazine; Graduation:
Blush Celebration Card, Botanical; Church: Beauty for Ashes), so most types have no template of their own.
`Event::privateEventTypes()` therefore returns every `INVITATION_EVENT_TYPES` entry, plus any further category
that has an active template, read through the explicit `Event::CATEGORY_SLUG_TO_TYPE` map (category slugs use
hyphens, event types underscores; a slug missing from the map is skipped, never guessed at). The wizard's
template picker lists every active template regardless of type, so a type with no template is not a dead end.
Adding a template category (e.g. `anniversary`, `kitchen_party` — see `plans/public-private-portals.md` §6)
needs a `CATEGORY_SLUG_TO_TYPE` entry before it becomes selectable. The `/templates` category dropdown lists only
categories with an active template on the current plan tab. `Event::eventTypesFor()` still takes only `?EventProductKind`, not audience — nothing
has an audience to pass until Phase 4 wires it into the create/update forms.

Public event types (`Event::PUBLIC_EVENT_TYPES`) are not template-constrained — ticketed events render one
fixed landing page — and stay a plain constant, currently identical to `TICKETED_EVENT_TYPES` (one array
literal; the two names are kept in step on purpose, not duplicated).

**Free-registration public events are admin-approved and admin-priced, not credit-based** (Phase 4c,
Step 1 shipped, Steps 2–4 pending — `plans/public-private-portals.md`). `Event::isFreeRegistration()` is
`isPublicAudience() && isInvitation()` — a public event that isn't ticketed. Like a ticketed event it never
spends an event credit; unlike one, it's priced with a one-off admin-set quote instead of commission.

- `public_registration_status` (`App\Enums\PublicRegistrationStatus`: `not_applicable | draft |
  pending_review | approved | rejected`) mirrors `TicketingStatus` deliberately — same shape, kept as a
  separate enum because `TicketingActivationService`'s own checks (ticket type, hero image) are
  ticket-specific and don't apply here. Defaults to `draft` for a new free-registration event via
  `Event::booted()`'s `saving` hook (same place `audience` is finalized, since `isFreeRegistration()` needs
  it), `not_applicable` for every other event
- `PublicRegistrationService` (submit/approve/reject) is the ticketing-approval pipeline's twin. The one real
  divergence: `approve()` does **not** publish the event (ticketed approval does) — the admin is setting a
  price (`public_registration_quote_amount`) the host still has to pay, so `is_published` stays false until
  that payment completes. `approve()` allows re-quoting an already-`Approved`-but-unpaid event; it refuses
  outright once `public_registration_quote_paid_at` is set — a paid event's price is settled, not editable
  by re-approving over it
- `EventController::publish()` excludes `isFreeRegistration()` the same way it already excludes
  `isTicketed()` — no credit path for either public product kind. `edit()`'s `$publishCostsCredit` and every
  publish-related button/message on `events/create.blade.php` and `events/edit.blade.php` follow the same
  three-way branch (ticketed / free-registration / everything else)
- **Payment (Step 2, shipped 2026-09-22):** `plan_key = 'public_registration_quote'` is a fourth special
  case in `PaymentController::initiate()` / `PaymentCompletionService::complete()`/`reverse()`, mirroring
  `remove_branding`'s shape exactly — grants no credits, no tier. The price is the event's own
  `public_registration_quote_amount`, never client-supplied; both `InitiatePaymentRequest` and `initiate()`
  itself (under a row lock) re-check `Event::awaitingPublicRegistrationPayment()` before charging anything.
  On completion: `is_published = true`, `public_registration_quote_paid_at = now()`. On reversal: both are
  undone, same "money went back, undo the flag" posture `reverse()` already applies to `remove_branding`.
  `PublicRegistrationPaymentController` (`GET /events/{event}/public-registration/pay`) is the dedicated
  host checkout page, a twin of `RemoveBrandingController`; `events/edit.blade.php`'s free-registration
  publish panel links to it once the event is approved. `BillingPlan::labelForPlanKey()` and
  `PaymentReceiptNotification` both got a `public_registration_quote` branch too — without them, admin/
  receipt copy would either show the raw plan_key or the misleading "you now have N event credit(s)" line
- **Admin approval + submit-for-review (Step 3, shipped 2026-09-22):** `EventPublicRegistrationController::submit()`
  (`POST /events/{event}/public-registration/submit`) is the host-facing action, mirroring
  `EventTicketingController::submit()` exactly — moves Draft/Rejected → PendingReview.
  `events/edit.blade.php`'s free-registration panel shows a real "Submit for review" button for
  Draft/Rejected (with the rejection note when there is one), a pending-review message, and (unchanged from
  Step 2) the pay CTA once Approved. On the admin side, `Admin\PublicRegistrationController`
  (`approve`/`reject`) is gated by a new `events.public_registration_manage` permission — `support` does not
  get it, same posture as `events.contribution_manage`/`ticketing.approve` — and rendered as an inline card
  on `admin/events/show.blade.php`, a twin of the Contribution admin card (not a dedicated queue page like
  Ticketing's, since there's no list to browse — the admin reviews one event's own status at a time).
  `approve()` accepts Draft/PendingReview/Approved(unpaid)/Rejected; `reject()` only PendingReview.
  `PublicRegistrationApprovedNotification` (quote + pay link) / `PublicRegistrationRejectedNotification`
  (note + edit-page link) fire from the service, outside its DB transaction, mirroring the ticketing
  notification pair and its "notify only once committed" split
- **Billing is hidden from the Public portal's sidebar nav (Step 4, shipped 2026-09-22).** Now that Steps
  2–3 give both public products their own priced flow, neither ticketed nor free-registration events need
  the general plan-comparison page — `layouts/app.blade.php`'s `$inPublicPortal` branch no longer renders
  it. The route and every direct link into it (the Enterprise custom-quote banner on
  `public-dashboard.blade.php`, the remove-branding and public-registration checkout pages) are untouched.
  The Private portal keeps the link — Base/Pro/Pro+ subscriptions are still sold there

### Guest Invitation Pass (private events)

An accepted guest's entry pass is a themed invitation card, not a bare QR. Plan and phases:
`plans/invitation-pass-card.md` — all five phases are built (card + page, PDF, PNG image, email/WhatsApp
delivery, API fields).

- `App\Support\GuestPassCard` is the single source of what the card says (event name as title, date/time, venue,
  guest, plus one, table, state, theme). Web, and later PDF and PNG, all render from it. `fingerprint()` is the
  future cache key — anything printed on the card must be in it
- Eligibility is unchanged and **not** decided by the card: `Guest::hasEntryPassFor()`, plus the event being
 published. The QR still encodes `Guest::checkInQrUrl()`
- **Personal links (`/rsvp/{token}`) respect publishing.** They ignore `is_public` (it's the host's own invite) but an
 unpublished event reads as "Invitation unavailable" (`PublicInvitationResolver::statusForLoadedEvent()`), RSVP
 submits 403, the pass routes refuse, WhatsApp replies are treated as closed, and `sendWhatsAppInvitation()` returns
 `unpublished` — so a link copied off a draft's guest list can't show or accept anything before the credit is spent
- `rsvp/partials/pass-card.blade.php` is the card; `rsvp/partials/entry-pass.blade.php` (the panel included by
  `token-show`, `closed`, `thank-you` and the invitation `rsvp` section) wraps it. `GET /rsvp/{token}/pass`
  (`RsvpController::pass()`) is the standalone page; an ineligible guest is redirected to `rsvp.token.show`,
  not 404'd
- Theme colours come from the merged invitation theme, are validated as `#rrggbb` (they land in an inline
  `style`) and fall back to the platform colours when the header would be unreadable or the template can't be
  resolved — a pass never 500s over paint
- **PDF:** `GET /rsvp/{token}/pass/download` (`RsvpController::passDownload()`, `throttle:guest-pass-download`)
  renders through `GuestPassPdfService` — a twin of `TicketPdfService`. The PDF omits the cover image (covers
  are WebP, which DomPDF can't embed) and needs literal hex colours and tables, not CSS variables or flexbox
- **Image:** `GET /rsvp/{token}/pass.png` (`passImage()`, `?download=1` for a filename) renders through
  `GuestPassImageService`, pure GD: laid out in px, drawn at 2× and downsampled (GD doesn't antialias shapes),
  QR pasted after the downsample so it stays sharp. GD font sizes are points at 96 dpi, hence the `0.75`.
  Uses `resources/fonts/DejaVuSans{,-Bold}.ttf` and `resources/images/eventhost-icon.png` (the brand icon
  pre-rendered from the SVG — GD can't rasterise SVG; re-render it if the icon changes). If it can't draw
  (no FreeType, font missing) the route serves the plain QR PNG uncached instead of failing
- **Caching (both formats):** `GuestPassFileCache` keys files on `GuestPassCard::fingerprint()` —
  `{root}/{token}/{fingerprint}.{ext}` — because, unlike a ticket, what's printed can change after the first
  render. Writing a new render deletes that guest's older file, so a folder holds one file, not one per edit.
  Bump a root's version in `GuestPassFileCache::PDF` / `::IMAGE` when that renderer's *layout* changes (the
  fingerprint covers content, not layout). `invitation:prune-orphaned-files` sweeps folders whose token no
  longer belongs to a guest. Anything printed on the card must be in the fingerprint
- **Delivery:** `RsvpConfirmationNotification` attaches the PDF and the card PNG (each independently — a
  failed renderer is reported and skipped; only if neither works does it fall back to the bare QR) and its
  button is "View your pass". WhatsApp sends the card PNG via `Guest::entryPassPngUrl()` (repointed at
  `rsvp.token.pass-image`) with `Guest::passPageUrl()` in the caption. `pass.png` is **deliberately
  unthrottled** — Twilio fetches every guest's image from a few IPs, so a per-IP limit would fail deliveries;
  the PDF keeps `throttle:guest-pass-download`. The PDF must stay **one page** (title size scales with length,
  free-text fields are capped) — a second page strands the QR; `GuestPassPdfTest` guards it
- **API:** `RsvpResource::entry_pass` keeps `available` / `check_in_qr_url` unchanged (the Android contract is
  additive-only) and adds `pass_url`, `pdf_url`, `image_url` and a `card` object built from `GuestPassCard`,
  all null when there is no pass. Add new card fields to `GuestPassCard` first and let the API read them from
  there, so web, PDF, image and API can't drift
- **Party size:** an invitation RSVP is only ever 1 or 2, so the card shows "Guest + 1" (`partyLabel()`) when a
  plus-one is coming and omits the row for a solo guest — there is no "Admits" row
- Guest QRs use standard error correction, so state ("Checked in", cancelled, ended) is a pill **above** the
  code and a dimmed QR, never an overlay — unlike ticket QRs, which are `ECC_HIGH` for exactly that reason

### Group RSVP Links (seat pools) and the host contact number

A guest group can carry a **shared RSVP link with a seat pool** (`/g/{token}`), so a host can send one link to,
say, a 100-person committee that gets 10 seats. Plan: `plans/group-rsvp-links.md` — built.

- `guest_groups.seat_limit` + `rsvp_token` (unique, 48 chars) + `rsvp_link_closed_at`. `GuestGroup::hasSeatPool()` /
  `isLinkOpen()` / `seatsTaken()` / `seatsPending()` / `seatsRemaining()` are the only place seats are counted. **Seats are
  derived live, never stored**: accepted RSVPs from the group's guests that are not `Rejected`, so a pending request
  *holds* its seats and approving can never overshoot the pool
- **Enforced in `RsvpSubmissionService::submit()`, not in the controller**, so the personal-link edit path cannot bypass
  it. Every submit locks the event row, which serialises two people racing for the last seat. Applies to every member of a
  pooled group, including guests the host added by hand
- Sign-ups through the link are **always held for host approval**, whatever `events.require_rsvp_approval` says — keyed on
  `guests.group_link_joined_at`, so a guest the host added to the group by hand is *not* forced into review, but a
  link joiner who declines then re-accepts still is. The link form only offers "request seats" (no decline), so a bot
  cannot mint unlimited zero-seat guests
- Each person becomes an ordinary `Guest` (own token, pass, QR) via `GroupRsvpService::request()`. An email or phone already
  on the list is refused, never moved between groups. Plus-ones follow the event's `allow_plus_one` (max 2 seats)
- `GroupRsvpResolver` decides open / full / closed / unavailable for both the page and the submit. A full or closed link shows
  the host number. `Event::hasRsvpApprovalQueue()` is what the guest list reads to show the "Awaiting approval" chip — the event
  toggle *or* any group with a link
- Host controls are `GuestGroupLinkController` (`PUT` seat count / create, `PATCH` close-reopen, `DELETE` turn off) on the
  groups page; the API adds `seat_limit` / `rsvp_link_closed` to the group endpoints and the `seat_limit`, `seats_taken`,
  `seats_pending`, `rsvp_link`, `rsvp_link_closed` fields to `GuestGroupResource` (all null when there is no link)

**Host contact number.** `events.host_contact_phone` is entered on the create/edit form and **required for web invitation
events** (`StoreEventRequest` / `UpdateEventRequest`), optional for the Android API so its contract stays additive.
`Event::hostContactPhone()` falls back to the host's profile phone. `rsvp/partials/host-contact.blade.php` renders "Questions?
Call {host} on {number}" on every guest-facing RSVP page and **renders nothing** when there is no number — add it to any new
RSVP page. Privacy §2 tells hosts the number is shown to guests

### Guest Event Reminders

Accepted guests are reminded 7 days before, 1 day before and on the day of a private (invitation-kind) event, by WhatsApp
(`events:send-whatsapp-reminders`) and by email (`events:send-guest-email-reminders`), both 09:00 Africa/Lusaka. Plan:
`plans/guest-email-reminders.md` — all four phases built; the email ships **off** (`COMM_GUEST_EMAIL_REMINDERS_ENABLED=false`) and
is switched on by that flag alone (go-live order: `docs/deployment.md` §3c).

- `App\Support\EventReminderBuckets` owns the three reminder days, `forEvent()` and the wording (`lead()`), and
  `Event::scopeDueForGuestEventReminder()` owns which events are candidates. Both channels must use them — don't
  re-implement the day arithmetic or the event filter in a command
- **A cancelled event is never reminded.** Cancelling sets `cancelled_at` and leaves `is_published` true, so "published" alone
  is not enough; the scope and `sendWhatsAppEventReminder()` both check it. A *paused* invitation is still reminded
- Send-once is the `notification_logs` idempotency key **including the event date**, plus the `guests` sent-markers column
  for the WhatsApp channel. Changing `event_date` clears that column for the event's guests (`Event::booted()`), which is what
  makes a moved event remind again
- Automated reminders are Pro+ (`Event::ownerCanSendAutomatedReminders()`), invitation events only
- The email is `GuestEventReminderNotification` via `CommunicationService::sendGuestEventReminderEmail()`, which enforces every
  rule itself — flag, plan, cancelled/deleted, Accepted RSVP, an email address, the hourly cap, and a day-of reminder is skipped
  once the event has started. **No attachments** (the confirmation mail carried the pass; the reminder links to it). The
  WhatsApp and email reminders are independent: a guest with both gets both
- **Privacy follows the flags** (`legal/privacy.blade.php` §4/§5): the reminder bullet, the WhatsApp bullet and the Twilio entry appear
  only while `guest_email_reminders.enabled` / `whatsapp.enabled` are on, the same way §7 follows the purge setting. Add a reminder
  channel and you change that copy with it. `events:send-guest-email-reminders --dry-run` shows what turning the flag on would send
- **Guests can stop reminder emails** from a link at the foot of both reminder emails (RSVP-deadline and event). `guests.email_reminders_stopped_at`;
  a relative signed URL by guest id (`Guest::stopEmailRemindersPath()`), GET shows a page and **POST changes it** so mail scanners
  cannot opt people out — that POST is also the one-click `List-Unsubscribe` endpoint, hence CSRF-exempt. Any new reminder email must
  use `OffersReminderOptOut` and check `Guest::hasStoppedEmailReminders()`; the RSVP confirmation, event-update email and WhatsApp
  are deliberately not covered
- `startLog()` reuses a *failed* row for an existing idempotency key (back to pending) instead of inserting — the key is unique,
  so a second insert used to throw. A pending or sent row still blocks

### RSVP Deadline

When RSVP closes, who may still change an answer, and how the deadline reminders follow it. Plan and phases:
`plans/rsvp-deadline-fixes.md` — all five phases are built.

- **The deadline is venue time.** `events.rsvp_deadline` is stored exactly as the host typed it (naive Lusaka wall-clock, like
  `event_date` / `event_time`), while `config('app.timezone')` is UTC. `Event::rsvpDeadlineAt()` is the **only** place the raw
  column is read, in `venueTimezone()`. Never compare the attribute to `now()`: that is what made an 18:00 deadline close at
  20:00. Guest-facing copy uses `rsvpDeadlineLabel()` ("Monday, October 5, 2026 at 6:00 PM CAT"), never the raw value
- **Where RSVP closes:** `rsvpClosesAt()` is the deadline, or with none the **event start** (`rsvpImplicitCloseAt()`; the end of
  the day for an event with no start time), not the end of the event day. `isLocked()` stays date based and drives edit locking,
  redefine charges, pass visibility and the Ended page, so do not use it for RSVP decisions
- **Three gates, pick the right one.** `isRsvpOpen($at = null)` is exact and is what pages show (true for an unpublished draft, so
  a host's preview still has the form). `acceptsRsvps()` adds `is_published`. `acceptsRsvpSubmissions()` is `acceptsRsvps()` with a
  grace (`events.rsvp.deadline_grace_seconds`, env `RSVP_DEADLINE_GRACE_SECONDS`, default 60) so a form that was open at the
  deadline is not lost. **Every submit path asks `acceptsRsvpSubmissions()`**; do not add a per-caller `is_published` check
- **Enforced under the lock.** `RsvpSubmissionService::submit()` re-checks inside its event row lock and throws
  `RsvpClosedException`; `enforceDeadline: false` exists for a host-initiated path (none today). The form requests throw it early
  for a friendlier refusal. It is rendered once, in `bootstrap/app.php`: web goes back to the closed page with an `rsvp_closed`
  flash, JSON gets 403 `{message, code: "rsvp_closed", can_reduce}` (the status is unchanged, so the API stays additive)
- **After the deadline a guest may cancel or reduce, never add.** `submit(..., allowReductions: true)` is passed **only** by callers
  that identify the guest by a secret: the personal RSVP link (web and API) and a verified inbound WhatsApp reply. Until the event
  starts (`acceptsRsvpReductions()` / `canReduceRsvp()`) they may decline, switch accepted to maybe, or take fewer seats. Never
  more seats, never declined to anything, never maybe to accepted. The **open RSVP form never gets this**: an email address is not
  a secret, and it would let anyone cancel someone else's RSVP. A cancelled, paused, deleted or unpublished event refuses every
  change. The closed page shows "Cancel my RSVP" / "Only me" to eligible guests; `RsvpResource` adds `can_reduce`
- **Reminders** (`rsvp:send-reminders`, 09:00 Lusaka, Pro+, non-responders with an email who have not opted out) count days on the
  venue calendar. The **catch-up rule**: a reminder goes out when the deadline is inside a 7 / 3 / 1 day window the guest has not
  used (`RsvpReminderBuckets::eligibleFor()` / `windowFor()`), so a deadline set 5 days out, or a missed scheduler day, is still
  reminded. One email per guest per run marks every window already crossed. There is no separate day-of bucket. Idempotency keys
  carry the deadline's day (`rsvp-reminder:{event}:{guest}:{window}:{Ymd}`, and `bulk-reminder:...:{Ymd}` for the host's manual
  send) from `Event::rsvpDeadlineKeyStamp()`. `rsvp_reminders_sent` is cleared when the deadline's **day** changes, is added or is
  removed (`Event::booted()` updated hook); a time-of-day edit keeps it, so fixing an hour does not email everyone twice
- **Validation.** `guardRsvpDeadline()` keeps the deadline at or before the event start. `guardRsvpDeadlineNotInPast()` rejects a
  deadline already behind us on create, and on update only when the host is changing it (5 minute slack, judged on the venue
  clock); ended and ticketed events are skipped. **Ticketed events ignore the deadline entirely** (`EventController::update()`
  drops it)
- **Host side.** `<x-rsvp-closed-banner>` (guest list and event page, published invitation events only) says why, from
  `Event::rsvpClosedReason()`, with an "Extend the deadline" link when there is a deadline to extend. Invitations and reminders are
  not sent into a closed form: `CommunicationService::sendWhatsAppInvitation()` returns `closed`, and the bulk
  `send_reminder_email` / `prepare_whatsapp_share` actions are refused (web error, API 422 `rsvp_closed`). Marking sent and update
  emails are unaffected. A save that reopens a closed RSVP flashes `rsvp_reopened` (a "remind them from the guest list" prompt,
  deliberately **no automatic broadcast**, web only). The free per-guest wa.me share link is client-side and cannot be blocked
- **API.** `EventResource::rsvp_deadline` is now a real ISO instant with the venue offset (`+02:00`, it used to carry the typed
  value labelled `+00:00`), and `rsvp_closes_at` is new. Both are additive; no Android client is shipped
- **Tests:** `RsvpDeadlineTest`, `RsvpClosedHandlingTest`, `RsvpReminderCadenceTest`, `RsvpDeadlineHostSideTest`. Fake the clock with a
  **UTC** instance, `Carbon::setTestNow(Carbon::parse($venueTime, config('events.timezone'))->utc())`: a Lusaka-zone fake clock
  makes Carbon parse dates in Lusaka too and shifts `event_date`. Build deadline fixtures on the venue calendar
  (`now(config('events.timezone'))`), not UTC, or they fail between 22:00 and 24:00 UTC when the two calendars differ

### Deleted-Event Retention

A deleted event is only soft-deleted and stays in "Recently deleted"; `events:purge-deleted` (daily 03:00
Africa/Lusaka) permanently removes it once it is older than `events.retention.deleted_days`. Plan and phases:
`plans/event-retention.md` — **all phases are built (purge, countdown UI/API/admin, warning email, Privacy copy,
account-guard fix); purging is disabled by default.** It is switched on by setting `EVENT_TRASH_RETENTION_DAYS` —
follow the go-live order in `docs/deployment.md` §3b. The Privacy page's retention wording follows that same
setting, so it can never promise a window the job is not enforcing. Account deletion has its own guard on the same
"has taken money" rule, and keeps the user's payment records (plan §6b, built — see the two account-deletion bullets below).

- Off unless `EVENT_TRASH_RETENTION_DAYS` > 0 (default 0). `EVENT_TRASH_RETENTION_STARTS_AT` (YYYY-MM-DD) is the
  release date: anything already in the trash then is treated as deleted on that date, so it gets a full window
  from the release. **While it is unset and trash older than the window exists the command refuses to run**
  (exit 1, deletes nothing); `--allow-backlog` overrides it, `--dry-run` is never refused
- **An event that has ever taken money is never purged** — `Event::hasRetainedFinancialRecords()`, the single
  definition: ticket orders Paid / Refunded / pending / processing; contributions with a Completed or Refunded
  payment, any `amount_paid`, or a pending payment under 7 days old (older pending contribution rows are
  abandoned checkouts and nothing expires them). It is a different question from `hasBlockingTicketCommerce()`
  ("is money moving right now?", which gates soft-deleting) — don't merge them
- `Event::scheduledPurgeDate()` is pure date math (safe on a list page); `purgeAt()` is the same date or null when
  exempt; `scopePurgeable()` selects candidates. The **exemption is re-checked per event inside
  `EventPurgeService`'s transaction, under a row lock** — an order can settle between selecting and deleting
- The purge detaches reviews first (`reviews.event_id` is now `nullOnDelete`, but the explicit detach stays for hosts whose
  DB came up without the constraint — a purge must never delete a featured testimonial), deletes the event's `notification_logs` (they're `nullOnDelete` and would outlive their guests),
  then `forceDelete()`s and lets the cascades run. `credit_transactions`, ticket revenue and payouts are
  `nullOnDelete` and survive with a null event id. **Files are deleted only after the transaction commits**, and a
  failed file delete is logged (`event.purge_file_failed`), never thrown
- **What people are told** comes from `App\Support\EventRetentionNotice` and nowhere else: the host's Recently
  deleted list (countdown, or "Kept for payment records"), both admin event pages, and the additive `purge_at` /
  `retained_for_records` fields on `EventListResource` and `EventResource`. Days round **up** so it never says 0
  while restorable. **Nothing is shown while purging is off** — the UI only mentions a window when one exists.
  Add wording there, not in a view
- **The purge never deletes an event nobody was told about.** `events:warn-pending-purge` (daily 02:30, before the
  03:00 purge) emails each host **one digest** of their deleted events due within 7 days
  (`HostPurgeWarningNotification`, no preference toggle — it is a service notice about irreversible removal).
  `EventPurgeService` then requires a **sent or pending** `event_purge_warning` log for *this deletion* that is at
  least 24 h old (`Event::PURGE_MIN_NOTICE_HOURS`) — the age rule is what stops an already-overdue event being
  warned at 02:30 and deleted at 03:00. The key, `Event::purgeWarningKey()`, includes `deleted_at`, so restore-then-
  delete-again is warned afresh. A suspended or email-less host is not warned and so their events are *kept*
  (erring towards keeping). Exempt events are never warned about
- **Privacy §7 reads from the same setting** (`legal/privacy.blade.php` branches on `Event::retentionDays()`): on, it
  states the N-day window (the configured number), the warning email, immediate removal on account deletion and the
  payment-records exemption; off, it keeps its original wording. Change one and the other follows — don't hard-code
  "30 days" in the copy. Still unreviewed by a lawyer (see Legal Pages)
- **Account deletion** goes through `App\Services\AccountDeletionService` (both `Settings\AccountController` and
  `Api\V1\Settings\AccountController` call it; the API keeps its 409 + `message`). `events.user_id` cascades over
  trashed rows too, so `blocker()` refuses when any event **including Recently deleted** matches
  `Event::scopeWithRetainedFinancialRecords()` — the query form `hasRetainedFinancialRecords()` also reads, so the
  purge and the account guard share one definition — or when the user has a `pending`/`processing` payment younger
  than `Payment::IN_FLIGHT_HOURS` (24). Always on, whatever `EVENT_TRASH_RETENTION_DAYS` is. Check and delete share one
  transaction under the user's row lock (`PaymentController::initiate()` takes the same lock)
- **The user's own `payments` survive their account** (`payments.user_id` is nullable + `nullOnDelete`, migration
  `2026_09_27_100000`). On deletion, payments that moved money (`completed`, `refunded`, `processing`) are kept with a
  `payer_name`/`payer_email` snapshot; `failed`, `cancelled` and never-finished `pending` ones are deleted with the
  account. `PaymentCompletionService::reverse()` and `complete()` tolerate a null `user_id` — don't reintroduce a
  `User::...->firstOrFail()` on `$payment->user_id`. `credit_transactions` and `custom_quotes` still cascade. Privacy §7,
  Terms §10 and the Settings → Account warning describe this; keep them in step
- **Slugs of purged events are freed** — a new event can later take a URL that was printed on an old invitation.
  Accepted deliberately (no tombstone table); see the plan §10

### Admin acting as a client

An admin can set an event up on a client's behalf by using the existing host screens **as that client**. Plan and
decisions: `plans/admin-create-events.md` — all steps are built. Switched **off** by default
(`ADMIN_ACT_AS_ENABLED=false`, `config/admin.php`).

- **Mechanism:** `ActingAsService` logs the `web` guard in as the client while the admin stays on the `admin` guard in the
  same session (`session('acting_as')`: admin id, client id, start time, return URL, help request id, scoped event id).
  Credits, tier gates, ownership and staged-media scoping all behave as the client's, so nothing is duplicated.
  It signs in and out **through the guard**, never the login/logout controllers: those write `last_login_*`, rotate the
  client's remember token and invalidate the whole session (which would sign the admin out). `Auth::logout` for a normal
  user is intercepted in `AuthenticatedSessionController::destroy()` — while acting it only leaves the client
- **Consent gate (Step 0):** a client sends a request at `/help-request` (`AdminHelpRequest`, one current request per client);
  an admin with `users.act_as` claims it at `/admin/help-requests`, which assigns them, opens an access window
  (`ADMIN_HELP_REQUEST_ACCESS_DAYS`, 7) and emails the client. **A session can only start while the assigned admin holds a
  claimed, unexpired request**, and `invalidReason()` re-checks it on every request, so cancel / decline / complete / expiry
  ends a live session at once. `ADMIN_ACT_AS_REQUIRE_REQUEST=false` bypasses it and exists only for local development.
  Every state change goes through `HelpRequestService` under a row lock. Stale requests are expired lazily
  (`AdminHelpRequest::expireStale()`), not by a scheduler. `Declined` is an addition to the plan's status list
- **A request about one event scopes the session to it** (`event_id` in the session): other events' pages and
  `events.create` / `events.store` are 403. A request with no event can reach the whole account
- **Permission:** `users.act_as`, given to `admin` and `super_admin`, **not** `support`. Re-seed `RolePermissionSeeder` on an
  existing database. Refused for non-active or unverified clients, a client linked to an `Admin`, or when this browser already
  has a `web` user signed in
- **Route isolation:** `acting-as.block` (`BlockWhileActingAs`) 403s credentials (`/settings/security`, `password.update`),
  account deletion, every payment action, help-request send/cancel, and **the whole admin panel** (only
  `admin.acting-as.destroy` is exempt). The email field on the profile form cannot change. `acting-as.block:friendly` is on the
  GETs that plan/credit gates redirect to (`billing.show`, remove-branding, public-registration pay): a safe request bounces
  back with `acting_notice` (shown by the banner) instead of a dead 403; anything state-changing stays 403
- **`EnforceActingAsSession`** is appended to the `web` group: ends a session when the admin guard is gone, the client is no
  longer active, the request is no longer open, or `ADMIN_ACT_AS_TTL_MINUTES` (60) has passed
- **Banner:** `<x-acting-as-banner />` (fixed to the bottom edge, so it never moves the sidebar) is included by
  `layouts/app`, `layouts/site` and `layouts/guest`. Pages with their own standalone layout do not show it
- **Audit trail:** `admin_activity_log` (its own table, every FK `nullOnDelete` so it outlives the admin, client and event).
  One middleware step logs every state-changing request made while acting (action = a name from `ACTIONS` or the route name),
  skipping failed and validation-rejected ones, plus `session_started` / `session_ended` (with a reason) and a
  `credits_spent` entry when the balance moves. `events.store` is logged by the `Event` model's `created` hook instead, which
  also sets `events.created_by_admin_id` — so every creation path is covered, including `TicketedEventCreator`. A logging
  failure is reported, never thrown. Shown on the admin user and event pages and to the client under "What our team did"
- **Entry points:** the admin help-request page ("Start acting as…", client credits, "Grant credits"), the admin event page
  ("Edit as client", only when the signed-in admin holds a claimed request that **covers** that event — about it, or about the
  whole account), and an events-index "Created by admin" filter. There is deliberately no client picker or start button on the
  user page
- **Per kind:** invitation events spend the **client's** credit on publish. Ticketed events and free-registration events never
  spend one; their admin approval and the free-registration quote live in the admin panel, so the admin **exits first**, and the
  client pays the quote themselves. Tier gates follow the client's tier — change it first via `PATCH /admin/users/{user}/tier`
- **Hand-off:** completing a request emails the client (`HelpRequestCompletedNotification`: what was done from the audit trail,
  an event link, and the one thing left for them) and a "Set up by our team" badge shows on events with `created_by_admin_id`
- **Tests:** `ActingAsClientTest`, `HelpRequestTest`, `ActingAsAuditTest`, `AdminActingAsEntryPointsTest`,
  `ActingAsEventKindsTest`, `HelpRequestHandoffTest`. `$this->actingAs($admin, 'admin')` makes `admin` the default guard for
  the rest of the test; call `auth()->shouldUse('web')` after starting a session, and `auth()->guard('web')->forgetUser()` to
  make the next request reload the client (a real request always does)
- **Privacy §2, §4 and §7** describe the help request, the "only after you ask, cancel any time, never password/email/payments"
  promise, and how long the audit record is kept — but **only while `ADMIN_ACT_AS_ENABLED` is on**, the same way the reminder
  and purge wording follows its setting, so the page never describes access the platform has switched off. Change the feature
  and you change that copy with it. Still unreviewed by a lawyer. Acting as exists on the web only — Sanctum tokens and the
  Android API are untouched

### Event Preview

`GET /events/{event}/preview` (`EventPreviewController`) renders the event's real, current invitation —
the same `events.invitations.renderer` partial and `InvitationCustomizationService::merge()` output the
public page uses — but gated on `EventPolicy::view` (owner-only) instead of `is_published`/`is_public`.

- This is the only way a host can ever see a **private** (`is_public = false`) event's invitation, published
  or not — `/e/{slug}` (`PublicEventController::show`, via `PublicInvitationResolver`) 403s on
  `is_public = false` regardless of who is asking, by design. It's also the only way to see a **draft**
  invitation before spending a credit to publish. Still keyed on `is_public`, not `audience` — see "Event
  Audience" below for why those two are kept in agreement on every save and this doesn't need to change
- Does not increment `invitation_views_count` — that column is real guest traffic
- Redirects to `events.choose-template` if `invitation_template_id` is still null; there is nothing to
  render yet
- **The Android app previews through a signed link, not a session.** `GET /api/v1/host/events/{event}/preview`
  (same `EventPolicy::view` check) returns `app_preview_url`, a relative-signed `events.app-preview` URL that expires
  after `EventPreviewResource::APP_PREVIEW_TTL_MINUTES` (30). `EventPreviewController::showForApp()` renders the same
  `events.preview` view with the preview bar hidden (its back link and publish form need a logged-in browser) and 404s
  for ticketed events or no layout. The signature is the only guard on that route — don't add anything to the page that
  a host-only session would normally protect
- Reuses the `isPreview` flag the renderer already supported for `templates/preview.blade.php` (template
  preview with sample data). A `previewLabel` override on the include distinguishes "this is your real
  event" copy from the sample-data wording
- On the edit page, "Save & publish" starts `disabled` and only unlocks once the host has clicked the
  **Preview invitation** link at least once (`event-edit-save.js`); editing any field afterward re-locks it.
  This is a client-side nudge only, same trust level as the credit-spend `confirm()` dialogs already on that
  page — there is no server-side check that a preview actually happened before `EventController::publish()`

### Featured Templates (homepage)

The homepage "Invitation Templates" strip is curated from the admin panel, not hardcoded:

- `/admin/templates` (`Admin\InvitationTemplateController`, permission `templates.manage`) uploads each template's `preview_image` — cropped to 600×800 WebP under `storage/app/public/templates/` — and toggles `is_featured` / `featured_sort_order`
- The same `preview_image` feeds `/templates` and the wizard's layout picker, so upload once
- `HomeController` reads `InvitationTemplate::featuredForHomepage()` limited to `HOMEPAGE_FEATURED_LIMIT` (4); the whole section is hidden when nothing qualifies
- A template cannot be featured without an image — enforced in `UpdateInvitationTemplateRequest` and again in the scope
- `templates.preview` is **public** (sample data only) so visitors can preview before signing up; `templates.index` still requires auth

### FAQs (homepage + contact page)

Both FAQ blocks are database-driven, not hardcoded:

- `/admin/faqs` (`Admin\FaqController`, permission `faqs.manage`) is a single-page CRUD — add, edit, delete, reorder and publish/unpublish
- `Faq::PLACEMENTS` (`homepage` | `contact`) is the single source of truth for the admin dropdown and `FaqRequest` validation. `FaqSeeder` does **not** read it — placements are hardcoded in its `FAQS` constant, so adding a placement leaves the seeder untouched
- `Faq::publishedFor($placement)` returns published rows ordered by `sort_order` then `id`; `HomeController` and `ContactController@show` each call it, and both sections are hidden entirely when the collection is empty
- Answers are plain text — rendered with `{{ }}`, never `{!! !!}`
- `FaqSeeder` carries the copy the two views used to hardcode, keyed on question + placement so re-seeding is idempotent
- The admin view holds many forms on one page, so a `$oldFor()` closure scopes `old()` repopulation to the form that actually failed validation (via hidden `_form` / `_faq_id` fields)

### Reviews (homepage testimonials)

The homepage testimonial strip is database-driven and admin-curated. One `reviews` table holds two kinds of review, told apart by `source` (`user` | `admin`) and `media_type` (`text` | `video`):

- **Hosts** submit from `/reviews` (`ReviewController`, sidebar → Account → My Reviews) — one review per event they hosted, enforced by a `unique(user_id, event_id)` index. `Event::isReviewable()` gates it: published, `event_date` in the past, not already reviewed. The purchase gate is implicit — publishing an event costs an event credit, so a reviewable event is a paid one; don't add a `payments` check, it would exclude users an admin granted credits by hand
- **Admins** moderate at `/admin/reviews` (`Admin\ReviewController`, permission `reviews.manage`) — approve/reject with a note, correct attribution, feature and order. `support` does not have this permission
- Admin-authored **video reviews** are the only video path — users never upload video, and there is no user-facing video field anywhere. The admin pastes a YouTube link into the "Add a video review" form; `video_ref` stores `youtube:<id>` normalized by `App\Support\InvitationVideoBackground`, and `video_poster` holds an optional 640×360 WebP still
- Video cards are **click-to-play**: the blade renders a poster and a button with the embed URL in `data-testi-video`, and `homepage.js` builds the iframe only on click, so no third-party frame loads on first paint. `InvitationVideoBackground::playerEmbedUrl()` is the unmuted, controls-on embed — distinct from `embedUrl()`, which stays muted and chrome-less for invitation hero backgrounds
- Editing a video review with a blank link keeps the stored video, so the admin can fix wording without re-pasting. Removing the poster does **not** unfeature the review — the video is the requirement, the poster is decoration
- `Review::featuredForHomepage()` returns approved + featured rows in `featured_sort_order`; `HomeController` limits to `HOMEPAGE_FEATURED_LIMIT` (6) and the section is hidden entirely when the collection is empty. A video review with no `video_ref` cannot be featured — enforced in the scope and again in `Admin\UpdateReviewRequest`
- **A host's reviews outlive their account and their events.** `reviews.user_id` and `reviews.event_id` are `nullOnDelete`
  (migration `2026_09_27_110000`), so an approved/featured testimonial stays on the homepage and every kept review stays
  in `/admin/reviews`, where a host review with no `user_id` reads "(account deleted)" and one with no event "Event
  removed" (`Review::hasDeletedAuthor()`). `AccountDeletionService` clears `author_photo` when it equals the deleted
  profile photo — that column holds the *same file path*, not a copy, and the controller deletes the file. Name and context
  stay. Pending and rejected reviews are kept too; an admin deletes them from the moderation page. Privacy §7 says this —
  change both together. Don't assume `$review->user` / `->event` exist
- `author_name` / `author_context` / `author_photo` are **snapshotted at submit time**, so the homepage renders without joining `users`/`events` and a profile rename never rewrites a published testimonial
- A host editing an approved review resets it to `pending` and clears `is_featured` — otherwise a mild review could be approved, featured, then rewritten on a live homepage
- Review bodies are plain text — rendered with `{{ }}`, never `{!! !!}`
- There is deliberately **no seeder**: the three fictional testimonials this section used to hardcode were not real customers, so they were dropped rather than seeded into a table meant for genuine reviews
- The admin view holds many forms on one page, so a `$oldFor()` closure scopes `old()` repopulation to the form that failed (hidden `_form` / `_review_id` fields), same as the FAQ page

### Legal Pages

`/privacy`, `/terms` and `/cookies` are plain `Route::view` static pages (`resources/views/legal/`).
They are public and outside every middleware group, because the sign-in and sign-up consent lines link
to them.

- **The copy has not been reviewed by a lawyer.** It was written to describe how the app actually
  behaves, not to be a binding agreement. The draft banner that used to say so on-page was removed on
  request — nothing warns visitors now, so treat the copy as unverified when editing it
- The operating company is **Kinpin Arts Media** (linked to `kinpinarts.com`), registered office in
  Lusaka. Support address comes from `config('mail.support_address')`, but the pages spell it out
  literally — grep the address if it changes
- Commercial terms now stated: credits **do not expire**, purchases are **non-refundable** (with a
  carve-out for payment faults), minimum age **18**, support **08:00–20:00 CAT**, replies within one
  business day. Keep these in step with the contact page and with `EventCreditService`
- One placeholder is left — `[REMEMBER-ME DURATION]` in `cookies.blade.php`, still wrapped in
  `<span class="legal-token">` and rendering to visitors as-is. Grep `legal-token` to find it
- The **liability cap is now stated**: total fees paid in the twelve months before the claim, falling back
  to the price of one event credit when nothing was paid. Still unreviewed by a lawyer
- The governing-law/dispute clause is written as prose, not a token, and has **not** been filled in
- The three pages cross-link via `legal/partials/siblings.blade.php` and share
  `legal/partials/contact-card.blade.php`
- The contents rail is a bare `<nav>`, so `global.css`'s `nav {}` rule applies — `legal.css` overrides it
  via `nav.legal-toc`, the same pattern `.dash-nav` and `nav.set-tabs` use

### Site Footer

`components/site-footer.blade.php`, rendered by `layouts/site` and `layouts/guest`.

- Every link resolves — there are **no `href="#"` placeholders left**, and `LegalPagesTest` asserts it.
  Do not add a footer link for a page that does not exist yet
- The four link columns are `<nav>` elements for screen readers, so they need the same `nav {}` escape as
  above — `nav.footer-col` in `global.css`
- The brand name comes from the `site_name` platform setting (admin → settings), not a hardcoded string.
  `PlatformSetting::getValue()` caches for an hour, so calling it per render is fine
- Social icons render from `config/social.php`, driven by `SOCIAL_*_URL` env vars. **An unset profile
  renders nothing** — `<x-social-links />` filters out blanks rather than emitting a dead `#`. The same
  component is used on the contact page, so real handles only need adding once
- Product/Support columns point at homepage anchors (`#how`, `#pricing`, `#faq`) that actually exist in
  `home.blade.php`

### Asset Bundling

Vite bundles `resources/css/app.css` (Tailwind) and `resources/js/app.js`. These are loaded with `@vite()` in the layouts. The custom CSS files in `public/css/` are loaded directly with `<link>` tags — they are not processed by Vite.

`public/js/media-uploader.js` must load **before** `event-edit-save.js` — saving waits on
`window.MediaUploader.pending()` so a click mid-upload does not post ids for files still in transit.

`public/js/homepage.js` is loaded with a plain `<script src>` tag (not Vite). It handles: FAQ accordion, chart bar animations, password eye-toggle for auth pages (`.auth-eye` class), and click-to-play for homepage video reviews (`[data-testi-video]`).
