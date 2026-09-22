# Feature Plan: Private portal + Public portal

Status: **Phases 1–2 shipped** (2026-09-22). Phases 3–9 planned. Written 2026-09-22.

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

### Phase 3 — The two shells and routing
1. Middleware `EnsureEventAudience:private|public` on the host-side event routes. A private route hit for a
   public event (or vice versa) **redirects to the equivalent URL in the right portal** rather than 404ing.
2. Keep every existing `events.*` route name and `/events/...` URL as the **private** portal — 189 view
   references and the whole test suite keep working with zero renames.
3. Add the public portal under `/public-events/...`, names `public-events.*`. Re-home, don't rewrite:
   - `events.ticket-types.*`, `events.ticketing.*`, `events.tickets.*` (overview, revenue, payouts, export,
     resend/reissue/cancel/confirm-checkin, checkin), `events.staff.*`, `events.checkin.links.*` →
     `public-events.*`. Same controllers, new route names.
   - Old `/events/{event}/tickets...` etc. → 301 to the new path.
4. `layouts/app.blade.php`: portal switcher at the top of the sidebar, two nav sets (Private: Overview,
   My Events, Templates. Public: Overview, My Events, Tickets & Revenue, Check-in, Staff). Account section
   (Billing, Settings, Reviews) is identical in both. Any new `<nav>` needs the `.dash-nav`-style escape
   from `global.css`'s bare `nav {}` rule.
5. Dashboards: `/dashboard` becomes the private overview; `/public-dashboard` is new.
   `DashboardAnalyticsService` takes an audience so counts don't mix. Users with events in both see a
   chooser; users with one see only that portal by default.
6. `EventController@index` scopes by audience instead of relying on `?kind=`.
7. Private portal cleanup: remove the `is_public` checkbox from `events/partials/form-fields.blade.php`,
   drop the open-RSVP / discover wording, keep invite-only messaging.

### Phase 4 — Create flow
1. `/events/create` becomes a chooser: **Private event** vs **Public event**, each with its example types.
   Public then asks paid tickets vs free registration.
2. `StoreEventRequest` / `UpdateEventRequest`: take `audience`, validate the allowed pairs from §3, use the
   audience type list, and drop the `is_public` form rule. The `canMakeEventsPublic()` check moves out of the
   old checkbox path and into one create-time gate for free public events (see §6.3).
3. `TicketedEventCreator` sets `audience = public` explicitly. A new creator (or the existing invitation
   path) handles public + invitation.
4. Draft limit (`Event::MAX_OPEN_DRAFTS`) stays global across both portals.
5. When contributions are switched back on (§6.4), `Event::acceptsContributions()` also requires
   `isPrivate()`; hide the contribute banner and admin toggle for public events. Until then the global
   switch already hides them everywhere.

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
