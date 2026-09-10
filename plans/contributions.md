# Event Contributions

Status: **Phases 1, 2 and 3 all built** (2026-09-10). See CLAUDE.md's "Event Contributions" section
for the condensed reference; this file keeps the fuller rationale.

## What this is

Some invitation-type events (weddings, funerals/memorials, baby showers, church events) ask each
guest to contribute a set amount of money toward the event. This feature lets an **admin** turn
that on for a specific event and set the fixed amount, and lets guests pay it — in one go or in
several installments — through the platform's existing Lenco integration (mobile money / bank
transfer), the same gateway already used for ticket sales and plan billing.

## Decisions locked in (confirmed with the user)

1. **Real payment**, not a display-only "here's our mobile money number" box. Guests pay in-app via
   Lenco and the platform tracks it, same posture as ticket checkout.
2. **Admin controls it per event**, not a platform-wide switch and not something the host sets on
   their own edit page. It lives on the existing admin per-event page
   ([app/Http/Controllers/Admin/EventController.php](app/Http/Controllers/Admin/EventController.php),
   `admin.events.show`) alongside the publish/pause/cancel controls already there.
3. **Fixed amount, not adjustable by the guest** — no "pay more" or "pay less." But a guest **can
   split it into installments**: multiple payments over time that sum to the fixed amount.

## Decisions made during Phase 1 build

- **Scoped to invitation-kind events** (`Event::isInvitation()`), gated on `product_kind` not
  `event_type` — a `corporate` *ticketed* event is excluded the same as a concert.
- **Fixed amount, installments allowed, no guest adjustment** — confirmed with the user: a
  contributor cannot pay more or less than the event's `contribution_amount` in total, but can
  split it across several payments over time.
- **No platform commission** — host gets the gross. Easy to add later following `TicketOrder`'s
  snapshot-fields pattern if it's ever wanted.
- **Payouts, built without a ledger table.** Ticketing needed `ticket_revenue_entries` because
  commission math has to be locked per-sale somewhere separate from the order, and because sales
  and payouts need to interleave in one ordered ledger with a running balance. Contributions have
  no commission, so `contribution_payments` (status `completed`) already *is* the append-only
  "money in" record — Phase 2 only added a "money out" table (`contribution_payouts`) and derives
  every balance live via SUM queries instead of maintaining a duplicate `balance_after` column.
- **Notifications gate on a new preference key, not a hardcoded "always send."** Rather than
  overloading an existing `notification_preferences` key (none of the five fit — "RSVP updates" and
  "Payment receipts" are both about something else), Phase 3 added `email_contribution_updates`,
  exactly the extension path `UpdateNotificationPreferencesRequest` and
  `ProfileService::updateNotificationPreferences()` were already built to accommodate.

## Data model

### `events` table — two new columns

- `contribution_enabled` (boolean, default false) — admin toggle, per event
- `contribution_amount` (decimal 10,2, nullable) — the fixed target per contributor, admin-set

Add both to `Event::$fillable` and `$casts`
([app/Models/Event.php](app/Models/Event.php)), plus a guard method
`Event::acceptsContributions(): bool` (enabled, amount set, invitation kind, published, not
cancelled) — same shape as the existing `isReviewable()` / `isTicketed()` helpers on that model.

### `event_contributions` — one row per contributor "pledge"

Tracks the running balance toward the fixed target. Mirrors `TicketOrder`'s role as the header row.

| column | notes |
|---|---|
| `event_id` | FK |
| `reference` | unique, `CTB-{eventId}-{timestamp}-{rand}` — same shape as `TicketOrder::generateReference()` |
| `contributor_name`, `contributor_phone`, `contributor_email` (nullable) | no login required, same as ticket buyers |
| `guest_id` (nullable FK → `guests`) | best-effort link if they arrived via their personal invitation link |
| `target_amount` | snapshot of `event.contribution_amount` at pledge creation — so an admin editing the amount later doesn't retroactively change a pledge already in progress, same reasoning `TicketOrder` gives for snapshotting commission fields |
| `amount_paid` | running total of **successful** installments only |
| `status` | `pending` / `partial` / `completed` (new `App\Enums\ContributionStatus`) |
| `completed_at` | nullable |

### `contribution_payments` — one row per installment attempt

Structurally a copy of `TicketPayment`
([app/Models/TicketPayment.php](app/Models/TicketPayment.php)): `provider`, `payment_method`,
`amount`, `currency`, `status` (pending/processing/completed/failed/cancelled),
`lenco_transaction_id`, `lenco_reference`, `lenco_status`, `lenco_response`, `payment_reference`,
`payment_instructions`, `bank_details`, `payment_url`, `expires_at`, `failure_reason`,
`failed_at`, `completed_at`, `cancelled_at`, `webhook_received`, `webhook_payload`,
`webhook_received_at`, `metadata`. Reusing the same shape means `LencoService` needs zero changes —
it already takes a generic `$context` array
([app/Services/LencoService.php](app/Services/LencoService.php):
`initiateMobileMoneyPayment`, `initiateBankTransfer`, `verifyByReference`).

