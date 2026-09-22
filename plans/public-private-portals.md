# Feature Plan: Private portal + Public portal

Status: **Phases 1–4 and 4c shipped** (2026-09-22). Phases 5–9 planned — see each phase's own notes. Written
2026-09-22.

Split the organizer side of EventHost into two portals:

| Portal | For | Attendee gets in by |
|---|---|---|
| **Private** (today's portal) | A specific invited group — the organizer controls who attends | Personal invitation link (`/rsvp/{token}`) |
| **Public** (new) | A broad audience who discover, register or buy tickets | `/discover`, `/e/{slug}`, ticket checkout or open registration |

This **reverses** `plans/ticketing.md` §0 ("Do not create a second organizer portal"). That decision is
superseded by this plan; update §0 when Phase 3 ships.

---

## 1. What already exists (why this is smaller than it looks)

- `events.product_kind` (`invitation` | `ticketed`) is already fixed at creation and already splits the
  create flow, event types, wizard and dashboards. It is the mechanism axis.
- Ticketed events are **already always public** (`TicketedEventCreator` hardcodes `is_public = true`).
- Invitation events are private by default; ticking `is_public` (Base tier+) turns them into open-RSVP
  events listed on `/discover`. That checkbox is the only thing that lets a "private-portal" event be public.
- `/discover`, the homepage strip and `Event::scopePubliclyListed()` already list public events only.
- `events.index` already accepts `?kind=`. `EventController@index` is the natural place to scope per portal.
- Local data: 2 events, both `product_kind = invitation`, both `is_public = 1` (an exhibition and a comedy
  show). They are exactly the rows that must move to the public portal.

What does **not** exist and must be built: an explicit audience concept, a second organizer shell, a
free-registration path that isn't a wedding-style invitation, the two event-type taxonomies, and the
redirects that keep old host URLs working.

## 2. Locked scope

- **One codebase, one login, one account.** "Portal" = a separate organizer workspace (own sidebar, own
  dashboard, own URL prefix, own event list, own create wizard). Not a separate app, database or domain.
- Billing, credits, settings and reviews are **shared** by both portals.
- Attendee-facing URLs (`/e/{slug}`, `/rsvp/{token}`, `/t/{token}`, `/checkin/...`, `/contributions/...`)
  **do not move.** They are in sent emails, printed QR codes and WhatsApp messages.
- Only host-side URLs move, and each gets a 301.

## 3. Model — the rule everything else follows

New column `events.audience` (`private` | `public`), set at creation, **immutable** (same reasoning as
`product_kind`: changing it would teleport an event between portals and orphan its data).

| audience | product_kind | Meaning |
|---|---|---|
| private | invitation | Today's flow. Invite-token access only. `is_public = false` always |
| public | ticketed | Paid tickets (unchanged pipeline, admin approval, commission) |
| public | invitation | **Free registration** — open RSVP, no payment. This is what the two local events are |
| private | ticketed | **Not allowed** |

`is_public` stays as a column and is **derived**: written from `audience` in exactly one place (model
`saving` hook), never from a form again. Every existing reader (`PublicInvitationResolver`,
`scopePubliclyListed`, reminders, API resources, notifications) keeps working untouched. Dropping the
column is a separate, later cleanup.

Backfill: `audience = 'public'` when `product_kind = 'ticketed' OR is_public = 1`, else `'private'`.

## 4. Event types

The example lists in the original brief (wedding … funeral; concert … awards) are **illustrations of the
two audiences, not the set of allowed types.**

**Private — types come from the templates.** A private event's type must match a template category, so
the template library is the single source of truth. Today's seven `invitation_template_categories`
(seeded in `InvitationTemplateSeeder`) are exactly today's seven `INVITATION_EVENT_TYPES`:

| Template category slug | Event type key |
|---|---|
| wedding | wedding |
| birthday | birthday |
| graduation | graduation |
| corporate | corporate |
| baby-shower | baby_shower |
| funeral-memorial | funeral (label "Memorial") |
| church | church |

- The private type dropdown is built from active template categories, not from a hardcoded constant. A new
  category added in admin (`/admin/templates`) becomes a selectable private type once it has at least one
  active template — you cannot pick a type nobody has designed an invitation for.
- Category slugs use hyphens and event types use underscores, so a small explicit slug → type map lives
  beside the categories. It is a map, not string munging, so `funeral-memorial → funeral` stays readable.
- Because `choose-template` already filters by category, the create flow can pre-filter templates to the
  chosen type with no new query.
- Existing rows keep working: `eventTypesFor()` keeps its `includeCurrent` grandfathering.
- v1 therefore ships **seven** private types. Anniversary and kitchen party arrive when their templates do;
  private dinner and AGM/EGM are out of scope.
- A private type with **no** active template is refused at create time with a clear message, rather than
  letting the host reach an empty template step.

**Public — no templates involved** (ticketed events render one fixed landing page), so there is no
template constraint. v1 keeps today's ten `TICKETED_EVENT_TYPES` (concert, conference, festival, party,
sports, comedy, exhibition, workshop, corporate, other). Extending that list is a plain constant + label
change — see open question 6.

