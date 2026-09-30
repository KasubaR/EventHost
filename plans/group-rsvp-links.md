# Group RSVP Links (shared link with a seat quota) + Host Contact Number

Status: **built** (2026-09-30); Android screens are still a follow-up in the other repo. Decisions below were confirmed in chat before
writing this file: the host approves every group RSVP, a full group shows a plain "full" message
(no waitlist) with the host's phone number, and **every** RSVP page shows the host's number.

## What this is

A host creates a **group** (e.g. "Committee") and gives it a **seat limit** (e.g. 10). The group gets
**one shared link**. Anyone with the link RSVPs **for themselves**; each accepted person becomes a
normal `Guest` in that group with their own RSVP, pass and QR. The host must **approve** each one,
and seats are counted against the group's pool. When the pool is full the link says so and shows the
host's phone number.

Separately, every RSVP page shows the host's contact number, and the host must enter it when the
event is created.

This is **not** the "household" idea (one answer, one QR, several people). That stays out of scope;
see "Out of scope".

## Decisions

1. **Approval: always the host.** A group RSVP is created in the existing pending state
   (`host_approval_status`), regardless of the event's `require_rsvp_approval` toggle. No code or
   password on the link.
2. **Full = plain message + host number.** No waitlist. Approving/rejecting frees or keeps seats as
   described below.
3. **Host number on every RSVP page**, and required when creating the event.
4. **Each person RSVPs separately.** One RSVP, one pass, one QR per person; check-in, tables and
   reminders keep working per person.

## Open questions - resolved (2026-09-30)

1. **Number:** a separate, required `events.host_contact_phone`, entered in the create-event wizard and
   prefilled from `users.phone`. The template's optional `contact_phone_*` fields are left alone.
2. **Plus-ones:** follow the event's existing setting (`allow_plus_one`, max 2). A group seat counts
   `attendee_count`.
3. **Pending holds seats:** yes. Rejecting releases them.

## Data model

**`guest_groups`** (today: `id, event_id, name`):
- `seat_limit` unsigned int, nullable. Null = an ordinary organising group with no link.
- `rsvp_token` string(48), nullable, unique. The link secret. Generated when the host turns the link on.
- `rsvp_link_closed_at` nullable timestamp. Host can close the link early or reopen it.

**`events`**:
- `host_contact_phone` string(40), nullable in the DB (existing events have none), **required by the
  create/update requests** for invitation-kind events going forward.

**`guests`**: no new column. Membership is already `guest_group_id`.

Seats used by a group = `SUM(rsvps.attendee_count)` over its guests where the RSVP is Accepted and
host approval is `pending` or `approved`. Derived live under a row lock, never stored, same
reasoning as contributions' `amount_paid` derivation in `ContributionRevenueAnalyticsService`.

## Routes (web, public)

- `GET  /g/{token}` — group RSVP page (`GroupRsvpController@show`)
- `POST /g/{token}` — submit (`@store`), inside the existing `throttle:rsvp-submit` group

Short prefix (`/g/`) so the link reads well in WhatsApp. The token is the group's `rsvp_token`, not
the event slug, so a leaked event slug does not open the group.

## Behaviour

**Page states** (resolved in one place, `GroupRsvpResolver`, modelled on `PublicInvitationResolver`):

| State | What the guest sees |
|---|---|
| Open, seats left | The RSVP form (name, email, phone, response, attendee count up to the cap) |
| Full | "This group is full." + host name + **call number** (tap-to-call) |
| Link closed / event ended / cancelled / unpublished | Existing `rsvp.closed` view, plus host number |
| Bad token | 404 |

**Submit** (`GroupRsvpService::submit()`, one transaction, row lock on the `guest_groups` row):
1. Re-check state and seats **inside the lock**. Two people taking the last seat must not both succeed.
2. `firstOrCreate` the guest on `(event_id, email)`, as `storeOpen()` does, but set `guest_group_id`
   and mint an `invitation_token` (they need a personal "view/change RSVP" link and pass).
3. If that email already belongs to a **different** group or is an existing guest with no group, do
   **not** silently move them: show "You're already on this event's list" with the host number.
4. Call `RsvpSubmissionService::submit()`, then force `host_approval_status = pending` for an
   Accepted response. Decline/Maybe are not gated (no seat, no pass), as in the approval plan.
