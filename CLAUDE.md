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
| `public/css/event-cards.css` | Public event cards (`.event-card-*`) + `/discover` page — pushed by `home.blade.php` and `events/discover.blade.php` |
| `public/css/reviews.css` | Host review portal (`.rev-*`) — star picker and status pills; pair with `events-admin.css` |
| `public/css/settings.css` | Account settings tab strip (`.set-*`) — pushed by `components/settings-layout.blade.php`; the cards inside each tab reuse `forms-app.css` |
| `public/css/legal.css` | Policy pages (`.legal-*`) — sticky contents rail + prose, pushed by `legal/*.blade.php` |
| `public/css/datetime-picker.css` | Custom date/time picker (`.dtp-*`) — pair with `js/datetime-picker.js` |
| `public/css/custom-select.css` | Custom dropdown (`.cs-*`) — pair with `js/custom-select.js` |
| `public/css/media-uploader.css` | Upload-on-pick tiles (`.mup-*`) — pair with `js/media-uploader.js`; pushed by `events/edit.blade.php` |
| `public/css/ticket-checkout.css` | Buyer-facing ticket flow (`.tkc-*`) — picker, checkout, order status, `/t/{token}` ticket page |
| `public/css/ticket-event-public.css` | Fixed public landing page for ticketed events (`.tev-*`) — hero/about/location layout only; ticket-row text and card chrome reuse `.tkc-*` from `ticket-checkout.css` (loaded alongside) and the generic `.evt-public-*` shell from `events-public.css`. Pushed by `events/tickets/landing.blade.php` and `events/preview.blade.php` when the event is ticketed |
| `public/css/contributions.css` | Contribute page, pay-installment status page (`.ctb-*`) and the invitation-page contribute banner — pairs with `ticket-checkout.css` (loaded alongside), which supplies the shared `.tkc-*` form/checkout chrome. Pushed by `events/contribute.blade.php`, `events/contribution-status.blade.php`, and by `events/public.blade.php` when the event accepts contributions |

Layouts: `layouts/site.blade.php` loads `global.css` + `account-components.css` + Vite; `layouts/app.blade.php` adds `dashboard-shell.css` + `forms-app.css`. Tailwind ships via Vite (`resources/css/app.css`) alongside these files.

### Custom Form Controls

Two reusable, dependency-free controls that progressively enhance native inputs. The native `<input>` / `<select>` stays in the DOM with its `name`, `value` and `required` intact, so controllers, validation and `old()` repopulation are unchanged — only the UI is replaced.

| Control | Opt in | Notable options |
|---|---|---|
| `js/datetime-picker.js` | `data-dtp` on `type="date"`, `time` or `datetime-local` | `data-minute-step` (default 5), `data-hour-format="24"`, `data-week-start="1"`, `data-placeholder`; native `min`/`max` are honoured and also accept `today` / `now` |
| `js/custom-select.js` | `data-cs` on any `<select>` (incl. `multiple`) | `data-cs-search="auto\|always\|never"`, `data-cs-placeholder`, `data-cs-icon`, `data-cs-size="sm"`; per-option `data-icon` / `data-hint`; `<optgroup>` supported |

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
- `/dashboard` → `DashboardController@index` (auth + verified)
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
  qualifies): whether an invitation event may be discoverable/open-RSVP rather than invite-link-only.
  Invite-only itself stays free at every tier, including `none`, and is the default for a new event
  (`events.is_public` defaults `false`). Ticketed events are exempt entirely — `TicketedEventCreator`
  hardcodes `is_public = true` for them regardless of tier, same commission-not-subscription
  reasoning as the gate below. `StoreEventRequest`/`UpdateEventRequest` only raise a validation error
  when someone actually tries to check the box below Base — leaving it unchecked never errors
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

### Event Preview

`GET /events/{event}/preview` (`EventPreviewController`) renders the event's real, current invitation —
the same `events.invitations.renderer` partial and `InvitationCustomizationService::merge()` output the
public page uses — but gated on `EventPolicy::view` (owner-only) instead of `is_published`/`is_public`.

- This is the only way a host can ever see a **private** (`is_public = false`) event's invitation, published
  or not — `/e/{slug}` (`PublicEventController::show`) 403s on `is_public = false` regardless of who is
  asking, by design. It's also the only way to see a **draft** invitation before spending a credit to publish
- Does not increment `invitation_views_count` — that column is real guest traffic
- Redirects to `events.choose-template` if `invitation_template_id` is still null; there is nothing to
  render yet
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
