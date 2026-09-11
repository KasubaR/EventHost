# Android app — step-by-step implementation

Canonical copy also lives at `EventHostAndriodApp/implementation.md`. Keep them in sync.

Ground truth requirements: `plans/android-app.md`.

**Audience:** Host + Guest in one app. Admin (`/admin/*`) and Enterprise quote are out of scope.

**Current state (2026-09-11):**

| Layer | Reality |
|---|---|
| Laravel | No `routes/api.php`. `bootstrap/app.php` does not register an API route file. Sanctum is installed (daily `sanctum:prune-expired`). Every in-scope controller returns Blade or a redirect. |
| Android | Empty View-based template (`AppCompatActivity` + XML). Package `com.sunconnecttechnologies.eventhost`, `compileSdk` 36, **minSdk 24** (plan says 26). No Compose, Hilt, Retrofit, Room, or Navigation. |
| Shared | ~90 in-scope web routes, 30+ controllers, existing services must stay the single source of business rules. |

Do not start Android feature screens until Phase 0 Slice A (auth + public event read) exists. You can scaffold the Android project in parallel with Slice A.

Lock these product decisions before coding (from Section 10 of the spec):

1. Payments: Custom Tabs against Lenco hosted checkout unless a native SDK is confirmed.
2. Push prefs: add parallel `push_*` keys (do not overload `email_*`).
3. Host contribution summary: include it on the event-show JSON payload for v1; add a dedicated endpoint later only if the payload is too heavy.
4. Play Billing: event tickets / contributions / subscriptions for real-world events are typically exempt; confirm current Play policy before store submission (Phase 5), not before Phase 0.

---

## Conventions (every API step)

- Prefix: `/api/v1`
- Guard: Sanctum **stateless** bearer tokens. Leave Breeze session auth on `routes/auth.php` untouched.
- Controllers: thin JSON siblings under `App\Http\Controllers\Api\V1\...`. Delegate to existing services (`ProfileService`, `EventCreditService`, `PaymentCompletionService`, `ContributionPaymentStatusService`, `InvitationMediaStager`, `RsvpSubmissionService`, `TicketReservationService`, `TicketCheckoutService`, `CheckInService`, `TicketCheckInService`, `InvitationCustomizationService::merge()`, etc.).
- Responses: Eloquent `JsonResource` / `ResourceCollection`. Validation errors stay Laravel default `{ "message", "errors": { field: [...] } }`.
- Never expose a raw “set credits” or “set amount_paid” write. Credits only via `EventCreditService`; contribution amounts only via `ContributionPaymentStatusService::creditContribution()`.
- Reuse named throttles from `AppServiceProvider` (`ticket-hold`, `ticket-checkout`, `contribution-checkout`, `staff-checkin`, `rsvp-submit`, `invitation-media`, `invitation-design`, `guest-bulk-send`, …).
- QR display: add `?format=png` on existing QR endpoints (or sibling PNG routes). Do not regenerate QR on device.
- File uploads: `multipart/form-data`, no Referer/session assumptions.
- Tests: feature tests hitting `/api/v1/...` asserting JSON shape, status codes, and that web Blade routes still work.

---

## Phase 0 — Backend API foundation

Nothing guest-facing ships until Slice A is live. Slice the API by what Android Phase 1–4 actually call.

### 0.1 Wire the API surface

1. Create `EventHostLaravel/routes/api.php`.
2. In `bootstrap/app.php` `withRouting()`, add `api: __DIR__.'/../routes/api.php'` (Laravel 11+ default prefixes `/api` and applies `api` middleware).
3. Inside `api.php`, wrap everything in `Route::prefix('v1')->group(...)`.
4. Confirm Sanctum: `auth:sanctum` on protected routes; personal access tokens; existing 7-day expiry + prune schedule.
5. Add `dedoc/scramble` (or Scribe) and point it at the API routes so the Android team has a generated spec.
6. Serve `GET /.well-known/assetlinks.json` from Laravel (static JSON: package name + release signing cert SHA-256). Can ship a placeholder fingerprint until Play App Signing is set.

**Done when:** `php artisan route:list --path=api/v1` lists the prefix; a health/ping JSON route returns 200.

### 0.2 Slice A — Auth + bootstrap + public event read

Needed for Android login and invitation view.