---

## 5. Phases

Each phase ships on its own and leaves the app working. Phases 1–2 change no visible behaviour.

### Phase 0 — Decisions and safety net
1. Confirm the still-open items in §6 (public type list, publishing credit, free-public tier gate).
2. Dump `event_host` (and note which MariaDB — 12.3 on 3306 — is the live one) before any migration.
3. Add `config('portals.enabled')` so phases 3–6 can be merged dark and switched on together.

### Phase 1 — Audience in the data model (no UI change) — SHIPPED 2026-09-22
1. `App\Enums\EventAudience` (`Private`, `Public`) with `label()` and `derive(kind, isPublic)`.
2. Migration `2026_09_22_120000_add_audience_to_events_table`: `events.audience` (string 16, indexed,
   default `'private'`), backfilled in SQL including soft-deleted rows; idempotent, `down()` verified.
3. `Event`: enum cast, `audience` fillable, `isPrivate()` / `isPublicAudience()`, `scopeForAudience()`,
   and a `saving` hook that first forces every ticketed event to `is_public = true` / `audience = public`
   (fixed after review — the first cut only enforced this when `audience` was assigned explicitly).
   `EventFactory` gained `privateAudience()` / `publicAudience()` states.
4. Tests: `tests/Feature/EventAudienceTest.php` (derive matrix, hook both directions, private+ticketed
   rejected, scope partition, agreement with `scopePubliclyListed()`).
5. **Correction to the original design.** This phase said the hook would set `is_public` *from* `audience`.
   That would silently break the still-live "Public invitation" checkbox and admin ticket approval, which
   both write `is_public` and know nothing about `audience`. As built, the hook works in both directions:
   legacy writers (no `audience` assigned) → `audience` is derived from `product_kind` + `is_public`; an
   explicitly assigned `audience` wins and drives `is_public`. **Immutability is therefore not enforced yet**
   — toggling the checkbox can still move an event. Phase 4 removes the checkbox, after which `audience`
   is the only writer and can be locked at creation.
6. Local backfill result: both existing events (exhibition, comedy) → `public`, as predicted.

### Phase 2 — Taxonomies — SHIPPED 2026-09-22
1. `Event::privateEventTypes()` derives the private list from `InvitationTemplateCategory` rows that have at
   least one active template (categories carry no active flag of their own), mapped through the new
   `CATEGORY_SLUG_TO_TYPE` constant, falling back to `INVITATION_EVENT_TYPES` if the query is empty.
2. `Event::PUBLIC_EVENT_TYPES` added as a copy of `TICKETED_EVENT_TYPES` (the audience-facing name; the
   ticketed-facing name is kept too, both point at one array literal so they cannot drift).
3. `eventTypesFor()`'s Invitation branch now calls `privateEventTypes()` instead of reading the static
   constant; the Ticketed branch reads `PUBLIC_EVENT_TYPES`. Verified byte-identical output against the
   live database for both branches before and after.
4. **Deviation from the original plan:** `eventTypesFor()`'s signature is **not** changed to take
   `EventAudience`. Nothing yet has an audience to pass — it doesn't enter the create/update forms until
   Phase 4 — so adding the parameter now would only be unused. Phase 4 adds it when a real caller exists.
5. `AdminAnalyticsService` needed **no change**: it groups by whatever `event_type` values are actually in
   the database and looks up `TYPE_LABELS`, it never iterates `EVENT_TYPES` itself. Confirmed by reading it,
   not assumed.
