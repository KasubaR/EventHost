# Android app — requirements & tech stack

**Status:** planning only — nothing in this plan is built. No mobile code, no JSON API.

## 0. Scope decision (confirmed with product owner, 2026-09-11)

The web app serves four distinct personas: public **guests**, authenticated **hosts**, **event
staff** (door check-in), and internal **admins** (`/admin/*`). The Android app covers:

- ✅ **Guest flows** — everything a public visitor can do with no account: view an invitation,
  RSVP, buy tickets, pay contributions, view/hold a ticket, upload table photos, browse the photo
  wall.
- ✅ **Host flows** — everything a logged-in host can do from `/dashboard` down: create/manage
  events, guests, tables, ticketing, check-in scanning, staff, billing, settings, reviews.
- ❌ **Admin panel** (`routes/admin.php`, `App\Http\Controllers\Admin\*`) — explicitly excluded.
  It's an internal-staff tool (user management, template/FAQ/review moderation, payouts,
  reports, platform settings) and does not belong in a public app-store listing.
- ❌ **Enterprise / Contact Sales flow** (`CustomQuote`) — hand-built off-platform, no self-serve
  UI on web either; nothing for mobile to do here.

This matches "Host + Guest app" — the same audience the *website* already serves at one domain,
just native. A host and a guest are the same Android app; which screens they see depends on
whether they're signed in, exactly like the website today.

## 1. Why this is a bigger project than "wrap the website"

`grep -c` on `routes/web.php` (excluding `admin.php` and `auth.php`) turns up **~90 in-scope
routes** across 30+ controllers, and **there is currently no JSON API** — every controller in this
repo returns a Blade view or a redirect. `docs/authentication-deployment.md` already flags this
under "Post-launch (not implemented here)":

> Sanctum-backed API versioning under `/api/v1`

So the real first deliverable isn't an Android screen — it's a `routes/api.php` that doesn't exist
yet. Section 3 scopes that out explicitly; everything in Section 4 assumes it exists.

## 2. Recommended tech stack