| Method | Path | Auth | Delegates to / mirrors |
|---|---|---|---|
| POST | `/api/v1/auth/register` | no | `RegisteredUserController` + `$user->createToken('android')` |
| POST | `/api/v1/auth/login` | no | credentials → token + user resource |
| POST | `/api/v1/auth/logout` | yes | delete current access token |
| POST | `/api/v1/auth/forgot-password` | no | password broker |
| POST | `/api/v1/auth/reset-password` | no | password broker |
| POST | `/api/v1/auth/email/verification-notification` | yes | resend verification |
| GET | `/api/v1/me` | yes | user + `subscription_tier` + **computed flags**: `canUsePremiumEventTools`, `canChooseInvitationPalette`, `canSendAutomatedReminders`, `canMakeEventsPublic`, `event_credits` |
| GET | `/api/v1/discover` | no | `PublicEventController::index` |
| GET | `/api/v1/events/{slug}` | no | `PublicEventController::show` + `InvitationCustomizationService::merge()` already applied in the resource |
| GET | `/api/v1/events/{slug}/calendar.ics` | no | existing ICS (binary, not JSON) |

Resources to add first: `UserResource`, `PublicEventResource` (merged customization, host bar: logo/tagline/CTA + `branding_removed`), `InvitationTemplate` browse fields as nested data.

**Done when:** register/login returns a token; `GET /me` with `Authorization: Bearer` works; public event JSON includes merged invitation fields (not raw DB JSON the client would have to merge).

### 0.3 Slice B — Guest mutations (RSVP, tickets, contributions, gallery, table)

Needed for Android Phase 1.

| Area | Endpoints (sketch) | Notes |
|---|---|---|
| RSVP token | `GET/POST /api/v1/rsvp/{token}` | `RsvpSubmissionService`; throttle `rsvp-submit` |
| RSVP open | `GET/POST /api/v1/events/{slug}/rsvp` | same |
| RSVP pass | `GET /api/v1/rsvp/{token}/entry-pass` | PNG QR, not SVG |
| Tickets browse/hold | `GET/POST /api/v1/events/{slug}/tickets` (+ hold) | throttle `ticket-hold`; return hold TTL seconds for the countdown |
| Checkout | `POST /api/v1/events/{slug}/tickets/checkout` | returns Lenco hosted URL + `return_app_link` variant |
| Order | `GET /api/v1/tickets/orders/{ref}`, `POST .../verify` | store `ref` client-side |
| Ticket wallet | `GET /api/v1/t/{token}`, `GET .../qr?format=png`, `GET .../download` | PDF as file |
| Contribute | `GET/POST /api/v1/events/{slug}/contribute` | throttle `contribution-checkout` |
| Contribution status | `GET /api/v1/contributions/{reference}`, `POST .../pay`, `POST .../verify` | lookup by **normalized phone**, not login |
| Table upload | `GET/POST /api/v1/events/{slug}/table/{code}` | multipart |
| Photo wall | `GET /api/v1/events/{slug}/gallery`, `GET .../feed` | only `approved` when moderation is on |

Payment redirect: add app-link return URLs for ticket checkout, contribution pay, and (later) billing / remove-branding. Verify endpoint remains source of truth.

**Done when:** Pest/PHPUnit covers hold expiry JSON, RSVP validation errors, contribution phone rematch, gallery filtering by `PhotoStatus`.

### 0.4 Slice C — Host core (events, guests, invitation media)

Needed for Android Phase 2. All `auth:sanctum` + ownership (or staff role) checks matching web.

- Dashboard stats (`DashboardAnalyticsService`)
- Event CRUD + lifecycle: `publish` / `pause` / `resume` / `cancel` / `uncancel` / `restore` (credit spend only through `EventCreditService`; confirm-before-publish is a client UX on top of the same server rules)
- Event preview (owner-gated, even if unpublished)
- Template choose
- Invitation design GET/PUT + media stage/store (`InvitationMediaStager`, throttle `invitation-media` / `invitation-design`)
- Guests CRUD, groups, CSV import, bulk send, CSV/PDF export, guest QR PNG + QR sheet PDF
- Tables CRUD + QR sheet
- Host contribution totals nested on event show

**Done when:** creating and publishing an event via API spends a credit exactly once; staged media slots match web (`gallery`, `hero_portrait`, `couple`, `speaker:0`–`3`, `cover`, `audio`).