6. Tests: `tests/Feature/EventTypeTaxonomyTest.php` (10 tests) — output matches the old constants exactly;
   deactivating a category's only template removes it and a new active template restores it; an unmapped
   category slug is skipped, not guessed; the map still has all 7 current slugs; the empty-query fallback;
   `$includeCurrent` grandfathering still works through the new template-derived list.

### Phase 3 — The two shells and routing — SHIPPED 2026-09-22

**Shipped:**
1. `layouts/app.blade.php`: a `.dash-portal-switch` toggle (Private | Public) above the nav, and the nav
   itself renders one of two sections depending on the current route — Private: Overview, Billing, My
   Events, Templates. Public: Overview, Billing, My Events. Neither tab is forced active on a page shared by
   both (Billing, Settings, Reviews) — that's an honest reflection of those pages belonging to neither
   portal. Uses the `.dash-nav`-style reset for the bare `nav {}` rule in `global.css`, per its own comment.
2. `/dashboard` (`events.*`-adjacent) is now the **private** overview: `DashboardAnalyticsService::forUser()`
   gained an optional trailing `?EventAudience $audience` (default `null` = every owned event, unfiltered —
   additive, so the Android API's own dashboard controller, the only other caller, is untouched) and
   `DashboardController::index()` passes `EventAudience::Private`.
3. `/public-dashboard` is new (`DashboardController::publicOverview()`) — commerce-shaped, not a filtered
   copy of the private one. See `PublicDashboardAnalyticsService`'s own docblock for why: ticketed events
   have no Guest/RSVP rows at all, so the private dashboard's whole shape (RSVP chart, guest groups, daily
   RSVPs) doesn't fit. Shows event counts (ticketed vs free-registration), tickets sold, checked-in,
   and gross/host revenue via a new `TicketRevenueLedgerService::summaryForEventIds()` (`summaryFor()` is
   now a one-id call to it). Also carries the "events you staff on" list, moved here from the private
   dashboard — staff access is ticketed-only (always public audience).
4. `events.index` and a new `public-events.index` both hit `EventController::index()`, which now takes
   `$audience` from a route default (`->defaults('audience', ...)`, read back explicitly with
   `EventAudience::from()`, not implicit enum route-model-binding) instead of the old `?kind=` query filter.
   The two portals are genuinely separate lists now — a ticketed event never appears on `/events`. The old
   in-page Invitation/Ticketed filter tabs are gone (private is invitation-only by construction, so they'd
   always show one empty tab); a public-portal filter is deferred (see below).
   `resources/views/events/index.blade.php` (private) and the new `public-index.blade.php` (public) share
   `partials/my-events-groups.blade.php` for the published/drafts/deleted lists.
5. Every redirect and back-link that used to point at the single `events.index` now checks the event's own
   `audience` first: `EventController::destroy()`, `EventTicketingController::submit()`, the draft-limit
   guards in `create()`/`store()` (via `?kind=`/the validated `product_kind`, since audience isn't decided
   until the event exists), and the "All events" link on `events/edit.blade.php` / `events/show.blade.php`.
6. Tests: `tests/Feature/PublicPortalTest.php` (13 tests) plus updates to `EventManagementTest`,
   `AdminTicketedEventCreateTest`, `TicketingTest`, `EventStaffTest`, `PublicInvitationLifecycleTest`,
   `DashboardAnalyticsTest` wherever they asserted the old single-list behavior.

**Phase 3b — route re-homing (shipped 2026-09-22):**
- `events.ticket-types.*`, `events.ticketing.*`, `events.tickets.*` (including `events.tickets.checkin.*`)
  and `events.staff.*` moved to `/public-events/{event}/...` with matching `public-events.*` route names —
  confirmed ticketed-only first, by grepping every controller behind them for its own
  `abort_unless($event->isTicketed(), 404)`. Every `route()`/`redirect()->route()` call across the
  ticketing/staff/check-in controllers, views and the one notification that links to this family
  (`TicketingRejectedNotification`) was updated in the same pass — `routes/web.php`'s new block carries a
  comment naming the exact scope. Blade **view** identifiers (`view('events.tickets.index')`,
  `@include('events.tickets.partials.nav')`, …) were deliberately left alone — only the route layer moved,
  the view files still live at `resources/views/events/tickets/...` — this was the one mechanical trap in
  the rename: a first pass over-matched and renamed several of these to `public-events.tickets.*` too, which
  would have 404'd every affected page; caught by grepping `@include\(['"]public-events\.` after the fact,
  reverted.