| Layer | Choice | Why |
|---|---|---|
| Language | **Kotlin** | Google's official, Jetpack-first language since 2019; new Java-only tooling has been de-prioritized for years. Jetpack Compose, Coroutines and most current sample code/docs are Kotlin-first — writing this in Java means fighting the ecosystem, not just a syntax preference. Not recommending Java for a 2026 greenfield app. |
| UI toolkit | **Jetpack Compose + Material 3** | Declarative UI matches the churn this app will see (invitation design, ticket states, contribution progress) far better than XML/View-based layouts; Google's own default for new apps. |
| Architecture | **MVVM + Repository**, unidirectional data flow (`ViewModel` → `StateFlow` → Compose) | Matches Android Architecture Components guidance; keeps networking/caching out of Composables, which matters here given how many screens are "list + detail + mutate" (guests, tickets, tables). |
| DI | **Hilt** | Standard for this stack, minimal boilerplate over vanilla Dagger. |
| Async | **Kotlin Coroutines + Flow** | Pairs with Retrofit's suspend-fun support and Room's `Flow` queries; needed for the ticket-hold countdown timer and upload-progress UI (Section 6.6) in particular. |
| Networking | **Retrofit + OkHttp**, `kotlinx.serialization` (or Moshi) for JSON | OkHttp interceptor is the natural place to attach the Sanctum bearer token and to log/retry. |
| Local persistence | **Room** (offline cache: events, guests, tickets) + **Jetpack DataStore** (auth token, small prefs — replaces `SharedPreferences`) | Room's `Flow` queries let list screens render instantly from cache while a refresh runs in the background — valuable for the guest list and ticket wallet, which hosts/guests reopen often. |
| Image loading | **Coil** (Kotlin-first, Compose integration) | Loads cover images, template previews, gallery/photo-wall images, avatars. |
| QR | **ML Kit Barcode Scanning** (on-device, no network) for scanning; **display, don't regenerate**, server-issued QR for showing one (see Section 6.5) | Check-in scanning (`CheckInController`, `TicketCheckInController`, `PublicCheckInController`, `PublicTicketCheckInController`) is the single most "native app" feature in this whole project — a phone camera beats the web check-in page's browser camera API by a wide margin. This is worth prioritizing early (see Section 8). |
| Navigation | **Navigation Compose**, single-Activity | Needed for deep links (Section 6.2) — Nav Compose's `deepLinks {}` block maps directly onto the token/slug URL patterns already in `routes/web.php`. |
| Payments | **Chrome Custom Tabs** against the existing hosted Lenco checkout page, not a native Lenco SDK | See Section 9 — Lenco is currently a server-driven redirect flow (`PaymentController`, `EventTicketCheckoutController`, `EventContributionController`); no evidence of a native Android SDK. Custom Tabs keeps PCI/3-D-Secure entirely off-device and off this app's attack surface. |
| Maps | **Google Maps SDK for Android** (event location display) | The web already stores `latitude`/`longitude` per event (`MapLinkController` resolves a pasted Maps link server-side) — the app just renders a pin, no new geocoding needed client-side. |
| PDF | **In-app viewer via `androidx.pdf` / open in a PDF viewer intent**, not a custom renderer | Server already generates tickets (`TicketController::download`), guest QR sheets and PDF exports via `barryvdh/laravel-dompdf` — the app downloads the finished PDF and hands it to the system viewer or `Intent.ACTION_VIEW`; no PDF rendering logic needed on-device. |
| Push | **Firebase Cloud Messaging** | See Section 6.3 — this is new infrastructure on the backend too, nothing to reuse. |
| Testing | JUnit5, **MockK**, **Turbine** (Flow testing), Compose UI testing, **Espresso** for a handful of end-to-end smoke flows | |
| Min / target SDK | **minSdk 26** (Android 8.0, ~98%+ of active devices), **target/compileSdk** latest stable at build time | 26 is a safe modern floor without cutting off a meaningful user base in the target market. |
| Crash/analytics | Firebase Crashlytics (+ Analytics, optional) | |
| Build | Gradle Kotlin DSL, **version catalogs** (`libs.versions.toml`) | |
| CI/CD | GitHub Actions → Gradle build/test/lint → Play Console internal testing track | |

Single Gradle module (`:app`) to start. Don't split into feature modules until the guest and host
surfaces are both built and the build-time pain is real — premature modularization here would slow
down exactly the phases (Section 8) that need to move fastest.

## 3. Backend prerequisite: build `/api/v1`

Nothing in Section 4 is buildable until this exists. This is Phase 0 (Section 8), and it's a
Laravel task, not an Android one.

- **New route file** `routes/api.php` (Laravel's `api` middleware group, `stateless` Sanctum
  guard), versioned under `/api/v1` per the roadmap note in `docs/authentication-deployment.md`.
- **Auth endpoints** (net-new — Breeze's session-based controllers in `routes/auth.php` stay as
  they are for the web, the API needs its own token-issuing equivalents):
  - `POST /api/v1/auth/register`, `POST /api/v1/auth/login` → issues a Sanctum personal access
    token (`$user->createToken(...)`) instead of a session cookie.
  - `POST /api/v1/auth/logout` → `$request->user()->currentAccessToken()->delete()`.
  - `POST /api/v1/auth/forgot-password`, `POST /api/v1/auth/reset-password` — wrap the existing
    `Illuminate\Auth\Passwords` broker, same as `PasswordResetLinkController`/`NewPasswordController`
    do for web.
  - `POST /api/v1/auth/email/verification-notification` (resend) — the app needs a "check your
    email" state since there's no signed-link-in-browser step to fall back on.
  - Token TTL: Sanctum is already configured for 10080-minute (7-day) expiry with a daily
    `sanctum:prune-expired` schedule — reuse as-is, just add a refresh-before-expiry or
    silent-relogin UX on the client.