### 0.5 Slice D — Check-in, staff, ticketing ops

Needed for Android Phase 3.

- RSVP check-in: lookup / confirmGuest / confirmToken / scan → `CheckInService`
- Ticket check-in → `TicketCheckInService`
- Staff links CRUD + share URL (do **not** App-Link `/checkin/{staffToken}`)
- Staff accounts + email invitations (`manager` / `checkin`)
- Ticket types CRUD, ticketing submit (`TicketingStatus` transition, not instant live)
- Ticket dashboard + management (resend / reissue / cancel / confirm-checkin / export)
- Ticket revenue **read-only**

**Done when:** confirm endpoints are transactional/row-locked like web; lookup works without confirm; staff token web pages still work in a browser.

### 0.6 Slice E — Settings, billing, reviews, photos, devices, push

Needed for Android Phase 4. Can land later than Slice A–D.

- Profile / password / notification prefs / delete account (`ProfileService`)
- Add `push_rsvp_updates`, `push_event_reminders` (and any other `push_*` you ship) to `User::DEFAULT_NOTIFICATION_PREFERENCES`
- Billing initiate/verify (Custom Tabs return app links)
- Remove-branding initiate/verify
- Reviews CRUD with “edit resets to pending” documented in JSON (`status`)
- Photo moderation index/update/destroy
- `device_tokens` migration: `user_id`, `fcm_token`, `platform`, `last_seen_at`
- `POST/DELETE /api/v1/devices`
- Add `fcm` channel to existing notification classes (additive to `mail`)

**Done when:** bootstrap `/me` returns new preference keys; registering a device token is idempotent on `(user_id, fcm_token)`.

---

## Phase 1 — Android foundation + Guest MVP

Work in `EventHostAndriodApp`. Single module `:app`. Do not feature-modularize yet.

Each guest screen is the same loop: DTO → Retrofit method → repository → `ViewModel` + `StateFlow` → Compose. Cache-first only for the ticket wallet.

Blocked on API Slice A for steps 18–22; Slice B for steps 23–46. Steps 1–17 can start immediately.

### 1.1 Project conversion (parallel with API Slice A)

1. Raise `minSdk` from 24 to **26** in `app/build.gradle.kts`.
2. Enable `buildFeatures { compose = true }`; add Kotlin, Compose Compiler, Hilt, and KSP plugins.
3. Add to `gradle/libs.versions.toml`: Compose BOM, Material 3, Navigation Compose, Hilt, Retrofit, OkHttp, kotlinx.serialization (or Moshi), Room, DataStore, Coil, Coroutines, ML Kit barcode, `androidx.browser` (Custom Tabs), Firebase BOM (Crashlytics now; FCM in Phase 4).
4. Wire those aliases into `app/build.gradle.kts` `dependencies {}`.
5. Add `BuildConfig` field `API_BASE_URL` (debug vs release).
6. Create `@HiltAndroidApp` `EventHostApplication` and point the manifest `android:name` at it.
7. Create package folders: `di/`, `core/network/`, `core/auth/`, `core/ui/`, `data/`, `feature/discover|invitation|rsvp|tickets|contribute|gallery|auth/`.
8. Convert `MainActivity` to `ComponentActivity` + `@AndroidEntryPoint` + `setContent { EventHostApp() }`.
9. Delete `activity_main.xml` once nothing references it.
10. Add Material 3 theme (dynamic type). Format money as **ZMW**. English only.
11. Implement encrypted token DataStore (Keystore). Never plaintext `SharedPreferences`.
12. Implement OkHttp logging (debug) + `Authorization: Bearer` interceptor.
13. Implement OkHttp `Authenticator`: on **401** clear token and emit a session-expired event.
14. Provide Retrofit + OkHttp + JSON converter from a Hilt module.
15. Map HTTP errors in one place: 422 → field errors, 429 → throttle message, 401 → logout.
16. Leave extra permissions out of the manifest until a later step needs them.
17. **Checkpoint:** app launches Compose; a throwaway screen can `GET /api/v1/discover` and show event names.

### 1.2 Navigation + App Links

