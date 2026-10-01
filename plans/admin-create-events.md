# Admin: create and manage events on behalf of a client

## Status

**Built (Steps 0-7).** Switched off by default (`ADMIN_ACT_AS_ENABLED`). See "Admin acting as a client" in `CLAUDE.md` for how it behaves today.
Privacy page copy: done (follows the feature flag).

## Decisions (confirmed)

| Question | Answer |
|---|---|
| Owner | An **existing user account** — `events.user_id` is the client |
| Credits | **Deduct the client's credit** when publishing, exactly as today. Admin can grant credits first |
| Event kinds | **All three**: private invitation, ticketed, public free-registration |
| Depth | **Full create/edit as the client** — template picker, design, guests, media, tables |
| Payments | **Blocked while acting as a client**, always. The client pays themselves |
| Consent | **Client must request help first.** An admin can only act on an account with an open, approved help request |

## What we found

- Admins sign in on a **separate `admin` guard** (`admin.auth`, `routes/admin.php`); clients use the `web` guard.
- Every host screen and rule reads `$request->user()` / `auth()->id()`: `EventController` (list, store, credits),
  `EventInvitationDesignController`, staged media (`StagedMedia::scopeOwnedBy`), tier gates in
  `StoreEventRequest`, `EventPolicy` → `EventAccess::isOwner` (`$user->id === $event->user_id`).
- Rebuilding all of that as admin-side controllers means duplicating ~2,000 lines plus the wizard views, and the copies would drift.

## Recommended approach: "Acting as client" session

The admin opens a client and clicks **Create event for this client**. That starts a **scoped acting-as session**:
the `web` guard is logged in as the client while the admin's identity is kept in the session. The admin then uses the
**existing host screens unchanged**. Credits, tier gates, ownership and staged-media scoping all behave as the client's,
so "deduct the client's credit" holds automatically.

Not chosen: separate admin CRUD (large duplication), or creating the event as the admin and transferring it later
(breaks credit rules and staged-upload ownership).

## Steps

### Step 0 — Client help requests (consent gate)
1. Table `admin_help_requests`: `user_id`, optional `event_id`, `kind` (`create_event` | `edit_event` | `other`), `message`, `status`
   (`open` | `in_progress` | `completed` | `cancelled` | `expired`), `assigned_admin_id`, `access_expires_at`, timestamps.
2. Client side: a "Request our team's help" button on `/events` (create-for-me), on each event's edit page, and in Settings, opening a short form
   (what you need, preferred contact). One open request per user at a time. The client can **cancel it at any time**, which ends any live acting-as session.
3. Admin side: `/admin/help-requests` queue (permission `users.act_as`), with claim / complete / decline actions.
   Claiming sets `access_expires_at` (config, default 7 days) and emails the client "{Admin} is working on your request".
4. **Acting-as is only possible while the user has an in-progress request assigned to that admin and it has not expired**, scoped to the request's event when it has one.
   This replaces "any admin can act on any client".
5. Notifications follow from this: the client is told when their request is claimed and when it is completed. No per-action emails, since they asked for the help.
   Everything else stays in the audit log (Step 3), visible to the client on the request page as "what our team did".

### Step 1 — Acting-as foundation
1. Permission `users.act_as` (new) in the role seeder; **not** given to `support` (same posture as `events.contribution_manage`).
2. `Admin\ActingAsController`: `store` (`POST /admin/users/{user}/act-as`), `destroy` (`DELETE /admin/acting-as`).
   - Refuse unless an in-progress help request (Step 0) covers it; refuse for suspended/pending users and for the client if the target is another admin/staff identity.
   - On start: stash `acting_as.admin_id`, `acting_as.started_at`, `acting_as.return_url` in the session, call `Auth::guard('web')->login($user)`
     **without** touching `last_login_at`/`last_login_ip`, and regenerate the session id.
   - On stop: log the `web` guard out, clear the keys, redirect back to the client's admin page.
3. Auto-expiry: middleware ends the session after N minutes (config, default 60) and whenever the admin guard is no longer authenticated.
4. **Route isolation**: no admin-only actions are reachable while acting-as, and the admin cannot change the client's password, email, delete the account, or open
   `/settings/security` and `/settings/account` (a small `BlockWhileActingAs` middleware on those routes plus the payment-initiation routes).