- **Controllers**: thin JSON-returning siblings of the existing web controllers, delegating to the
  **same service classes** the web already uses (`ProfileService`, `EventCreditService`,
  `PaymentCompletionService`, `ContributionPaymentStatusService`, `InvitationMediaStager`, etc.).
  Do not duplicate business logic into new API-only services — every gotcha documented in
  `CLAUDE.md` (staged-media scoping, the `withValidator()` capacity check, the credit-spend
  transaction, contribution phone-matching) lives in a service class today and must keep living
  there.
- **Response shape**: Eloquent API Resources (`JsonResource`/`ResourceCollection`) per model in
  Section 7, not raw `toArray()`. Laravel's default validation-error JSON shape
  (`{"message":..., "errors": {field: [...]}}`) is fine as-is — mirror it, don't invent a new one.
- **File uploads**: every existing multipart endpoint (`EventInvitationMediaController::store`,
  `GuestImportController::store`, `TableUploadController::store`, `EventPhotoController`, profile
  photo) needs to be reachable as `multipart/form-data` from OkHttp — verify none of them
  implicitly assume a browser-set `Referer`/session state beyond the auth guard.
- **New, mobile-only endpoints** (nothing to reuse from web):
  - Device push-token registration/deregistration (`POST/DELETE /api/v1/devices`) — new table,
    new model. See Section 6.3.
  - A "what can I do" bootstrap endpoint returning the current user + `subscription_tier` +
    computed capability flags (`canUsePremiumEventTools()`, `canChooseInvitationPalette()`,
    `canSendAutomatedReminders()`, `canMakeEventsPublic()`) so the app doesn't reimplement the
    tier-rank logic from `User.php` client-side — it should ask the server, not duplicate
    `SubscriptionTier` ranking rules on-device where they'll drift.
- **Rate limiting**: reuse the existing named throttles (`ticket-hold`, `ticket-checkout`,
  `contribution-checkout`, `staff-checkin`, `rsvp-submit`, `invitation-media`, `invitation-design`,
  `guest-bulk-send`, …) on the new API routes — they encode real product decisions already, not
  web-specific concerns.
- **API docs**: generate from the codebase (e.g. `dedoc/scramble` or `knuckleswtf/scribe`) rather
  than hand-maintaining a spec that drifts from the 90-route surface in Section 4.
- **CORS**: not applicable for token-authenticated native requests (no browser origin involved) —
  don't spend time on a CORS policy for the mobile client; a `sanctum_stateful_domains` cookie
  flow is a *web SPA* concern, not this app's.

## 4. Feature requirements by screen

Every row cites the real controller/route it's replacing so there's a ground truth to check
behavior against. "New" means there's no web equivalent to copy — the mobile UX has to be designed
from scratch.

### 4.1 Guest flows (no account required)