18. Add Navigation Compose `NavHost` with a public graph (discover as start) and placeholders for guest destinations.
19. If the launch `Intent` is an App Link, skip discover and open that destination.
20. Add verified `https` intent filters (production host, `autoVerify=true`) for:
    `/e/{slug}`, `/e/{slug}/tickets`, `/e/{slug}/contribute`, `/e/{slug}/gallery`,
    `/e/{slug}/table/{code}`, `/rsvp/{token}`, `/t/{token}`,
    `/tickets/orders/{orderReference}`, `/contributions/{reference}`.
    Add payment return URLs when Slice B defines them. Defer `/staff/invitations/{token}` to Phase 2/3.
21. Map each filter in `deepLinks { }`. Do **not** claim `/checkin/{staffToken}` or `/checkin/tickets/{staffToken}`.
22. **Checkpoint:** `adb shell am start -a android.intent.action.VIEW -d "https://<domain>/e/<slug>"` reaches the invitation route (screen can still be a stub until step 24). Confirm `assetlinks.json` matches the signing cert when you have one.

### 1.3 Guest screens

23. **Discover** — list UI from `GET /api/v1/discover`; tap opens invitation.
24. **Invitation shell** — load `GET /api/v1/events/{slug}` (merged customization). Native layout, **no Blade WebView**.
25. **Host bar** — logo, tagline, CTA; hide when `branding_removed`.
26. **Map** — pin from `latitude`/`longitude` (Maps SDK). No location permission.
27. **Add to calendar** — download `.ics` → `Intent.ACTION_VIEW`.
28. **Open RSVP** — form on `/e/{slug}/rsvp`: accept / decline / maybe, plus-one, guest count.
29. **Token RSVP** — same form on `/rsvp/{token}`.
30. Map Laravel `{ "errors": { field: [...] } }` onto the RSVP fields; honor `rsvp-submit` 429.
31. **My RSVP / entry pass** — persist by token; show server PNG QR (not a toast, not a client-generated QR).
32. **Tickets browse** — types and availability from `GET .../tickets`.
33. **Hold** — POST hold; start a countdown `Flow` from server TTL seconds.
34. If the hold expires mid-flow, block checkout and offer a new hold. Honor `ticket-hold` 429.
35. **Checkout** — POST initiate → Chrome Custom Tabs with Lenco URL (not WebView).
36. On App Link return, POST verify. Redirect is UX only; verify is source of truth.
37. **Order status** — persist `orderReference` locally; reopen `/tickets/orders/{ref}` without the original link.
38. **Ticket wallet Room** — entities/DAO for tickets the guest owns; upsert on successful verify and on `GET /t/{token}`.
39. Wallet list + detail: server PNG QR, PDF download, share sheet. Must open with airplane mode after a prior sync.
40. **Contribute** — show fixed amount; POST pledge (`contribution-checkout` throttle).
41. **Find pledge** — returning contributor enters the **same phone** (server `normalizePhone`); no guest account.
42. Installment pay: Custom Tabs + verify, same as tickets.
43. **Photo wall** — feed of `approved` photos only; Coil.
44. Add camera + photo-picker permissions (Android 13+ granular media, not `READ_EXTERNAL_STORAGE`).
45. **Table photo** — `PickVisualMedia` / `TakePicture` → downscale/compress → multipart with OkHttp progress → one-shot append.
46. **Phase 1 smoke** — shared invitation link → RSVP → hold/buy on staging Lenco → offline QR → contribution by phone.

**Phase 1 done when** step 46 passes on a device (or emulator + Custom Tabs).

---

## Phase 2 — Host core

### 2.1 Auth UX

- Login / register / forgot / reset screens against Slice A.
- Email verification: blocking banner + resend + pull-to-refresh `/me` (`email_verified_at`). Verification tap stays email-link (browser or App Link).
- 401 → clear datastore → login.

### 2.2 Host screens (in this order)

1. Dashboard stats.
2. Event list.
3. Create / edit (template picker). Surface **credit balance**; confirm dialog before publish/spend — same rule as web, server still enforces.
4. Lifecycle actions (publish, pause, resume, cancel, uncancel, restore) with explicit confirmations.
5. Host preview (unpublished / private).
6. **Invitation design** as a **linear step flow** (not the web panel layout): copy → palette (if flag) → media slots with upload-on-pick + per-file progress → review/save. Same slot replace vs append rules as web.
7. Guests list/add/edit/delete; groups; CSV via `ACTION_OPEN_DOCUMENT`; bulk send; export share sheet; guest QR PNG + sheet PDF.
8. Tables list + QR sheet.