## Admin side

- `admin/events/{event}` (`admin.events.show`) has a "Contribution" card: enable/disable toggle +
  amount field, amount required whenever enabling. `Admin\EventContributionController@update`
  (kept separate from the already-crowded `Admin\EventController`, same split as
  `TicketRevenueController` vs `TicketingController`), gated behind permission
  `events.contribution_manage` — `support` does not get it, same posture as
  `ticketing.payouts.manage`.
- Every change is logged via `AdminActivity::log()`.

## Guest-facing flow

- The public invitation page (`events/public.blade.php`) shows a banner (`.ctb-invite-banner`,
  `public/css/contributions.css`) linking to `/e/{slug}/contribute` when
  `$event->acceptsContributions()`.
- `events/contribute.blade.php`: guest enters name + phone (+ optional email) and how much they're
  paying now (defaults to the full amount). `ContributionCheckoutService::startOrResume()` finds an
  existing in-progress `EventContribution` for that event, matched by **normalized phone**
  (`EventContribution::normalizePhone()` — same digit-stripping order as the `ZambianPhoneNumber`
  rule, so `0977…`, `+260977…` and `260977…` all resolve to the same pledge), or creates one with
  `target_amount` snapshotted from the event.
- Payment method picker (mobile money / bank transfer) reuses the existing `.tkc-*` classes from
  `public/css/ticket-checkout.css` rather than inventing a new visual language —
  `contributions.css` only adds what's unique (amount banner, progress bar, history rows).
- `events/contribution-status.blade.php` at `/contributions/{reference}` (mirrors
  `/tickets/orders/{orderReference}`) shows amount paid so far, a progress bar, payment history, and
  — while not yet fully paid — a "pay the rest" form for the next installment. This is the link a
  guest bookmarks or gets texted to resume later; `contribution-status.js` polls
  `/contributions/{reference}/verify` while the latest installment is still in flight.
- No `TicketReservation`-equivalent hold step — contributions aren't inventory-limited, so there's
  nothing to reserve. A pledge already has an in-progress payment guards against a second
  concurrent installment (`ContributionCheckoutService::pay()` checks
  `ContributionPayment::scopeInProgress()` before starting a new one).

## Payment integration

- New `ContributionCheckoutService` (parallel to `TicketCheckoutService`) creates the
  `ContributionPayment` row and calls the existing `LencoService` methods unchanged.
- New `ContributionPaymentStatusService` (parallel to `TicketPaymentStatusService`): on a
  successful verify/webhook, locks the `EventContribution` row in a transaction, increments
  `amount_paid`, and flips status to `partial` or `completed`.
- `PaymentController::webhook()`
  ([app/Http/Controllers/PaymentController.php:335](app/Http/Controllers/PaymentController.php:335))
  already tries `Payment::findForLencoWebhook()` then `TicketPayment::findForLencoWebhook()`
  against the one shared webhook URL, with a comment explaining why it's one endpoint trying
  multiple tables. Add a third: `ContributionPayment::findForLencoWebhook()`, same pattern, same
  comment updated to mention three tables now.
- A `verify`/poll route mirrors `events.public.tickets.checkout.store` → `verify` for the case
  where the webhook is slow and the guest is sitting on the status page.

## Host-facing visibility

- Read-only "Contributions" card on the host's own `events/show.blade.php`, shown only when
  `$event->acceptsContributions()`: requested amount, pledge count, how many are fully paid, total
  collected. No host controls — the host can't turn it on/off or change the amount, matching the
  decision above.

## Payment integration — implementation notes

- `App\Jobs\RetryLencoContributionPayment` (twin of `RetryLencoTicketPayment`) retries a Lenco
  `initiate` call that failed transiently (network error / 5xx) during the first submit.
- Rate limiters `contribution-checkout` (5/min/IP) and `contribution-verify` (10/min/IP) in
  `AppServiceProvider`, same shape as the `ticket-*` limiters.
- `ContributionPaymentStatusService::creditContribution()` is the *only* place `amount_paid` moves,
  called from inside the same row-locked transaction that flips a `ContributionPayment` from
  non-terminal to `completed` — that transition happens exactly once per payment (every other path
  back into `applyVerificationResult()` hits the `isTerminal()` guard first), so there's no separate
  "fulfillment" queue to desync the way ticket issuance can.

## Money bookkeeping (Phase 2)

- `contribution_payouts` — admin-recorded disbursement, twin of `ticket_payouts` (`event_id`,
  `amount`, `currency`, `paid_on`, `note`, `paid_by`). `App\Models\ContributionPayout`, written only
  by `ContributionPayoutService::recordPayout()`, never edited or deleted.
- `App\Services\ContributionRevenueAnalyticsService` (read-only) — `platformSummary()`,
  `perEventBreakdown()`, `paymentsFor()`, `payoutsFor()`, `collectedFor()`, `balanceFor()`. Every
  figure is derived live from `contribution_payments`/`contribution_payouts`; there is no
  `balance_after`-style precomputed column to drift out of sync.