| Screen | Backs onto | Notes |
|---|---|---|
| Deep-link landing / splash | — | See Section 6.2 — every row below is normally arrived at via a shared link, not in-app browsing. |
| Discover events | `PublicEventController::index` (`/discover`) | Public browse list. |
| Event invitation view | `PublicEventController::show` (`/e/{slug}`) | Renders `InvitationCustomizationService::merge()` output — the app needs its own native renderer per template, not a WebView of the Blade partial (see Section 9 on why WebView is the wrong call here too). |
| Add to calendar | `/e/{slug}/calendar.ics` | Download the `.ics` and hand to `Intent.ACTION_VIEW` / the calendar provider — no native reimplementation needed. |
| RSVP (token link) | `RsvpController::showByToken/storeByToken` (`/rsvp/{token}`) | Accept/decline/maybe, plus-one, guest count. |
| RSVP (open, no token) | `RsvpController::showOpen/storeOpen` (`/e/{slug}/rsvp`) | |
| RSVP confirmation / entry pass | `rsvp.token.thanks`, `RsvpController::entryPassQr` | The bookmarkable, refreshable confirmation page — the app's equivalent is a persistent "My RSVP" card, not a one-shot toast. |
| Ticket browse + hold | `EventTicketPurchaseController::show/hold` (`/e/{slug}/tickets`) | The hold is time-limited inventory — the app needs a visible countdown (Coroutine `Flow` ticking down) matching the server-side hold TTL, and must handle hold-expired gracefully mid-checkout. |
| Ticket checkout | `EventTicketCheckoutController::show/store` | Hands off to Lenco via Custom Tabs (Section 9). |
| Order status | `EventTicketCheckoutController::status/verify` (`/tickets/orders/{ref}`) | Bookmarkable-equivalent: store the order reference locally so the app can reopen this without the user re-finding the link. |
| Ticket wallet | `TicketController::show/qr/download` (`/t/{token}`) | List of the user's tickets on-device (Room-cached), each with its QR and a PDF download/share action. Stretch: Google Wallet pass. |
| Contribution pledge | `EventContributionController::show/store` (`/e/{slug}/contribute`) | Fixed amount, no cart. |
| Contribution status / pay installment | `EventContributionController::status/pay/verify` (`/contributions/{reference}`) | A returning contributor is matched by **normalized phone**, not login (`EventContribution::normalizePhone()`) — the app should let a guest re-find their pledge by re-entering the same phone number, matching that server behavior, rather than inventing an account system contributions don't have. |
| Table photo upload | `TableUploadController::show/store` (`/e/{slug}/table/{code}`) | Camera/gallery picker; this is the guest-side twin of the host media uploader (Section 6.6) but simpler — one-shot append, no staged-media lifecycle. |
| Photo wall | `EventGalleryController::show/feed` (`/e/{slug}/gallery`) | Feed view; respects `photo_wall_requires_approval` (only `approved` `PhotoStatus` shows). |
| Event host bar | `resources/views/components/event-host-bar.blade.php` | Render natively (logo/tagline/CTA) on every public event screen, honoring `branding_removed`. |

### 4.2 Host flows (authenticated)