**Done when:** a new host can register, create a draft, stage media, add guests, and publish (credit deducted once).

---

## Phase 3 — Check-in + host ticketing

This is the native justification for the app. Prioritize over billing.

1. ML Kit barcode scanning (camera permission; content description on shutter).
2. RSVP scan → lookup → confirm; manual name/phone lookup fallback. Confirm requires network; lookup should timeout cleanly and use Room cache when possible.
3. Ticket scan twin.
4. Staff **links**: generate, revoke, system share sheet (recipients use **browser**, not the app).
5. Staff **accounts**: invite email, `manager` / `checkin`, resend, revoke. Do not mix with staff links in one list without clear labels.
6. Ticket type CRUD + submit-for-review status.
7. Ticket management: list, resend, reissue, cancel, confirm-checkin, export.
8. Revenue: read-only.

TalkBack pass on the scan success/failure result before calling the phase done.

**Done when:** door staff with a host login can scan a guest QR and a ticket QR in a noisy/low-network setting without the UI hanging; shareable scanner links still open in Chrome for people without the app.

---

## Phase 4 — Remaining host + push

1. Settings: profile (400×400 crop before upload), security (password), notifications (`email_*` + `push_*`), account delete with consequences copy (not a one-line dialog).
2. Photo wall moderation.
3. Billing + remove-branding via Custom Tabs + verify.
4. Reviews: create; warn that edit → `pending`.
5. FCM: register token on login / refresh; `DELETE /devices` on logout; notification channels; runtime `POST_NOTIFICATIONS` on API 33+.
6. Firebase Crashlytics.

**Done when:** toggling push prefs changes whether a test RSVP notification is delivered; billing verify returns the user to the app.

---

## Phase 5 — Hardening and store

1. Room cache polish: ticket wallet, guest list, check-in lookup.
2. Deep-link matrix: every URL in 1.2, plus payment returns, plus “app not installed” still opens web.
3. Accessibility: icon-only controls, scan flow TalkBack, large font.
4. CI: GitHub Actions → `./gradlew lint test assembleRelease` (or bundle).
5. Play Console internal testing; App Signing fingerprint into `assetlinks.json`.
6. Recheck Play payments policy for tickets vs subscriptions.
7. Out of scope stay out: admin, iOS, i18n, Google Wallet, 2FA/social, offline event editing.

---

## Android architecture rules (do not drift)

| Rule | Practice |
|---|---|
| UDF | `ViewModel` exposes `StateFlow<UiState>`; Composables collect; user events go back as functions |
| DI | Hilt `@Inject` repositories; Retrofit/Room provided in modules |
| Mapping | DTOs in `data`; domain models if needed; never parse JSON in a Composable |
| Errors | Map HTTP 422 to field errors; 401 to logout; 429 (throttles) to a human message |
| Images | Coil only |
| QR show | Load server PNG |
| QR scan | ML Kit only |
| Pay | Custom Tabs + verify API |
| PDF | Download + `ACTION_VIEW` / `androidx.pdf` |

Suggested first tests: repository MockK + Turbine on hold countdown; one Compose test on RSVP form validation; one Espresso/Compose smoke: open discover.

---

## Suggested week-by-week order (one squad)

| Week | Work |
|---|---|
| 1 | Phase 0.1 + 0.2 (API auth, `/me`, discover, public event). Android 1.1 scaffold in parallel. |
| 2 | Phase 0.3 guest API. Android App Links + invitation + RSVP. |
| 3 | Tickets hold/checkout/wallet + contributions + gallery/table. Guest MVP internal. |
| 4 | Phase 0.4 host API + Android auth + dashboard + event CRUD. |
| 5 | Invitation step-flow + guests/tables. |
| 6 | Phase 0.5 + Android scan + staff + ticket ops. |
| 7 | Phase 0.6 + settings/billing/reviews/push. |
| 8 | Phase 5 hardening + internal track. |

Adjust if API and Android are different people: Android stays on 1.1–1.2 until Slice A is on staging.

---

## Do-not-build list (v1)

- Admin panel and admin models
- `CustomQuote` / Contact Sales
- 2FA, Socialite
- i18n
- iOS
- Google Wallet passes
- Offline create/edit event
- WebView wrapping of Blade invitations or Lenco checkout
- Client-side `SubscriptionTier` ranking
- Regenerating QR payloads on device
- App Links on public staff check-in URLs
