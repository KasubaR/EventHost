# Feature Plan: Staff Access (Phase 18)

Status: **Shipped** (2026-08-22). Hosts of **ticketed events** can invite people by email into one of two
roles — **Event Manager** or **Check-in Staff** — without sharing their own login. Invitation/RSVP events
have no staff surface at all (see §1) — that's a deliberate scope decision, not a gap. `EventStaffLink`
(Phase 17, bearer-token scanner links, no account) is unchanged, works on both event kinds, and stays the
no-login alternative.

## 1. Decisions taken

| Question | Decision |
|---|---|
| Which events can have staff? | **Ticketed events only.** `EventStaffController` 404s every action for `! $event->isTicketed()`, and the "Staff" button is hidden on invitation/RSVP events entirely |
| Does the invited person need an account already? | No — invite by email; if the address has no account, the invite link lets them set a password and land straight on the event |
| Who can invite / change roles / remove staff | **Owner only** — a Manager gets full ticketing reach but never access to the access list itself |
| Gated by subscription tier? | No (revised — see §7). Gated on ticketing approval instead, via `ownerHasPremiumEventTools()` — same gate as the check-in scanner. Inviting Check-in Staff to an event that can't use the scanner yet is still pointless, but the reason is approval status, not the owner's plan |
| Do staff see the event in their own dashboard? | Yes — a distinct "Events you're staff on" section on `/dashboard` and `/events`, separate from owned events |

## 2. Roles

- **Event Manager** — full ticketing reach: ticket types, ticket orders/management, ticketing settings,
  staff scanner links, check-in scanning. **Cannot** activate ticket sales, delete the event, or manage
  staff — those stay owner-only, billing/destructive actions.
- **Check-in Staff** — door scanning only (ticket check-in: scan, lookup, confirm). Nothing else.

Because staff rows can only ever exist on a ticketed event, `EventAccess::canManage()`/`canCheckIn()`
naturally resolve to "owner only" everywhere on an invitation/RSVP event — `staffRoleFor()` queries
`event_staff` scoped to that specific `event_id`, and no such row can exist there. That's why the shared
per-model policies below (`GuestPolicy`, `EventTablePolicy`, `EventPhotoPolicy`, `GuestGroupPolicy` — all
invitation-only concerns) were safe to point at `EventAccess` too, even though a Manager will never
actually touch them: there is exactly one enforcement point (`EventStaffController`'s `isTicketed()` guard),
not one per policy.

## 3. Authorization

Every event-scoped policy used to repeat `$user->id === $event->user_id` independently. That's now
centralized in `App\Support\EventAccess` (`isOwner`/`canManage`/`canCheckIn`), and every policy that
touches an event-owned model (`EventPolicy`, `EventPhotoPolicy`, `EventTablePolicy`, `GuestGroupPolicy`,
`GuestPolicy`, `EventStaffLinkPolicy`) calls into it instead. `EventPolicy` gained `checkIn` (door scanning,
`canCheckIn`) and `publish` (owner-only) as separate abilities from `update` (`canManage`) — `publish` used
to ride on `update`, which would have let a Manager spend the owner's event credits.

Staff management itself (`App\Policies\EventStaffPolicy`) is owner-only on every ability, including
`manage(User, Event)` — used to authorize the invite form and staff list before any `EventStaff` row
exists, the same shape `EventStaffLinkController` already used for its own create action.

## 4. Data model

One table, `event_staff` (`event_id`, `user_id` nullable, `role`, `email` snapshot, `invited_by`,
`invite_token`, `invite_expires_at`, `accepted_at`, unique on `event_id`+`email`). `role` is
`App\Enums\EventStaffRole` (`manager`|`checkin`). Deleting the row is the only "revoke" — no soft-revoke
state, matching how `EventStaffLink::destroy()` already worked.

`accepted_at` is the only thing `EventAccess` checks — even an invite to an *existing* account sets
`user_id` immediately (so the staff list shows who they are) but leaves `accepted_at` null until they
click the invite link. Otherwise inviting someone by an email you don't control would silently grant that
account access before they ever saw the invite.

## 5. Invite + accept flow

`EventStaffInviteNotification` is sent via on-demand routing (`Notification::route('mail', $email)`), not
to a `User` — most invitees have no account yet. Queued on `high`, same queue as
`WelcomeNotification`/`EmailChangedNotification`.

The accept link (`/staff/invitations/{token}`) branches on whether a `User` already exists for the invited
email:

- **No account** → renders a small "create your account" form (name + password; email is read-only,
  never taken from user input). On submit, the `User` is created with `email_verified_at` set immediately —
  the signed invite link is itself proof of mailbox ownership, so no separate verification email — and the
  session logs straight in.
- **Existing account** → redirects to `/staff/invitations/{token}/confirm`, which sits behind `auth`+
  `verified` middleware. An unauthenticated visitor gets Laravel's ordinary "log in, then come back"
  intended-URL redirect for free — no custom session juggling needed. Once authenticated,
  `EventStaffInvitationController::confirm()` 403s if the logged-in address doesn't match the invite
  (wrong-account guard) rather than silently attaching whoever is signed in.

## 6. Not done / deliberately out of scope for MVP

- Invitation/RSVP events have no staff at all — not a smaller version of this feature, just absent. If
  that changes later, the plumbing (`EventAccess`, the per-model policies) is already in place; only
  `EventStaffController`'s `isTicketed()` guard and the "Staff" button's visibility would need to move.
- No granular permission matrix (view/scan/manage-tickets/manage-refunds/view-revenue as separate toggles)
  — just the two roles, per the MVP note in the phase brief.
- A Manager cannot invite/remove staff, even though they can do almost everything else.

## 7. Revision: subscription tier dropped from the gate (2026-08-23)

Ticketed events don't need a subscription-tier gate at all — EventHost already earns a commission on
every ticket sold, once an event is approved. Gating check-in/staff behind a Pro+ plan on top of that
commission was double-charging for the same thing. `Event::ownerHasPremiumEventTools()` now branches:
ticketed events unlock on `ticketSalesAreApproved()` regardless of tier; invitation events (paid for via
event credits, not commission) still gate on the owner's subscription tier exactly as before. One method,
one branch — every call site (check-in controllers, `EventStaffController`, `EventStaffLinkController`,
the various "Pro" badges) inherited the correct behavior for free; only the ticketed-only UI copy and the
redirect target for an unapproved event (`events.ticket-types.index`, not `billing.show` — there's nothing
to buy) needed updating by hand. See `Event::ownerHasPremiumEventTools()` and `tests/Unit/Models/EventTest.php`.