| Screen | Backs onto | Notes |
|---|---|---|
| Register / login / logout | `routes/auth.php` (Breeze) | Via the new token endpoints in Section 3, not session cookies. |
| Forgot / reset password | `PasswordResetLinkController`, `NewPasswordController` | |
| Email verification gate | `EmailVerificationPromptController`, `VerifyEmailController` | The app needs a persistent "verify your email" banner/blocking state — verification itself still happens by the user tapping a link in their email (opens in browser or deep-links back per Section 6.2), there's no in-app verification UI to build beyond "resend" and "check status." |
| Dashboard home | `DashboardController::index` (`/dashboard`) | Overview stats. |
| Event list / create / edit | `EventController` (resource), `EventChooseTemplateController` | Full CRUD; publish costs a credit (`User::canCreateEvent()` — surface the credit balance and the same confirm-before-spend UX the web has). |
| Event lifecycle actions | `publish/pause/resume/cancel/uncancel/restore` (`EventController`) | |
| Event preview | `EventPreviewController::show` (`/events/{event}/preview`) | Host-only real-invitation view, gated on ownership not `is_published`/`is_public` — the only way to see a private or draft event's invitation. |
| Invitation design editor | `EventInvitationDesignController`, `EventInvitationMediaController` | The single most complex screen to port — see Section 6.6. Do not attempt a literal port of the panelled web editor; design a mobile-native equivalent (likely a linear step flow) around the same staged-upload API. |
| Guests: list/add/edit/delete | `GuestController` (scoped resource) | |
| Guest groups | `GuestGroupController` | |
| Guest import (CSV) | `GuestImportController` | Android `ACTION_OPEN_DOCUMENT` file picker → multipart upload; template download for reference. |
| Guest bulk actions (send invites) | `GuestBulkActionController` | |
| Guest export (CSV / PDF) | `GuestController::export/exportPdf` | Download + share sheet, same pattern as ticket PDFs. |
| Guest QR / QR sheet | `GuestController::qr/qrSheet` | |
| Tables | `EventTableController`, `TableUploadController` (QR sheet) | |
| Check-in scanning (RSVP) | `CheckInController` (`lookup/confirmGuest/confirmToken/scan`) | **Priority native feature** — ML Kit camera scan → `confirmToken`/`confirmGuest`, with a manual-lookup fallback for a guest without their QR handy. |
| Check-in scanning (tickets) | `TicketCheckInController` | Twin of the above for ticketed events. |
| Staff scanner links | `EventStaffLinkController` | Host generates/revokes shareable no-login scanner links (`/checkin/{staffToken}`, `/checkin/tickets/{staffToken}`) — the app needs a native share-sheet action here, since the link itself is meant to be opened by someone *without* the app or an account. |
| Staff accounts | `EventStaffController`, `EventStaffInvitationController` | Invite by email, `manager`/`checkin` roles (`EventStaffRole`), resend/revoke. |
| Ticketing setup | `EventTicketTypeController`, `EventTicketingController` (`update/submit`) | Ticket type CRUD; submitting for admin review is a status transition (`TicketingStatus`), not an instant "go live." |
| Ticket management | `EventTicketDashboardController`, `EventTicketManagementController` | Overview, list, resend/reissue/cancel/confirm-checkin, export. |
| Ticket revenue / payouts | `EventTicketRevenueController` | **Read-only** on mobile — only an admin records a payout, matching web. |
| Contribution status (host view) | via `Event` relations, not a dedicated host controller today | Confirm during API design whether a host-facing contribution summary endpoint needs to be added — the web currently surfaces this through the event show page, not a standalone route. |
| Photo wall moderation | `EventPhotoController` (`update/destroy/index`) | Approve/hide submitted photos when `photo_wall_requires_approval` is on. |
| Remove-branding purchase | `RemoveBrandingController` | One-off add-on checkout, same Custom Tabs pattern as Section 9. |
| Billing | `PaymentController::show/initiate/verify` (`/billing`) | Subscription tier purchase (`base`/`pro`/`pro_plus` from `config/billing.php`) via Lenco. |
| My Reviews | `ReviewController` | Host submits a review for a past, published, unreviewed event (`Event::isReviewable()`); edit resets it to `pending` — surface that consequence in the UI before an edit is confirmed, same as the web should. |
| Settings — profile | `Settings\ProfileController` | Photo (400×400 crop before upload, matching the server-side WebP conversion target), name, email, phone, company. |
| Settings — security | `Settings\SecurityController`, `PUT /password` | Password change form only. |
| Settings — notifications | `Settings\NotificationController` | The five (now six, with `email_contribution_updates`) preference toggles — **plus the new push-specific toggles** this app introduces (Section 6.3), which don't exist in `User::DEFAULT_NOTIFICATION_PREFERENCES` yet. |
| Settings — account (danger zone) | `Settings\AccountController` | Delete-account flow; needs the same confirm-with-consequences modal the web has, not a bare confirm dialog. |

## 5. Cross-cutting non-functional requirements

### 5.1 Auth & session model
Bearer token (Sanctum), stored in **`DataStore` backed by the Android Keystore** (encrypted), never
in plain `SharedPreferences`. OkHttp `Authenticator`/interceptor attaches it; a 401 clears local
state and routes to login. A web session and an app token can coexist for the same user without
conflict — Sanctum supports both concurrently.