### Step 2 — Visible guard rails
1. Persistent banner in `layouts/app.blade.php` and `layouts/site.blade.php`: "You are acting as {client}. Exit." (Exit button posts to `destroy`.)
2. Suppress side effects that would surprise the client while acting-as: no "new login" emails, no `last_login_*` writes,
   and `EmailChangedNotification` cannot fire (email change is blocked in Step 1.4).

### Step 3 — Audit trail
1. New `admin_activity_log` (or reuse an existing audit table if one exists — check first): `admin_id`, `user_id`, `event_id` (nullable), `action`, `ip`, `created_at`.
2. Log: session start/stop, event created, published, cancelled, deleted, credits spent, and any `EventController@store/update/publish/destroy` call made while acting-as.
   Implement with one middleware that tags mutating requests, not by editing each controller.
3. Show the log on the admin user page and on `admin/events/{event}` ("Created by admin {name} on behalf of client").
4. Add nullable `events.created_by_admin_id` (foreign key, `nullOnDelete`) set in `EventController::store()` when acting-as. Read-only provenance; nothing gates on it.

### Step 4 — Admin entry points
1. Admin **help request** page: "Start acting as client" (goes to `/events/create` or the event's edit page) replaces free-floating buttons on the user page, which only link to the client's open request, plus the client's credit balance and a
   "Grant credits" link that already exists, so the admin can top up before publishing.
2. Admin **event show** page: shows the linked help request, if any. "Edit as client" appears only when one covers that event.
3. Admin **events index**: filter "Created by admin".
4. No client picker is needed: the admin starts from the request, so the client is already known.

### Step 5 — Per-kind checks
For each kind, run the whole flow acting-as and fix anything that assumed a human client at the keyboard.
- **Private invitation**: create → choose template → design → guests → preview → publish (spends 1 client credit; blocked with the existing insufficient-credit message if 0).
- **Ticketed**: create → ticket types → submit for ticketing review. The admin then approves it from the existing `admin.ticketing` queue,
  so the acting-as session ends first. Payout details are entered by the client or by the admin acting-as; the admin's own approval stays on the admin guard.
- **Public free-registration**: create → submit for review → admin approves and sets the quote in `admin/events/show` → client pays (`/events/{event}/public-registration/pay`).
  The payment step is blocked while acting-as (Step 1.4); notify the client by email that it is ready, which `PublicRegistrationApprovedNotification` already does.
- Subscription-tier gates (free registration needs Base+, Pro+ palette and reminders) apply to the **client's** tier, so an admin cannot bypass them without changing the tier first (existing `PATCH /admin/users/{user}/tier`).
  Mention this in the UI when a gate blocks the admin.

### Step 6 — Hand-off
1. Completing the request emails the client (`HelpRequestCompletedNotification`: what was done, event link, what to do next).
2. Client sees a small "Set up by our team" badge on that event, driven by `created_by_admin_id`.

### Step 7 — Tests and docs
1. Feature tests: acting-as start/stop, permission denied for `support`, suspended client refused, banner present, expiry, blocked routes return 403,
   audit rows written, event owner is the client, credit deducted from the client (not the admin), `last_login_at` unchanged, each of the three kinds creatable.
2. Update `CLAUDE.md` (new "Admin acting as a client" section) and run `./vendor/bin/pint` and `composer test`.

## Risks and open items

- **Security**: acting-as is powerful. Mitigations are the dedicated permission, blocked credential/payment routes, expiry, banner and audit log.
  A super-admin-only toggle (`ADMIN_ACT_AS_ENABLED`) is worth adding so it can be switched off in production.
- **Session handling**: logging the `web` guard in while `admin` is live must not log out the admin guard; verify `session()->regenerate()` keeps both.
- **Client privacy**: Privacy page §2/§7 should say our team only acts on an account after the client's request, and that the client can cancel it. Copy is unreviewed by a lawyer.
- **Mobile/API**: Sanctum tokens are untouched; acting-as exists on the web only.

## Suggested order

Steps 0 → 1 → 2 → 3 → 4 first (usable end to end for invitation events), then 5 and 6, with 7 alongside each step.