- `App\Services\ContributionPayoutService::recordPayout()` locks the event row, re-reads
  `balanceFor()` under that lock, and throws `ContributionPayoutExceedsBalanceException` if the
  amount is <= 0 or exceeds it — same shape as `TicketPayoutService::recordPayout()`.
- `admin/contributions/revenue` (index) and `admin/contributions/revenue/{event}` (show) —
  `Admin\ContributionRevenueController`, kept separate from `Admin\EventContributionController`
  (enable/amount) the same way `TicketRevenueController` is kept separate from `TicketingController`.
  Viewing needs permission `events.contribution_manage`; recording a payout needs the stronger
  `contributions.payouts.manage` (twin of `ticketing.view` vs `ticketing.payouts.manage`, though
  here both are admin-only — `support` gets neither).
- Nav link in the admin sidebar (`layouts/admin.blade.php`), gated on `events.contribution_manage`.
  A "View revenue & payouts" button also appears on the admin event show page's Contribution card
  once contributions are enabled, gated on `contributions.payouts.manage`.

## Notifications & export (Phase 3)

- `App\Notifications\ContributionReceiptNotification` — contributor-facing, on-demand (no user
  account to notify), sent via `Notification::route('mail', $contribution->contributor_email)`.
  Fires **per completed installment**, not just the final one, so a contributor paying in parts
  gets a receipt each time; silently skipped if they didn't give an email (that field is optional).
  Twin of `TicketOrderConfirmationNotification`.
- `App\Notifications\NewContributionReceivedNotification` — host-facing, same trigger point. Twin
  of `NewRsvpReceivedNotification`. Gated by the host's `email_contribution_updates` preference
  (new key in `User::DEFAULT_NOTIFICATION_PREFERENCES`, defaults `true`; toggle added to
  `resources/views/settings/partials/notifications-form.blade.php`, no other settings-flow changes
  needed since that form and its request already iterate every key in the const).
- Both are dispatched from `CommunicationService::sendContributionReceipt()` /
  `notifyHostNewContribution()`, called from `ContributionPaymentStatusService::creditContribution()`
  via `DB::afterCommit()` (same deferred-side-effect reasoning as ticket fulfillment) and wrapped in
  a try/catch that reports but never rethrows — a notification failure must never surface as a
  failed payment; the money already moved by the time this runs.
- CSV export of completed payments on the admin per-event revenue page
  (`admin.contributions.revenue.export` → `Admin\ContributionRevenueController::export()`), same
  `streamDownload()` + `fputcsv()` + `chunk(200)` pattern as the host-facing `events.tickets.export`.

## Test coverage

- `tests/Feature/EventContributionFlowTest.php` (Phase 1) — admin enable/amount (+ permission
  gate), 404 when not enabled, full-amount single payment, two-installment split, phone-based pledge
  resumption across differently-formatted numbers, over-the-remaining-balance rejection, and the
  host-facing summary card.
- `tests/Feature/AdminContributionRevenueTest.php` (Phase 2 + Phase 3 export) — support forbidden
  from viewing and recording; admin sees collected totals on both pages; recording a payout appears
  on both pages; a payout exceeding the pending balance is rejected; a second payout can't exceed
  what remains after the first; CSV export includes only completed payments with the right columns.
- `tests/Feature/ContributionNotificationsTest.php` (Phase 3) — contributor + host both notified on
  a completed payment; no receipt sent when the contributor gave no email (host still notified);
  host not notified when they've turned off `email_contribution_updates`.

## Phased build order

1. **Phase 1 — core collection. ✅ Built (2026-09-10).** Migrations (`events` columns,
   `event_contributions`, `contribution_payments`), `ContributionStatus` enum, `EventContribution` /
   `ContributionPayment` models, admin enable/amount UI + `events.contribution_manage` permission,
   guest contribute + installment flow, `ContributionCheckoutService` /
   `ContributionPaymentStatusService`, webhook wiring, host read-only summary card, feature tests.
2. **Phase 2 — money bookkeeping. ✅ Built (2026-09-10).** `contribution_payouts` table +
   `ContributionPayoutService` + `ContributionRevenueAnalyticsService` + admin "Contributions
   revenue" index/show pages + `contributions.payouts.manage` permission + nav link + feature tests.
   Contributions can now actually be *paid out* to a host, not just collected.
3. **Phase 3 — polish. ✅ Built (2026-09-10).** Contributor receipt notification per installment,
   host notification on each new contribution (gated by a new preference), CSV export on the admin
   revenue page, feature tests.

## Deploy status

All three phases' migrations are applied and `RolePermissionSeeder` re-seeded against the local
XAMPP MySQL database (2026-09-10) — this is live, not just test-covered. Nothing from the original
plan remains unbuilt; any further work here (e.g. SMS receipts, a host-facing payout history view)
would be a new phase, not a gap in this one.