### 5.2 Deep linking (this is load-bearing, not optional)
Nearly every guest-facing screen in Section 4.1 is normally *arrived at via a link someone shared* —
WhatsApp, SMS, email — not by opening the app and browsing. Without Android App Links configured,
tapping `https://<domain>/e/{slug}` on a phone with the app installed opens a browser instead of
the app, which defeats most of the point of building this app at all. Requirements:
- Android **App Links** (verified `https` intent filters, not a custom `myapp://` scheme, so links
  keep working for users without the app installed) for: `/e/{slug}`, `/e/{slug}/tickets`,
  `/e/{slug}/contribute`, `/e/{slug}/gallery`, `/e/{slug}/table/{code}`, `/rsvp/{token}`,
  `/t/{token}`, `/tickets/orders/{orderReference}`, `/contributions/{reference}`,
  `/staff/invitations/{token}`.
- A `/.well-known/assetlinks.json` served from the Laravel app (new, static route) declaring the
  Android package + signing-cert fingerprint.
- **Explicitly not** claiming `/checkin/{staffToken}` / `/checkin/tickets/{staffToken}` as app
  links — those links are handed to people *without* the app or an account by design (see the
  staff scanner links row above); intercepting them into an app requiring login would break that
  flow.

### 5.3 Push notifications (new backend surface, not a port)
Every current notification in this app is email-only (`WelcomeNotification`,
`EmailChangedNotification`, `PaymentReceiptNotification`, `ContributionReceiptNotification`,
`NewContributionReceivedNotification`, RSVP/reminder emails gated by
`notification_preferences`). None of it is push today. Building push means:
- New `device_tokens` table/model (user_id, fcm_token, platform, last_seen_at).
- Extending the existing notification classes to add an `fcm` channel alongside `mail` (Laravel
  notifications support multi-channel natively — this is additive, not a rewrite) rather than a
  parallel notification system.
- New preference keys (e.g. `push_rsvp_updates`, `push_event_reminders`) — decide whether push
  piggybacks on the existing `email_*` toggles or gets its own row in
  `User::DEFAULT_NOTIFICATION_PREFERENCES`; given `ProfileService::updateNotificationPreferences()`
  already merges-over-defaults safely (per `CLAUDE.md`), adding keys is low-risk either way.

### 5.4 Media capture & upload
Mirror the web's "upload on pick" philosophy (`plans/upload-progress.md`) rather than a
save-everything-at-the-end model: stage each photo the moment it's picked, show real per-file
progress, and let the save/submit action simply reference already-uploaded IDs. Concretely:
- Camera + gallery picker (`ActivityResultContracts.PickVisualMedia` / `TakePicture`).
- Client-side downscale/compress before upload (the server still re-encodes to WebP —
  `ProcessInvitationDesignImageJob`, `InvitationMediaStager::storeCover()` — but sending a
  12 MP camera photo over a mobile connection unshrunk is a real cost worth avoiding).
- Multipart upload with progress via OkHttp's `RequestBody` progress wrapper (Retrofit alone
  doesn't expose upload progress — same reason the web uses `XMLHttpRequest` over `fetch`).
- Respect the same slot model (`gallery`, `hero_portrait`, `couple`, `speaker:0`–`speaker:3`,
  `cover`, `audio`) and single-vs-append replace semantics documented in `CLAUDE.md`.

### 5.5 QR codes: display server-issued ones, don't regenerate them
The server already generates every QR the app needs to show (`bacon/bacon-qr-code`, exposed as
`.../qr.svg` endpoints and PDF sheets). Two options for rendering an SVG on Android: decode it
with an SVG-capable image loader, or add a `?format=png` variant to those endpoints server-side.
Prefer the latter — one small backend change beats bundling an SVG renderer for a handful of
endpoints. **Scanning** is the opposite direction and does need an on-device library (ML Kit,
per Section 2) since that has to work against a live camera feed, not a fetched image.