5. Redirect to the existing pending-review page. Nothing is sent to the guest until approval.

**Approve / reject** reuse `GuestController::approveRsvp/rejectRsvp`, unchanged. Approving needs no seat
re-check (pending already holds them) and rejecting simply drops out of the sum.

**Capacity:** a group's seats also count towards the event's overall `guestCapacity()`. The check
reuses `Event::hasReachedGuestCapacity()`, so a group can never push the event over its cap.

## Host UI

- **Guest groups page**: per group, optional "Seats" number and a **Group link** panel: copy button,
  on/off (closes via `rsvp_link_closed_at`), and a live "6 of 10 seats taken · 2 awaiting approval".
- **Guest list**: filter by group already exists; add a group column badge for link sign-ups.
- Pending group RSVPs appear in the existing "Awaiting approval" chip. No new queue.
- **Create wizard**: a required "Your contact number" field on the details step, prefilled from the
  host's profile phone, with help text "Shown to guests on every RSVP page so they can call you."
  Also editable on the edit page.

## Host number on every RSVP page

A small shared partial, `rsvp/partials/host-contact.blade.php`, included by every guest-facing RSVP view:
`rsvp.show/token-show`, `rsvp.open-show`, `rsvp.closed`, `rsvp.thank-you`, the pending-review page and
the new group pages. It renders "Questions? Call {name} on {number}" with a `tel:` link, and renders
**nothing** when the number is empty (existing events), so there is never a dead "call null".

Add the number to the single source of truth for guest-facing copy, not per view: read it from one
`Event::hostContactPhone()` accessor that falls back to `contact_phone_primary`, then to
`users.phone`. That way old events with no number still show something sensible.

## API (Android, additive only)

- `GuestGroupResource`: add `seat_limit`, `seats_taken`, `seats_pending`, `rsvp_link` (null when off).
- Guest group create/update accept `seat_limit` and a link on/off flag.
- `EventResource` / event create+update: `host_contact_phone`.
- `RsvpResource`/guest resource: no change needed; group membership is already exposed.

The Android screens (group seats + link, contact number on event create) are a follow-up in the
other repo; the API lands first and stays backwards compatible.

## Security and abuse

- **Seats are the real limit.** A forwarded link can at worst create pending requests; the host
  approves each, and seats stop at the pool.
- Throttle: reuse `throttle:rsvp-submit`; add a per-token limiter so one link cannot be hammered.
- Pending-spam: the group form only offers "request seats" (no decline), and pending requests hold their
  seats, so at most `seat_limit` requests can ever exist. A separate cap is not needed.
- Token is 48 random chars, never the event slug; closing or regenerating it kills old links.
- One RSVP per email per event (existing unique index).
- Names and messages render with `{{ }}`, never `{!! !!}`.

## Privacy and copy

Collecting the host's number and publishing it on guest pages must be reflected in
`legal/privacy.blade.php` (host data shown to guests) and in the create wizard help text. Legal copy
is still unreviewed by a lawyer, as noted in `CLAUDE.md`.

## Build order

1. Migration + model: `guest_groups` columns, `events.host_contact_phone`, `Event::hostContactPhone()`.
2. Wizard/edit field, request validation (required for invitation kind), prefill from profile.
3. `rsvp/partials/host-contact.blade.php` and include it in every RSVP view.
4. `GroupRsvpResolver`, `GroupRsvpService`, `GroupRsvpController`, routes, views.
5. Host UI: seats + link panel on the groups page, live counts.
6. Approval integration and capacity check; pending-spam cap.
7. API resources and endpoints (additive), then docs in `CLAUDE.md`.
8. Tests.

## Tests

- Resolver states: open, full, closed, ended, cancelled, bad token.
- Concurrency: two submits racing for the last seat, exactly one succeeds.
- Pending holds seats; reject releases; approve never exceeds the pool.
- Existing email in another group / no group is not moved.
- Event capacity respected.
- Host number appears on every RSVP view and is absent, not broken, when empty.
- Create wizard rejects an invitation event with no contact number; ticketed events are unaffected.
- API fields are additive; existing API tests still pass untouched.

## Out of scope

- **Household RSVP** (one answer, one QR, several people) and a per-invitation party size beyond
  plus-one. Discussed separately; revisit after this ships.
- Waitlists.
- A join code or password on the link.
- Per-group RSVP deadlines or custom messages.
- Android screens.