- `EnsureEventAudience` middleware now exists (`audience:public` on the moved group) — pure defence in
  depth, confirmed via `route:list -v` that `SubstituteBindings` still runs before it so `$request->route('event')`
  is a bound model, not a raw string. It never actually fires today (ticketed ⇒ public per Phase 1's hook),
  same as originally predicted.
- **`events.checkin.links.*` did NOT move — correcting this plan's own earlier premise.** The "all
  ticketed-only already" claim above was checked against `EventStaffLinkController` specifically and turned
  out wrong: its `store`/`destroy` actions gate on `ownerHasPremiumEventTools()`, which is true for a
  ticketed event with approved sales **or** a Pro+ invitation event (private or free-registration) — and
  both `events/checkin/scan.blade.php` (private) and `events/tickets/checkin/scan.blade.php` (ticketed) post
  to the same two routes. Moving it would have broken the Private portal's own scanner-link feature, so it
  stays on `/events/{event}/checkin/links` for both audiences.
- 301 redirects were added for the family's **GET** routes only (bookmarks, the one emailed link) —
  `Route::redirect(..., 301)`, outside the auth group so a logged-out visitor lands on the new URL first
  instead of bouncing through login. State-changing verbs (store/update/destroy/resend/…) got no redirect:
  a stale form action only exists on a page left open across the exact deploy moment, 404s, and self-heals
  on refresh — the same scope-narrowing trade-off that pushed this whole pass to Phase 3b in the first place.
- Because of the above, the Public nav still has no "Tickets & Revenue / Check-in / Staff" items — those
  stay per-event pages reached from an event's own card/detail page, not portal-level pages, since nothing
  in this pass touched navigation.
- Item 7 (removing the "Public invitation" checkbox) **moved to Phase 4** on purpose — it's tightly coupled
  to the create/update form rework there, and removing it now with no replacement chooser would take away a
  Base+ host's only way to opt into open RSVP.

### Phase 4 — Create flow — SHIPPED 2026-09-22
1. `/events/create` is now a two-level chooser. Step 1: **Private event** vs **Public event** (each with
   illustrative example types, not a fixed list — see §4). Step 2, only for Public: **Ticketed** vs **Free
   registration** — the latter shown locked with a "Base" badge and an upgrade link for a `none`-tier host,
   exactly like the old checkbox's disabled state did. Private skips step 2 entirely (always Invitation).
   `EventController` gained `resolveCreateAudience()` alongside the existing `resolveCreateProductKind()`
   (now taking `?EventAudience` — Private short-circuits to Invitation). The old bare `?kind=ticketed` link
   (used by the public portal's own "New event" buttons, added in Phase 3) still works unchanged — ticketed
   unambiguously implies public, so it skips both chooser steps as before.
2. `StoreEventRequest` gained a required `audience` field, validated with a new `guardAudienceChoice()`
   (replacing `guardPublicVisibility()`): free-registration (public + invitation) needs Base+, exactly the
   old gate, just keyed on `audience` instead of a raw `is_public` boolean. `is_public` itself is no longer
   a rule at all — dropped from both `StoreEventRequest` and `UpdateEventRequest`, along with
   `UpdateEventRequest`'s whole `guardPublicVisibility()` method. Audience is therefore immutable in
   practice from this phase on: nothing in either form can write it after creation. Event types still key on
   `product_kind` alone (§4's own note that audience doesn't affect the type list held up — no signature
   change needed here, confirming Phase 2's deviation was correct).
3. **A private + ticketed submission is silently corrected, not rejected** — `TicketedEventCreator::create()`
   already force-overwrites `audience = public` unconditionally (matching its pre-existing, identical
   handling of `is_public` and every invitation-only field), so that combination can never actually reach
   `Event::save()`. This is a deliberate deviation from the plan's original wording ("validate the allowed
   pairs" suggested a hard reject): the wizard makes the conflict structurally unreachable through the UI,
   and the codebase's own established precedent for "field doesn't apply to this product kind" is silent
   override, not a validation error — matching that precedent won out over inventing a new one.
   `guardAudienceChoice()` only ever fires for the tier gate now.
4. **Backward compatibility for the Android app, discovered by the full test suite, not anticipated in the
   original plan:** `StoreEventRequest` is shared with `POST /api/v1/host/events`
   (`Api\V1\EventController::store()`), which predates `audience` and never sends one. Making it `required`
   outright broke 5 Android API tests. Fixed with `fillMissingAudience()` in `prepareForValidation()`: when
   the caller sent no valid `audience`, one is derived from `is_public` (missing → false) via the same
   `EventAudience::derive()` the model's own saving hook already uses — reproducing the Android app's exact
   prior behavior. A caller that does send a real `audience` (the web wizard) is untouched. No Android
   controller, route or response shape changed; Phase 7's additive-only rule holds.
5. The "How people join" readonly note (create + edit, `events/partials/form-fields.blade.php`) now
   summarizes **both** audience and product_kind together in one sentence (e.g. "Public — Ticketed, via
   EventHost checkout"), since both are chosen in the same wizard step now. `$audience` there falls back
   `$audience ?? $event?->audience ?? (isTicketed ? Public : Private)` so the admin's ticketed-only "create
   on behalf of a user" page (which passes neither `$audience` nor a real `$event`) still resolves correctly
   without needing its own change.
6. `TicketedEventCreator` sets `audience = public` explicitly (see item 3) — used by both the host's own
   create flow and the admin white-glove flow, so this one line covers both.
7. Draft limit (`Event::MAX_OPEN_DRAFTS`) stays global across both portals, per plan — but the two guards
   that redirect to "wherever the blocking draft probably is" (`create()`'s GET guard, `store()`'s POST
   guard) were upgraded from Phase 3's `product_kind`-based guess to reading `audience` directly (from the
   query hint or, in `store()`, the already-validated payload) — necessary now that `product_kind=invitation`
   alone no longer tells you which portal an event belongs to.
8. Item 5 in the original plan (contributions + `isPrivate()`) is **not yet done** — deferred until
   contributions are switched back on at all (§6.4); doing it now would be dead code with nothing to test
   against, since the global switch already hides contributions everywhere.
9. Tests: rewrote `PublicVisibilityPlanGateTest` end to end for the new chooser/audience flow (9 tests);
   updated `TicketingTest` (chooser-UI tests replaced, 6 store payloads gained `audience`),
   `EventManagementTest`, `AdminTicketedEventCreateTest`, `EventCreditTest`, `EventCreditLedgerTest`,
   `PublicInvitationLifecycleTest` wherever they posted to `events.store` directly.
10. **Bug fixed after initial ship (reported by the owner):** every "New event" button still linked to the
    bare/legacy chooser — the private dashboard and `/events` used `route('events.create')` with no params
    (showing the Private/Public picker even though the host was already in the private portal), and the
    public dashboard and `/public-events` used `?kind=ticketed` (locking straight into the ticketed form,
    never offering free registration). All 8 links across `dashboard.blade.php`, `public-dashboard.blade.php`,
    `events/index.blade.php` and `events/public-index.blade.php` now pass `?audience=private` or
    `?audience=public` respectively — private skips the chooser entirely (straight to the details form,
    Public never shown), public lands on the Ticketed vs Free registration step (Private never shown). The
    sidebar's own portal-detection also needed a matching fix (`onPublicCreateStep` in
    `layouts/app.blade.php`), since `events.create`'s route name is shared by both portals and can't tell
    them apart on its own — without it the switcher stayed on "Private" through the whole Public branch of
    the wizard. Covered by 4 new tests in `PublicPortalTest.php`; verified in the browser for both portals.

### Phase 4c — Free registration becomes admin-approved + quoted, not credit-based

**Revises a Phase 4 decision, at the owner's request (2026-09-22).** Phase 4 shipped free-registration
(public + invitation) events costing 1 event credit to publish, same as a private event. The owner then
asked to hide Billing from the Public portal's sidebar, since ticketed events already don't use credits
(commission-based, admin-approved) — but hiding it would strand a free-registration host with no in-portal
way to buy the credit they still needed. Resolving that: **free-registration events should also require
admin approval, and after approving, the admin sets a one-off price ("quote") for that specific event. The
host pays that quote to publish — no event credit, ever, for any public event.** This makes ticketed and
free-registration symmetric: both are public, both are admin-approved, neither uses the credit pool. Only
private (invitation) events use credits after this.

Deliberately staged rather than built in one pass (a payments + admin-approval + notifications feature is
too large to land safely in one sitting) — mirrors almost every mechanic 1:1 from the existing ticketed-event
approval pipeline (`TicketingStatus`, `TicketingActivationService`, `Admin\TicketingController`) and the
`remove_branding` payment special case (`PaymentController`, `PaymentCompletionService`), rather than
inventing new patterns, to keep the risk down.

**Step 1 — SHIPPED 2026-09-22 — Schema, model, submit/approve/reject mechanics (no payment, no UI yet):**
1. New enum `App\Enums\PublicRegistrationStatus`: `NotApplicable | Draft | PendingReview | Approved |
   Rejected` — same shape as `TicketingStatus`, deliberately not reusing that enum (its cases are
   conceptually ticket-specific and `TicketingActivationService` branches on ticket types/hero image, none
   of which apply here).
2. Migration adds to `events`: `public_registration_status` (string 20, default `not_applicable`, indexed),
   `public_registration_submitted_at`, `public_registration_reviewed_at`, `public_registration_reviewed_by`
   (FK admins), `public_registration_rejection_note` (text), `public_registration_quote_amount` (decimal
   10,2, nullable — set by the admin at approval), `public_registration_quote_paid_at`. No new table: the
   quote is one column on the event itself, not a reissuable `CustomQuote`-style row, because it's 1:1 per
   event and never renegotiated after payment — closer to `remove_branding`'s shape than Enterprise's.
3. `Event`: cast the new enum, a `isFreeRegistration(): bool` (`isPublicAudience() && isInvitation()`)
   helper, `canSubmitPublicRegistration()` / `publicRegistrationApproved()` gates, and default
   `public_registration_status` to `Draft` at creation for a free-registration event (`NotApplicable`
   otherwise) — mirrors how `ticketing_status` defaults today.
4. `PublicRegistrationService` (submit/approve/reject), mirroring `TicketingActivationService` exactly:
   `submit()` moves Draft/Rejected → PendingReview; `approve()` takes the admin-set quote amount and moves
   to Approved (does **not** auto-publish, unlike ticketed approval — there's a real amount owed first);
   `reject()` moves PendingReview → Rejected with a note.
5. `EventController::publish()` excludes free-registration the same way it already excludes ticketed
   (currently only checks `isTicketed()`) — no credit path for either public product kind.
6. Tests for the state machine and the publish exclusion.

**Step 2 — Payment (host pays the quote) — SHIPPED 2026-09-22:**
7. `plan_key = 'public_registration_quote'` as a **fourth** special case in `PaymentController::initiate()`
   / `PaymentCompletionService::complete()`/`reverse()`, alongside remove_branding/enterprise/normal
   plans — grants no credits, no tier, same posture as remove_branding. Price comes from the event's own
   `public_registration_quote_amount` (server-side, never client-supplied — `InitiatePaymentRequest`
   re-validates ownership and `awaitingPublicRegistrationPayment()`, and `initiate()` re-checks both again
   under a row lock, same double-check `remove_branding` already gets). On completion: `is_published =
   true`, `public_registration_quote_paid_at = now()`. On reversal: un-publish and clear the paid-at, same
   "money went back, undo the flag" reasoning `reverse()` already applies to remove_branding.
8. A dedicated host checkout page mirroring `RemoveBrandingController` exactly
   (`PublicRegistrationPaymentController`, `GET /events/{event}/public-registration/pay`,
   `billing/public-registration-checkout.blade.php`, `public/js/public-registration-checkout.js`) — not a
   card on the generic `billing/checkout.blade.php`, same reasoning as remove-branding: one line item, not a
   set of plans to compare. Redirects back to `events.show` if the event isn't currently approved-and-unpaid
   (draft/pending/rejected, or already paid).
9. `BillingPlan::labelForPlanKey()` and `PaymentReceiptNotification::toMail()` both needed a
   `public_registration_quote` branch — without the first, admin/receipt copy would have shown the raw
   plan_key string; without the second, a buyer would have gotten the generic "you now have N event
   credit(s)" line for a payment that granted none, the same gap `remove_branding` already had to close.
10. At ship time, the host-facing "submit for review" action didn't exist yet (`PublicRegistrationService::submit()`
    had no route/controller/view calling it) — **built as part of Step 3 below instead**, once it became
    clear the admin card's Decline action (PendingReview-only) would otherwise have nothing to ever act on.
11. Tests: `tests/Feature/PublicRegistrationPaymentTest.php` (12 tests) — checkout page access/redirects,
    `initiate()` validation (missing event, wrong owner, still-draft, already-paid), successful payment
    (publishes, no credits/tier touched), reversal (un-publishes, clears paid-at, no credit ledger row), and
    a regression guard on the edit-page CTA (see Step 3's own note on the same bug).

**Step 3 — Admin approval UI + notifications — SHIPPED 2026-09-22:**
12. Admin panel card on the event show page (twin of the existing Contribution admin card, gated behind a
    new `events.public_registration_manage` permission — `support` does not get it, same posture as
    `events.contribution_manage`/`ticketing.approve`) to set the quote amount and approve, or reject with a
    note — reusing `Admin\TicketingController`'s approve/reject form patterns. Approve accepts
    Draft/PendingReview/Approved(unpaid)/Rejected, same as `PublicRegistrationService::approve()`'s own
    activatable list; Decline only shows for PendingReview.
13. **Also built, once building the admin card exposed the gap item 10 flagged:** the host-facing
    "submit for review" action — `EventPublicRegistrationController::submit()`
    (`POST /events/{event}/public-registration/submit`), mirroring `EventTicketingController::submit()`
    exactly (same `publish`-ability authorization reasoning). Without it, an event could never reach
    `PendingReview` through the app, so the admin card's Decline action and the reject-notification's
    "resubmit" promise would both have been dead ends. `events/edit.blade.php`'s free-registration panel now
    shows "Submit for review" for Draft/Rejected, a pending-review message for PendingReview, and (unchanged
    from Step 2) the pay CTA once Approved.
14. `PublicRegistrationApprovedNotification` (quote amount + a pay link to the Step 2 checkout page) /
    `PublicRegistrationRejectedNotification` (the note + a link back to the edit page), mirroring the
    ticketing notification pair exactly — dispatched from `PublicRegistrationService::approve()`/`reject()`
    outside their DB transactions, same "notify only once committed" split `TicketingActivationService`
    already uses.
15. Tests: `tests/Feature/PublicRegistrationAdminTest.php` (17 tests) — host submit (draft → pending,
    rejected → pending and clears the note, refuses if already pending, non-owner 403s, 404s for a ticketed
    event), admin approve/reject (guest/support blocked, approve from pending or straight from draft, zero
    quote rejected, re-approving an unpaid approved event updates the quote, a paid event cannot be
    re-approved, reject requires pending review, both notifications sent), and admin card visibility
    (renders for a free-registration event, hidden for private events and for `support`).

**Step 4 — Wizard/copy cleanup:**
16. **Done as part of Step 1** (the stale copy would have directly contradicted the new publish() block, so
    it shipped alongside rather than waiting for Step 4): `events/create.blade.php`'s page subtitle, "Free
    registration" card hint and "Save draft" helper text; `events/edit.blade.php`'s save-bar message and its
    no-JS fallback "Publish" panel (informational only — no button, since there's nothing to submit to yet);
    `EventController::edit()`'s `$publishCostsCredit` now also excludes `isFreeRegistration()`. Verified in
    the browser: no page for a free-registration event mentions "event credit" any more. **Also done as part
    of Step 2** (2026-09-22): the same panel shows a real "Pay K{amount} to publish" action once approved.
    **Also done as part of Step 3** (2026-09-22): the same panel now shows a real "Submit for review" action
    instead of only text, and a pending-review / declined-with-note state.
17. **Done** (2026-09-22). Removed the "Billing" nav link from the Public portal's sidebar section in
    `layouts/app.blade.php` (`$inPublicPortal` branch) — neither ticketed nor free-registration ever needs
    the general plan-comparison page. The route itself, and every direct link to it, are untouched: the
    Enterprise custom-quote banner on `public-dashboard.blade.php` still links straight to
    `billing.show(['plan' => 'enterprise'])`, and remove-branding / public-registration payments always used
    their own dedicated checkout pages, never this nav entry. The Private portal keeps its "Billing" link —
    Base/Pro/Pro+ subscriptions are still sold there.

### Phase 5 — Public portal features
1. Public event page for free-registration events: today it reuses the wedding-style invitation renderer.
   Decide in Phase 0 whether v1 accepts that or gets a proper public-event layout (recommended: v1 accepts
   it, redesign is Phase 5b).
2. `/discover`: search, event-type filter, date filter, city/venue filter. Public-audience only.
3. Homepage strip: unchanged query (`scopePubliclyListed()`), which is already public-only.
4. Public + invitation events get guest-list style views (registrations) instead of an invite list; reuse
   `GuestController` with copy changes rather than a new controller.
5. Organizer public profile page (optional, later).

### Phase 6 — Migrate existing events
1. Data: the Phase 1 backfill already assigns audience. Manually verify the 2 local events land in Public.
2. Every host-facing email/notification/WhatsApp link that points at a moved URL: audit and repoint
   (`EventUpdatedNotification`, `RsvpReminderNotification`, staff invitation and ticketing-approval mails,
   `WhatsApp` invitation builder). The 301s are the safety net, not the plan.
3. Hosts who currently have a **private** invitation event with `is_public = 1` and real guests: show a
   one-time notice in the new portal explaining the event moved.

### Phase 7 — Android API (`/api/v1`)
1. **Additive only:** add `audience` to `EventResource`, `EventListResource`, `EventPreviewResource`.
   `product_kind` and `is_public` stay exactly as they are — the shipped app reads them.
2. Optional `?audience=` filter on `GET /api/v1/host/events`.
3. Public attendee endpoints (`/api/v1/events/{slug}`, rsvp, tickets) are untouched.

### Phase 8 — Admin panel
1. Event lists and analytics filter by audience; add it to the event detail page.
2. Ticketing approval, payouts and contribution admin stay as they are.
3. Confirm no admin permission needs to split — audience is not a permission boundary.

### Phase 9 — Copy, docs, rollout
1. Homepage sections and pricing cards: make sure no plan card promises a feature in the wrong portal
   (the CLAUDE.md note about staff accounts is the precedent).
2. Update `CLAUDE.md` (Routing, Subscription Tiers, Event Preview, new "Portals" section) and
   `plans/ticketing.md` §0.
3. Flip `portals.enabled`, run `composer test`, `./vendor/bin/pint`, manual pass through both portals.

---

## 6. Decisions

### Settled (2026-09-22)

1. **Private types for v1 are the seven template categories only** (§4). `anniversary` and `kitchen_party`
   are planned: the owner will add those templates later, and each becomes selectable automatically once
   its category has an active template. `private_dinner` and `agm_egm` are **dropped** from scope.
2. **Free public events use the existing open-RSVP path** (public + `invitation` kind). No price-0 tickets,
   no checkout change. Free tickets with QR/check-in are a possible later addition, not part of this plan.
3. **Public events need admin approval unless they are free.**
   - Paid public events = ticketed events, which **already** go through `TicketingStatus` submit → admin
     approve. Nothing new to build there.
   - Free public events (open RSVP) skip approval and publish the way an invitation event does today.
   - **Confirmed:** free public events still need **Base or above** (`canMakeEventsPublic()`), now enforced
     at create time instead of via the old checkbox, and they need **no admin approval**.
   - The owner plans further approval/gating rules later; Phase 4 keeps the gate in one method so they can
     be added without touching the create flow.
4. **Contributions are switched off everywhere for now** (confirmed 2026-09-22 — not just on public events).
   Built in Phase 1 as `config('events.contributions.enabled')` (env `CONTRIBUTIONS_ENABLED`, default
   `false`), read by `Event::acceptsContributions()`. Stored per-event settings are kept, and pledges already
   in flight can still be paid. When the switch is turned back on, contributions should be **private-events
   only** — Phase 4 adds the `isPrivate()` condition then.
5. **Host portal URL prefix is `/public-events/...`** (route names `public-events.*`), no subdomain.

### Still open

6. **Public type list** — default: keep today's ten for v1. Extend when named (each is one constant + one
   label).
7. **Publishing credit** — default: unchanged. Invitation events (both audiences) spend 1 credit,
   ticketed does not.

## 7. Risks

- **URL churn is the main risk**, not the schema. Mitigation is in §2 and Phase 3: attendee URLs frozen,
  host URLs redirect, private portal keeps every current route name.
- 71 files check `product_kind` / `isTicketed()` / `isInvitation()`. This plan deliberately leaves those
  checks alone and adds audience beside them, so no gate is rewritten.
- `is_public` write-path: after Phase 1 nothing else may assign it. Grep for assignments before merging.
- The Android app depends on `product_kind` and `is_public` — never remove or repurpose either.