### 5.6 Offline behavior
Not a "make everything work offline" requirement — scope it per screen:
- **Should work offline / cache-first**: ticket wallet (a guest needs their QR at the door even on
  bad venue wifi), the host's guest list and check-in lookup (Room-cached, sync on reconnect).
  Check-in *confirming* still needs connectivity (it's a server-side state change guarded by row
  locks per `CLAUDE.md`'s ticket/contribution crediting patterns) — but the scan-and-lookup step
  should degrade gracefully, not hang, on a flaky connection.
- **Fine to require connectivity**: event creation/editing, payments, RSVP submission, everything
  admin-adjacent.

### 5.7 Permissions
Camera (QR scan + photo capture), media/gallery read (Android 13+ granular media permissions, not
legacy `READ_EXTERNAL_STORAGE`), POST_NOTIFICATIONS (Android 13+ runtime prompt), no location
permission needed for anything in Section 4 (no "near me" feature exists on web to justify one).

### 5.8 Accessibility & localization
Content descriptions on all icon-only controls (scanner button, QR display), Compose's built-in
dynamic-type support, TalkBack pass on the check-in scan flow specifically since it's the
highest-frequency repeated action. Currency is fixed `ZMW` (`config/billing.php`) and there is no
i18n anywhere in the current app — ship single-locale (en) for v1, same as web.

## 6. Data model reference

Condensed to what the app actually needs to know about, from the 30 models in `app/Models/`.
Admin-only models (`Admin`, `Report`, `PlatformSetting` writes, `NotificationLog`,
`InvitationTemplateCategory` management, `CustomQuote`) are omitted — out of scope per Section 0.

| Model | Relevant fields/enums | App-relevant notes |
|---|---|---|
| `User` | `account_type`, `status` (`pending`\|`active`\|`suspended`), `subscription_tier` (`SubscriptionTier`: none < base < pro < pro_plus < enterprise), `event_credits`, `notification_preferences` (JSON), `profile_photo` | `event_credits` is write-protected at the model level (`booted()` throws if changed outside `EventCreditService`) — the API must never expose a raw "set credits" endpoint. |
| `Event` | `product_kind` (`EventProductKind`: invitation\|ticketed), `ticketing_status` (`TicketingStatus`), `is_public`, `is_published`, `cancelled_at`, `invitation_paused_at`, `contribution_enabled`/`contribution_amount`, `branding_removed`, `photo_wall_enabled`/`_requires_approval`, `invitation_customization` (JSON) | The single largest model (1000+ lines) — the app should consume it through the same `InvitationCustomizationService::merge()`-shaped API response the web renderer uses, not re-derive customization merging client-side. |
| `Rsvp` | status: `RsvpStatus` (accepted\|declined\|maybe) | |
| `Guest`, `GuestGroup` | | QR check-in token lives on `Guest`. |
| `EventTable` | | Table QR → guest photo upload. |
| `TicketType`, `Ticket`, `TicketOrder`, `TicketOrderItem`, `TicketReservation`, `TicketPayment` | `TicketStatus` (valid\|used\|refunded\|cancelled), `TicketOrderStatus` (7 states incl. `pending_payment`\|`payment_processing`), `TicketReservationStatus` (held\|converted\|expired\|released) | The hold→checkout→paid pipeline the countdown-timer UI (Section 4.1) has to reflect accurately. |
| `EventContribution`, `ContributionPayment` | `ContributionStatus` (pending\|partial\|completed) | `amount_paid` only ever changes inside `ContributionPaymentStatusService::creditContribution()` — never write it directly from the API layer either. |
| `EventStaff`, `EventStaffLink` | `EventStaffRole` (manager\|checkin) | Two different staff mechanisms — an *account* (`EventStaff`) vs a *shareable no-login link* (`EventStaffLink`) — don't conflate them in the UI. |
| `EventPhoto` | `PhotoStatus` (pending\|approved\|hidden) | |
| `Review` | `ReviewStatus` (pending\|approved\|rejected), `ReviewMediaType` (text\|video — video is admin-authored only, never from this app) | |
| `Payment`, `CreditTransaction` | | Subscription/credit purchase history — read-only in-app. |
| `InvitationTemplate` | | Browse/preview only from this app; management is admin-only. |

## 7. Payments: how Lenco checkout actually works on mobile

`PaymentController`, `EventTicketCheckoutController` and `EventContributionController` all drive
Lenco through a **server-initiated, browser-redirect** flow today — there's no evidence in this
codebase of a native Lenco mobile SDK. Recommended approach:
1. App calls the existing `initiate`-style endpoint, gets back Lenco's hosted checkout URL.
2. Open it in a **Chrome Custom Tab** (not an in-app `WebView`) — Custom Tabs share cookies/state
   with the system browser, get Chrome's phishing/safe-browsing protections, and keep card entry
   fully outside this app's process, which matters for PCI scope.
3. Lenco redirects back to a `payment.verify`/`payment.verify.ref`-style URL on completion — that
   URL needs to be an **Android App Link** (Section 6.2) so it lands back in the app instead of
   stranding the user in the browser tab.
4. App calls the existing `verify`/`verify-ref` endpoint to confirm final status rather than
   trusting the redirect alone (same as web already does — the redirect is a UX nicety, the verify
   call is the source of truth).

This needs a small backend adjustment either way: the success/failure redirect targets configured
for Lenco are currently Blade page URLs — they need to become (or gain a variant that becomes) the
app-link URLs above. Flag this explicitly when scoping the API work in Section 3 — it's a
one-config-value change per payment flow, not a new payment integration.

## 8. Out of scope for v1

- Admin panel (Section 0).
- Enterprise/Contact Sales quote flow.
- Two-factor auth, social login (Socialite) — not built on web either (`docs/authentication-deployment.md` lists both as "post-launch, not implemented").
- Multi-language / i18n.
- iOS — this document is Android-only; treat a future iOS app as a separate plan once the API in Section 3 exists (it'll be the same API for both).
- Native Google Wallet ticket passes — worth revisiting once the core ticket wallet ships.
- Offline event creation/editing.

## 9. Suggested build phases

1. **Phase 0 — Backend API foundation.** `routes/api.php`, Sanctum token auth endpoints, JSON
   resources for `Event`/`Guest`/`Rsvp`/`Ticket`, device-token registration table. Nothing mobile
   ships until this exists.
2. **Phase 1 — Guest MVP.** Deep links, event view, RSVP, ticket purchase + wallet, contribution
   pledge/pay. This is the highest-reach, lowest-auth-complexity slice — ships value before any
   host tooling is built.
3. **Phase 2 — Host core.** Auth, dashboard, event CRUD + lifecycle, guest management, invitation
   design (redesigned for mobile per Section 4.2's note, not ported 1:1).
4. **Phase 3 — Check-in + ticketing.** RSVP and ticket QR scanning (the standout native-mobile
   feature), staff links/accounts, ticket management. Prioritize this before Phase 4 — it's the
   feature this app justifies itself with.
5. **Phase 4 — Everything else host-side.** Billing, remove-branding, reviews, settings, photo
   moderation, push notifications end-to-end.
6. **Phase 5 — Hardening.** Offline caching polish, deep-link edge cases, accessibility pass, Play
   Store listing + staged rollout.

## 10. Open questions to resolve before/while building

- Does Lenco publish a native Android SDK after all (worth one confirmation check before
  committing to the Custom Tabs approach in Section 9)?
- Push-preference keys: extend `User::DEFAULT_NOTIFICATION_PREFERENCES` with parallel `push_*`
  keys, or reuse the existing `email_*` ones as "this notification, any channel"? Affects both the
  API contract and the Settings — Notifications screen.
- Does a host-facing contribution summary need a dedicated endpoint (noted as unclear in
  Section 4.2), or does it ride along on the existing event-show payload?
- Google Play policy on ticketed/paid events: confirm whether Play's payments policy requires
  Google Play Billing for anything here (generally: physical-goods/real-world-services payments
  like event tickets are exempt, but verify against current Play policy before submission, not
  after).
