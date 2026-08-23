# Feature Plan: Paid ticketing

Status: **Phase 1 shipped** (schema, product kind, ticket types, admin
activation). **Phase 2 shipped** (§5) — reservations, checkout, Lenco
collections, order/ticket issuance, QR delivery. Check-in, host ticket
dashboard, and the revenue ledger (sale rows) shipped early — see
§5.9–§5.11. **Phase 19: ticket refunds are manual** — organizers refund
buyers off-platform; there is no in-app refund action, no Lenco reversal,
and no ledger refund rows. See §5.12. Payouts remain later — see §7.

One EventHost client portal. Ticket buyers never log in. EventHost/Lenco is the
only online payment path; organizers cannot configure alternative payment
methods on the EventHost ticket page.

---

## 0. Architecture

```
EVENTHOST
              ┌─────────────┴─────────────┐
              │                           │
        CLIENT PORTAL               PUBLIC WEBSITE
              │                           │
        Organizer                  Ticket buyer
              │                           │
       ┌──────┴──────┐              /e/{slug}
       │             │                   │
 Invitations     Ticketing            Checkout
  RSVP/Guests    Tickets/Orders        Lenco
                 Revenue                  │
                 Check-in                 ↓
                 Payouts              Ticket + QR
```

Do **not** create a second organizer portal. Ticketing is a module on the
existing dashboard (`layouts/app.blade.php`), the same way Guests and Check-in
already hang off an event.

Two user types:

| | Organizer | Buyer |
|---|---|---|
| Account | EventHost login | None |
| Surface | Client portal | `/e/{slug}` → checkout → ticket link |
| Pays | EventHost commission (see §2) | Ticket price (+ commission if they chose pass-through) |

---

## 1. Locked product decisions

| Question | Decision |
|---|---|
| RSVP vs tickets | **Separate products per event.** `product_kind`: `invitation` or `ticketed`. Chosen at create; cannot be changed. Existing events default to `invitation`. |
| Ticket types | Multiple named types per event (General / VIP / …), each with its own price and quantity |
| Buyer account | Not required. Name, phone, email at checkout |
| Payment provider | EventHost Payments, powered by Lenco. Organizer cannot pick or add methods |
| Currency | ZMW |
| Platform revenue | Configurable percentage (admin panel, default **5%**). Snapshotted on every order |
| Who pays the commission | Organizer chooses per event: **absorb** (deducted from host) or **pass-through** (added to buyer total) |
| Seat hold | 10 minutes while checkout is in progress |
| QR ticket | Issued only after Lenco payment is confirmed. Emailed to the buyer |
| Check-in | Extend the existing scanner to accept ticket QR tokens (Phase 3) |
| Refunds | **Manual / off-platform** (Phase 19). The organizer refunds the buyer themselves. No host Refund button, no Lenco reversal, no ledger refund rows. `TicketStatus::Refunded` / `TicketOrderStatus::Refunded` stay on the enums so a Lenco `refunded` webhook can freeze an order, and so any historical ticket row still refuses check-in. Nothing in the host dashboard writes those statuses. |
| Ticket transfer | Phase 2 |
| Seat selection | Phase 3 |
| Complimentary / door / cash | **Not in V1.** Off-platform comps are the organizer's problem; the site will not mark tickets paid by hand |
| Payouts | Manual. Admin records a payout on the date agreed with the organizer. No Lenco disbursement in V1 |
| Ticket sales go live | Organizer submits → **admin approves**. Approval publishes the public page **without spending an event credit** |
| Cancellation | Organizer pays EventHost a cancellation fee (admin-configurable %). Buyer money is refunded off-platform by the organizer, not through EventHost |
| Bypass | EventHost only commissions tickets sold on the platform. Terms prohibit directing buyers off-platform; the product does not try to stop cash-in-hand deals |
| Manual "mark as paid" | Never. Tickets are issued only by the payment-confirmed path |

### What the organizer controls

Ticket name, price, quantity, sales window, min/max per order, description,
image, ticket terms, commission mode (absorb vs pass-through).

### What the organizer does not control

Payment provider, platform commission %, payment verification, ticket issuance,
the transaction ledger, alternative payment instructions on the EventHost page.

Do **not** build a "payment methods" checklist (MTN number, bank, cash,
WhatsApp). The ticket page CTA is **Buy now**, not "contact organizer to pay".

---

## 2. Commercial rules (Phase 0.5)

### Commission

Admin sets `ticketing_commission_percent` (default `5.00`) under platform
settings. Label in the UI: **EventHost Ticketing Commission**, not "ticketing
fee".

Worked example, **absorb** (host pays the commission):

```
100 × K200          = K20,000  gross (buyer total)
EventHost 5%        = K 1,000  commission
Host                = K19,000  payable
```

Same tickets, **pass-through** (buyer pays the commission):

```
100 × K200          = K20,000  face value (host gets this)
EventHost 5%        = K 1,000  added at checkout
Buyer pays          = K21,000
```

Every `ticket_orders` row stores the percent **and** the mode used. Changing
the platform default from 5% to 7% must not rewrite old orders.

### When host revenue is payable

After the event, on a date agreed with the organizer (`events.agreed_payout_on`,
set by admin at or after approval). Finance then records a manual payout.
Pending vs available is a later dashboard; Phase 1 only stores the agreed date.

### Credits vs commission

Invitation events still spend 1 event credit to publish.

Ticketed events **do not**. Going live is admin activation. `EventController::publish`
and the edit-page "Save & publish" control must refuse ticketed events.

### Cash / door / comps

Out of scope for V1. No UI to issue a paid ticket without a Lenco
`completed` payment. Complimentary tickets are Phase 2 if we ever want them
on-ledger.

---

## 3. Data model

### `events` columns (Phase 1)

| column | type | notes |
|---|---|---|
| product_kind | string | `invitation` (default) \| `ticketed` |
| ticketing_status | string | `not_applicable` \| `draft` \| `pending_review` \| `approved` \| `rejected` |
| commission_mode | string, nullable | `absorb` \| `pass_through`; null on invitation events |
| ticketing_submitted_at | timestamp, nullable | |
| ticketing_reviewed_at | timestamp, nullable | |
| ticketing_reviewed_by | FK → admins, nullOnDelete | |
| ticketing_rejection_note | text, nullable | Shown to the organizer after reject |
| agreed_payout_on | date, nullable | Admin-set; organizer sees it read-only |

`online sales enabled` is derived: `product_kind = ticketed` AND
`ticketing_status = approved`. Do not store a separate boolean the organizer
can tick.

### `ticket_types` (Phase 1)

| column | type | notes |
|---|---|---|
| event_id | FK cascade | |
| name | string | |
| description | text, nullable | |
| price | decimal(12,2) | > 0; no free types in V1 |
| quantity | unsigned int, nullable | null = unlimited |
| sales_starts_at / sales_ends_at | datetime, nullable | |
| min_per_order | unsigned tinyint | default 1 |
| max_per_order | unsigned tinyint | default 10 |
| image_path | string, nullable | public disk |
| terms | text, nullable | |
| sort_order | unsigned smallint | default 0 |
| is_active | boolean | default true |
| timestamps | | |

`refund_policy` was on this table in Phase 1 and dropped in Phase 19
(`2026_08_17_150000_drop_refund_policy_from_ticket_types`). Refunds are
manual; there is no policy copy on the ticket type.

### Later tables (do not migrate until the phase that uses them)

Phase 2's four tables are specced in full in §5.1 below (columns, indexes,
FKs) now that the buy flow is being built. Quick summary:

**`ticket_reservations`** — 10-minute hold of `quantity` on a type during
checkout, keyed by an anonymous `cart_id` (uuid), not a user. Status
`held | converted | expired | released`. Capacity is
`quantity - (sold + held-unexpired)`.

**`ticket_orders`** + **`ticket_order_items`** — buyer identity, status
`pending_payment | payment_processing | paid | failed | refunded | cancelled
| expired` — `payment_processing` (Lenco actively collecting, e.g. buyer has
a mobile money prompt to approve) and `failed` (payment definitively didn't
go through, as opposed to `cancelled`) were added after the schema first
shipped, once the order needed to distinguish those from a bare
`pending_payment`. `partially_refunded` is deliberately **not** added —
ticket refunds are manual / off-platform (Phase 19), so an in-app
partial-refund status would be unused. `refunded` on the **order** is the
Lenco webhook freeze (`TicketPaymentStatusService`): if the provider
reports the collection refunded, the order is never re-issued. It is not
an organizer action. Money columns
(`face_value`, `commission_percent`, `commission_mode`, `commission_amount`,
`buyer_total`, `host_amount`, `currency`) on the order; one row per ticket
type + quantity line on the items table, price snapshotted per line. An
order was originally sketched as a single flat row, but a buyer choosing two
different ticket types in one purchase needs a line-item split — added here.

**`ticket_payments`** — Lenco collection row, **no `user_id`**. Do not reuse
`payments` (that table is hosts buying credits). Reuse `LencoService` + the
lock/idempotency pattern in `PaymentStatusService`, as a parallel
`TicketPaymentStatusService` rather than a shared abstraction — see §5.2.

**`tickets`** — one row per issued seat, created only after payment is
`completed`. `public_token` (secret ticket URL, same shape as
`Guest::invitation_token`). Check-in columns (`status`, `checked_in_at`,
`checked_in_by`) are **not** part of the Phase 2 migration — that's Phase 3's
column to add, per this file's own "do not migrate until the phase that uses
them" rule. Guest uniqueness is `(event_id, email)` so ticket holders are
**not** stuffed into `guests` for V1; the scanner grows a ticket-token path
instead.

**`ticket_revenue_entries`** — **shipped.** Append-only host ledger,
`$guarded = ['*']`, same shape as `CreditTransaction`
(`App\Services\TicketRevenueLedgerService` is the only writer, mirroring
`EventCreditService`). One row per **order**, not per ticket — `gross_amount`
/ `platform_fee` / `host_amount` are copied straight from the paid
`ticket_order`'s own `face_value`/`commission_amount`/`host_amount`, no
per-line proportional split (would just re-introduce the rounding question
already settled against for `ticket_order_items` — see §5's Phase 5 note).
`host_amount` is stored signed so `balance_after` can always be
`SUM(host_amount)` over prior rows without special-casing type once
payout rows exist. Written inside the same transaction that marks the
order `paid` (`TicketOrderFulfillmentService::complete()`), not as an
afterCommit side effect — this is financial history, not a best-effort
notification.
Only `sale` has a writer. Buyer refunds never post here — they happen
off-platform (Phase 19). `payout | payout_reversal | adjustment` are
**not** enum values yet — same "don't build ahead of the phase that uses
it" rule, applied to enum cases this time, not just tables. No `status`
column either: a correction is meant to be a new row, never an edit to an
existing one, matching `credit_transactions`, which has no status column
for the same reason.

**`ticket_payouts`** — admin-recorded disbursement: amount, date, note,
`paid_by`. Still Phase 4.

Platform settings keys (Phase 1):

| key | default | type |
|---|---|---|
| `ticketing_commission_percent` | `5.00` | string decimal |
| `ticketing_cancellation_fee_percent` | `0.00` | string decimal |

---

## 4. Organizer flow (Phase 1 ships this far)

1. Create event → **Invitation / RSVP** or **Ticketed event**
2. Ticketed: **no layout to choose.** Every ticketed event renders the same
   fixed public template (`events/tickets/landing.blade.php` +
   `events/tickets/partials/landing-content.blade.php`, styled with
   `ticket-event-public.css` and the buyer-facing `.tkc-*` classes already
   shared with the buy flow) — hero, about, ticket list, location/calendar,
   share. `EventChooseTemplateController` redirects ticketed events straight
   back to `events.edit`; `EventController::store()` sends them there
   directly instead of to `events.choose-template`. Guest-limit / plus-one /
   RSVP-deadline fields are hidden.
3. Add ticket types. Choose absorb vs pass-through. Payment provider and
   commission % are read-only.
4. Submit for activation (needs ≥ 1 active ticket type).
5. Wait. Rejection returns a note; they edit and resubmit.
6. Approval sets `ticketing_status = approved` and `is_published = true`
   (no credit spend). The public link is then the official sales channel.

Sidebar stays **My Events**. Ticketed events get a **Tickets** action on the
event show page instead of **Guests & RSVPs**. A global Ticketing / Revenue /
Payouts nav is unnecessary until those modules have their own index pages.

---

## 5. Buyer flow (Phase 2 — planned, not built)

```
Open /e/{slug} → pick type(s)/qty → POST hold → cart_id cookie
  → /tickets/checkout (name/phone/email) → POST → Lenco (mobile money/bank)
  → poll verify + webhook (either wins) → issue tickets → email QR + /t/{token}
```

No organizer payment instructions on that page. Contact details, if shown, are
for event questions only. A ticket is **VALID** only after EventHost has a
confirmed payment — nothing marks a ticket paid by hand (see §1). Door staff
scanning anything else reject (wired in Phase 3; this phase only issues the
token the scanner will later accept).

The whole slice deliberately mirrors two flows that already work in this
codebase rather than inventing a third pattern:

- **Checkout mechanics** ← `PaymentController`/`LencoService`/
  `PaymentStatusService`/`PaymentCompletionService` (event credit purchase).
- **No-login secret-link trust model** ← `Guest::invitation_token` /
  `rsvp.token.show` / `rsvp.token.entry-pass` (guest RSVP + entry pass).

Everywhere those two disagree slightly, the payments-domain choice wins for
money and the RSVP-domain choice wins for anonymous-identity/URL trust.

### 5.1 New tables

**`ticket_reservations`**

| column | type | notes |
|---|---|---|
| event_id | FK cascade | |
| ticket_type_id | FK cascade | |
| cart_id | string(36), indexed | uuid, set client-side via an httpOnly signed cookie (`eh_ticket_cart`); the only thing that lets an anonymous buyer reach their own checkout/held items — same trust level as a guest's `invitation_token` |
| quantity | unsigned int | |
| unit_price_snapshot | decimal(12,2) | locks in price at hold time so a host editing the price mid-hold can't change what's charged |
| status | string(20) | `held \| converted \| expired \| released` |
| expires_at | timestamp | `now()->addMinutes(10)` |
| ticket_order_id | FK nullable | set on convert |

Index: `(ticket_type_id, status, expires_at)` — this is the hot path for the
capacity query on every hold attempt and every public ticket-type listing.

**`ticket_orders`**

| column | type | notes |
|---|---|---|
| event_id | FK cascade | |
| order_reference | string, unique | `TKT-{event_id}-{timestamp}-{random}`, same shape as `LencoService::generatePaymentReference()` but buyer-agnostic (no user id to key off) |
| cart_id | string(36) | links back to the reservation batch |
| buyer_name / buyer_email / buyer_phone | string | |
| status | string(20) | `pending_payment \| paid \| refunded \| cancelled \| expired` |
| currency | string(3) | `ZMW` |
| face_value | decimal(12,2) | Σ (ticket_type.price × qty) |
| commission_percent | decimal(5,2) | snapshot from `TicketingSettings::commissionPercent()` |
| commission_mode | string(20) | snapshot from `event.commission_mode` |
| commission_amount | decimal(12,2) | |
| buyer_total | decimal(12,2) | what Lenco actually charges |
| host_amount | decimal(12,2) | face_value minus commission (absorb) or face_value (pass-through) |
| paid_at | timestamp, nullable | |
| expires_at | timestamp, nullable | mirrors the reservation window until payment is initiated |

Index: `(event_id, status)`.

**`ticket_order_items`**

| column | type | notes |
|---|---|---|
| ticket_order_id | FK cascade | |
| ticket_type_id | FK nullOnDelete | historical row must survive a type being deleted later |
| ticket_type_name | string | snapshot |
| unit_price | decimal(12,2) | snapshot |
| quantity | unsigned int | |
| subtotal | decimal(12,2) | |

**`ticket_payments`**

Same shape as `payments`, minus `user_id` / `plan_key` / `credits_*`, plus
`ticket_order_id` FK cascade. Columns: `provider`, `payment_method`
(`mobile_money`\|`bank_transfer`), `amount`, `currency`, `status` (`pending
\| processing \| completed \| failed \| cancelled \| refunded`),
`lenco_transaction_id`, `lenco_reference`, `lenco_status`, `lenco_response`
(json), `payment_reference` (unique), `payment_instructions`, `bank_details`
(json), `payment_url`, `expires_at`, `failure_reason`, `failed_at`,
`completed_at`, `cancelled_at`, `webhook_received` (bool), `webhook_payload`
(json), `webhook_received_at`, `metadata` (json).

**`tickets`**

| column | type | notes |
|---|---|---|
| ticket_order_id | FK cascade | |
| ticket_order_item_id | FK cascade | |
| event_id | FK cascade | denormalized, same reasoning as `ticket_types.event_id` — scanner/reporting queries shouldn't join through orders |
| ticket_type_id | FK nullOnDelete | for grouping/reporting only; name/price live on the order item |
| public_token | string(48), unique | `Str::random(48)`, same shape as `Guest::invitation_token`; retry-on-collision loop (2–3 attempts) around the unique constraint, which remains the real guard |
| attendee_name / attendee_email / attendee_phone | string, nullable | default to the buyer's identity; per-seat attendee names are **out of scope** for V1 — one buyer, N tickets, one email |
| price_paid | decimal(12,2) | snapshot from the order item |
| status | string(20) | `valid \| used \| refunded \| cancelled` — `refunded` is dormant (Phase 19); check-in columns shipped in §5.9 |
| issued_at | timestamp | |

Index: `event_id`; unique on `public_token`.

### 5.2 Services

- **`TicketReservationService`** — `hold()`, `release()`, `activeForCart()`.
  `hold()` locks each requested `TicketType` row (`lockForUpdate`, same idiom
  as `TicketingActivationService::approve` / `EventController::update`),
  computes `available = quantity - soldCount - heldUnexpiredCount` (`null`
  quantity = unlimited), enforces `min_per_order`/`max_per_order` and the
  sales window (`sales_starts_at`/`sales_ends_at` — unenforced today, this is
  the first thing that reads them), releases the cart's own prior unconverted
  holds before creating new ones (so changing quantities re-holds instead of
  stacking), and writes `expires_at = now()->addMinutes(10)`. The same
  capacity math backs the "N left" badge on the public ticket picker, so the
  display and the guard can't drift apart.
- **`TicketCheckoutService`** — given a cart's active holds + buyer details,
  computes the commission math (below), creates `TicketOrder` +
  `TicketOrderItem` rows inside a transaction with the reservations
  re-locked, then calls `LencoService::initiateMobileMoneyPayment()` /
  `initiateBankTransfer()` — the exact methods `PaymentController::initiate`
  already calls — and writes the resulting `TicketPayment`. Duplicate-in-
  progress guard is keyed on `cart_id`/order, not `user_id` (there is none).
- **`TicketPaymentStatusService`** — a parallel copy of
  `PaymentStatusService`, not a shared abstraction: same lock-then-branch
  shape (`completed` → fulfill, reversal statuses → reverse, terminal → no-
  op), but drives `TicketPayment`/`TicketOrder` and calls
  `TicketOrderFulfillmentService` instead of `PaymentCompletionService`. This
  codebase already keeps `Payment` (credits) and this domain (ticket
  revenue) as separate models per §3's explicit instruction not to reuse
  `payments` — the status service follows the same split for the same
  reason: the two domains' completion side-effects have nothing in common.
- **`TicketOrderFulfillmentService`** — `complete(TicketOrder $order)`:
  inside a transaction, re-locks the order, and if not already fulfilled,
  creates one `Ticket` row per unit across every `TicketOrderItem`
  (`public_token` generated with the collision-retry above), flips the
  matching `TicketReservation` rows to `converted`, sets
  `order.status = paid` + `paid_at`. After commit, sends a
  `TicketOrderConfirmationNotification` (mirrors `PaymentReceiptNotification`)
  to `buyer_email` with each ticket's `/t/{public_token}` link and a QR PNG
  attachment per ticket (`QrCodeService::png()`, same email-safe reasoning
  documented on that class already). Idempotency: a second `complete()` call
  on an already-paid order (duplicate webhook) is a no-op, same guard style
  as `PaymentCompletionService::complete()`'s `credits_fulfilled_at` check.

### 5.3 Routes (all public, no `auth` middleware)

| Method | Path | Name | Notes |
|---|---|---|---|
| GET | `/e/{slug}/tickets` | `events.public.tickets` | Ticket picker; 404 unless `event->ticketSalesAreApproved()` |
| POST | `/e/{slug}/tickets/hold` | `events.public.tickets.hold` | `throttle:ticket-hold`; sets `cart_id` cookie, redirects to checkout |
| GET | `/e/{slug}/tickets/checkout` | `events.public.tickets.checkout` | Buyer details form; expired-hold view if the cookie's cart has nothing active |
| POST | `/e/{slug}/tickets/checkout` | `events.public.tickets.checkout.store` | `throttle:ticket-checkout`; creates order + initiates Lenco |
| GET | `/tickets/orders/{order_reference}` | `ticket.orders.show` | Poll/status page, same UX as `billing.checkout`'s poll loop |
| GET | `/tickets/orders/{order_reference}/verify` | `ticket.orders.verify` | AJAX, mirrors `payment.verify.ref` |
| GET | `/t/{public_token}` | `tickets.show` | Buyer's secure ticket page, no login — same trust model as `rsvp.token.show` |
| GET | `/t/{public_token}/qr.svg` \| `.png` | `tickets.qr` | `QrCodeService`, same pattern as `events.guests.qr` / `rsvp.token.entry-pass` |

The existing single Lenco webhook route (`routes/web.php:39`) is **extended**,
not duplicated: it tries `Payment::findForLencoWebhook()` first, then
`TicketPayment::findForLencoWebhook()` (same three-key lookup: reference,
transaction id, Lenco reference). One webhook URL is registered with Lenco
today; ticket payments share that account, so this avoids asking to register
a second callback URL.

Rate limiters (`throttle:ticket-hold`, `throttle:ticket-checkout`) get
registered in `AppServiceProvider` next to `rsvp-submit` / `payment-initiate`
/ `table-upload`.

### 5.4 Commission math (implementation of §2, unchanged here)

```php
$faceValue = $items->sum(fn ($i) => $i->ticket_type->price * $i->quantity);
$commissionPercent = TicketingSettings::commissionPercent(); // snapshot now
$commissionAmount = round($faceValue * $commissionPercent / 100, 2);

if ($event->commission_mode === CommissionMode::Absorb) {
    $hostAmount = $faceValue - $commissionAmount;
    $buyerTotal = $faceValue;
} else { // PassThrough
    $hostAmount = $faceValue;
    $buyerTotal = $faceValue + $commissionAmount;
}
```

### 5.5 Jobs / scheduler

- **`ExpireTicketReservations`** — console command, scheduled every minute
  (`routes/console.php`, alongside `sanctum:prune-expired`), flips `held`
  rows past `expires_at` to `expired`. This is what actually frees capacity;
  `hold()`'s own query already excludes expired-but-unflipped rows defensively,
  but the sweep keeps the table from accumulating stale `held` rows forever.
- **`RetryLencoTicketPayment`** — twin of `RetryLencoPayment` for the same
  reason `TicketPaymentStatusService` is a twin of `PaymentStatusService`:
  queued when `LencoService::initiate*` 5xxs mid-checkout.

### 5.6 Views / assets

- `resources/views/events/tickets/purchase.blade.php`,
  `checkout.blade.php`, `order-status.blade.php` — public-facing, extend
  `layouts/site.blade.php` like `events/public.blade.php` does.
- `resources/views/tickets/show.blade.php` — the buyer's `/t/{token}` page.
- CSS: new `public/css/ticket-checkout.css`, pushed like every other
  page-specific file in the table in `CLAUDE.md`; reuse `events-public.css`
  for shared public-page chrome rather than duplicating it.
- JS: new `public/js/ticket-checkout.js` — quantity steppers, the hold
  countdown (`data-hold-expires-at`), and a poll-until-terminal loop. Read
  `public/js/billing-checkout.js` first and mirror its interval/backoff
  instead of reinventing one — it already solves this exact problem for
  `payment.initiate`/`payment.verify`.
- `events/public.blade.php` gains a **"Buy tickets"** CTA for ticketed
  events in place of the RSVP CTA — the two are mutually exclusive per
  `product_kind`, never both on one event.

### 5.7 Tests

- Full flow: hold → checkout → simulated Lenco `completed` (faked
  `LencoService`) → tickets issued, order `paid`, commission math correct
  under both `absorb` and `pass_through`, capacity decremented, reservations
  `converted`.
- Reservation expiry frees capacity for a second buyer.
- Oversell guard: two concurrent holds against the last available ticket —
  one succeeds, one is rejected; assert no oversell (`lockForUpdate` is what
  this test is actually proving).
- Webhook idempotency: firing the same `completed` webhook twice does not
  issue tickets twice.
- Public page gating: ticket picker only renders when
  `ticketSalesAreApproved()`; sold-out state when every type is at capacity;
  invitation events never render a ticket picker.

### 5.8 Explicitly out of scope for this phase

Per-seat attendee names, ticket transfer, payouts — later phases per §7.
Ticket refunds are **not** a later in-app phase: they are manual /
off-platform (Phase 19, §5.12). This phase's job is: money in, tickets
out, buyer can see and re-open their own ticket.

### 5.9 Check-in scanner (Phase 3, shipped early)

`TicketCheckInService`/`TicketCheckInController` — parallel to
`CheckInService`/`CheckInController` (guests), not a shared abstraction, same
reasoning as §5.2's other "parallel, not shared" services. Differences worth
recording:

- A ticket's own QR encodes its **public buyer page** (`tickets.show`,
  `/t/{public_token}`), not a check-in endpoint — unlike `Guest::checkInQrUrl()`,
  which points straight at the confirm route. So there is no
  `openFromCamera`-style safety net to build: opening a ticket's QR with a
  phone camera just shows the buyer their own ticket, harmlessly.
- The check-in gate **is** `Ticket::status` — confirming flips
  `valid → used` (plus `checked_in_at`/`checked_in_by`, same columns as
  `guests`) rather than adding a second nullable timestamp next to an
  unrelated status column. `cancelled` tickets are refused
  (`TicketCheckInException`). A dormant `refunded` status (nothing writes
  it from the host dashboard) is refused with the same cancelled message,
  so a historical row cannot be scanned in. An already-`used` ticket is
  **not** an error — same idempotent-rescan behavior as a guest's second
  scan, returned as `already_checked_in: true`.
- Gated on `Event::isCheckInOpen()` (same-day) and
  `ownerHasPremiumEventTools()` — the same method guest check-in calls, but
  no longer identical rules underneath: as of Phase 23,
  `ownerHasPremiumEventTools()` unlocks a ticketed event on ticketing
  approval (`ticketSalesAreApproved()`), not the owner's subscription tier —
  EventHost already earns a commission on every ticket sold, so a tier gate
  on top of that would double-charge for the same thing. Guest (invitation)
  check-in is unaffected and still gates on tier. See §2 and
  `plans/staff-access.md` §7.
- **Dashboard scanner only.** No shareable no-login staff-link variant yet
  (`EventStaffLink`'s equivalent for tickets) — a real gap for door staff
  without dashboard accounts, deliberately deferred rather than mirroring
  guest check-in's full surface area on day one. Add it the same way
  `PublicCheckInController` was added for guests, if/when needed.
- Dedicated views/JS (`events/tickets/checkin/*`, `ticket-checkin-scanner.js`)
  rather than reusing `events/checkin/*` — the result fields differ (ticket
  type / order reference vs. table / meal preference) and duplicating the
  ~300-line camera-scan widget was judged safer than parameterizing the
  guest one and risking that already-shipped, tested flow.

### 5.10 Ticket delivery (Phase 2, shipped)

Three channels, each already reachable from `/t/{token}` (and the "View
ticket" / "Download" pair on `order-status.blade.php`):

- **Website** — `TicketController::download()`, a new `tickets.download`
  route rendering `tickets/pdf.blade.php` through the `barryvdh/laravel-dompdf`
  dependency already used for `EventTableController::qrSheet()`. Same
  `QrCodeService::png()` call the email attachment uses, for the same
  raster-over-inline-SVG reason documented on that class.
- **Email** — no new code path; `TicketOrderConfirmationNotification`
  (existing) gained one more `->line()` with the event's date/time/venue, so
  "event information" isn't only implied by the ticket type/price lines that
  were already there.
- **WhatsApp** — a `wa.me` deeplink (`WhatsAppInviteLink::ticketConfirmationMessage()`,
  reusing the existing `url()` builder guests already share invites through),
  surfaced as a "Send to WhatsApp" button on `/t/{token}` when the ticket has
  an `attendee_phone`. **Not** an automatic server-initiated send — this
  codebase has no WhatsApp Business API integration (no access token, phone
  number ID, or approved message template), and building one needs real
  provider credentials only the organizer can supply. Upgrading this to a
  true automatic send is a drop-in replacement of this one link once those
  credentials exist — the message copy/fields don't change, only who
  triggers it.

### 5.11 Host ticket dashboard (Phase 3/4, shipped early)

A "Ticketing" section on the event dashboard, replacing the plain **Tickets**
button that used to go straight to ticket-type CRUD (§4 said a global nav
was "unnecessary until those modules have their own index pages" — Overview
and the Tickets table now do). Nine tabs, shared via
`events/tickets/partials/nav.blade.php` (`nav.tkt-tabs` needs the usual
`global.css` `nav {}` override, same as `.dash-nav` / `nav.set-tabs`):
Overview, Tickets, Check-in, and Settings route to real pages; Orders,
Attendees, Sales, Revenue, and Payouts render disabled — visible as the
9-item structure without inventing content for phases that haven't shipped.

- **Overview** (`EventTicketDashboardController`) — sold / remaining /
  checked-in counts, plus gross sales / EventHost fees / host revenue read
  straight from `TicketRevenueEntry` via
  `TicketRevenueLedgerService::summaryFor()`, not re-derived from
  `ticket_orders`. "Remaining" skips unlimited ticket types, same as the
  public picker's "N left" badge.
- **Tickets** (`EventTicketManagementController`) — every issued ticket:
  Ticket / Type / Buyer / Status / Check-in, `.evt-more` row actions (View,
  Resend, Cancel, Check in), reusing the guests table's already-shipped
  dropdown JS (`guests-admin.js`'s `initMoreMenus()` is fully generic — this
  page loads the file for that one function rather than duplicating ~100
  lines of toggle/portal logic, per "parallel, not shared" but for markup/JS
  it's cheaper to just re-load than fork). Ticket display numbers (`EH-001`
  …) are computed per page load from pagination offset, not stored — there is
  no `ticket_number` column, `public_token` remains the only identifier that
  matters (§5.1).
  - **Resend** re-sends `TicketOrderConfirmationNotification` to the order's
    `buyer_email` — the exact on-demand call `TicketOrderFulfillmentService`
    already makes, just re-triggered.
  - **Check in** is `TicketCheckInService::confirm()` again, called from a
    plain redirect-back form instead of the camera scanner's JSON endpoint —
    two front doors on one service, same idiom as `TicketController::download()`
    and the emailed QR both calling `QrCodeService::png()`.
  - **Cancel** is status-only — it does not call Lenco or move real money.
    It only flips a `Valid` ticket to `Cancelled` so the buyer can no
    longer use it. There is no Refund action; see §5.12.

### 5.12 Ticket refunds are manual (Phase 19)

Organizers refund buyers themselves (bank, mobile money, cash — off
EventHost). The product does not:

- show a Refund action on the Tickets table
- collect refund-policy copy on ticket types (`refund_policy` dropped)
- reverse Lenco collections
- write negative `ticket_revenue_entries`

Cancel remains: it voids a `Valid` ticket so it cannot be scanned, and
does not move money. `TicketStatus::Refunded` stays on the enum so
check-in still refuses any leftover rows; `TicketOrderStatus::Refunded`
stays so a provider `refunded` webhook freezes fulfillment. Credit
purchase refunds (`PaymentCompletionService`,
`CreditTransaction::REASON_REFUND`) and the legal/billing "credits are
non-refundable" copy are a different product and are unchanged.

### 5.13 One check-in scanner, two credential types (Phase 3, shipped early)

Phase 17's brief: "don't create an entirely separate check-in system — extend
the existing one." §5.9 had already gone the other way (parallel controllers
*and* parallel views/JS, ~95% byte-identical between `checkin-scanner.js` and
the now-deleted `ticket-checkin-scanner.js`). This phase folds the
markup/JS layer back into one, while leaving business logic split:

- **`CheckInController`/`CheckInService` and `TicketCheckInController`/
  `TicketCheckInService` are untouched.** `product_kind` is mutually
  exclusive per event (§1), so which service applies is already decided by
  which dashboard route the organizer is on — there was never runtime
  ambiguity to resolve inside a shared controller, only duplicated
  presentation code to remove.
- **One partial, one JS file.** `events/checkin/partials/scanner-widget.blade.php`
  now takes a `$kind` (`guest` default | `ticket`) and a generic
  `$selfQrBase` (was `$guestQrBase`) instead of forking per credential type;
  `checkin-scanner.js` picks a small per-kind config (result JSON key,
  lookup JSON key, detail-field list, copy) instead of every line being
  duplicated. DOM ids are always `ckin*` now — the ticket page's markup used
  to say `tckin*`, purely an implementation detail existing tests asserted
  on, updated alongside.
- **Re-scan stays idempotent for both kinds** — a second scan of an
  already-checked-in guest or ticket returns `already_checked_in: true` and
  does not error. This was already true on both sides before this phase;
  keeping it (rather than "Used → reject" as literally written in the brief)
  avoids a UX regression on the guest side, which nothing about this phase
  was supposed to touch.
- **New: `PublicTicketCheckInController`** (`/checkin/tickets/{staffToken}`,
  registered before the guest catch-all `/checkin/{staffToken}` so the
  literal `tickets` segment isn't swallowed as a token) — the ticket-side
  twin of `PublicCheckInController`, the actual gap this phase closes: door
  staff without a dashboard login previously had no way to scan tickets at
  all. `EventStaffLink` needed no schema change — it was already generic
  (`event_id`/`token`/`label`, no guest FK); `EventStaffLinkController`
  (create/revoke) only gained a branch on which dashboard page to redirect
  back to, and `EventStaffLink::scannerUrl()` only gained a branch on which
  public route to build, both keyed off `$event->isTicketed()`.
- Ticket check-in dashboard page (`events/tickets/checkin/scan.blade.php`)
  gained the same "Door staff links" section the guest page has, reusing
  `EventStaffLinkController` as-is.

---

## 6. Admin (Phase 1)

- Platform settings: commission % and cancellation-fee %.
- `/admin/ticketing` queue: pending ticketed events. Approve (optional agreed
  payout date) or reject (required note).
- Permissions: `ticketing.view` (support+), `ticketing.approve` (admin+, not
  support). Commission lives under existing `settings.manage`.

---

## 7. Phases

| Phase | Ships |
|---|---|
| **1 — this slice** | `product_kind`, ticket types CRUD, commission settings, submit / approve / reject, no credit on ticketed publish |
| **2** | Public buy flow, 10-minute hold, Lenco collection, ticket + QR email, secure ticket link |
| **3** | Orders / attendees in the portal. ~~Scanner accepts ticket QR~~ shipped early — see §5.9, later unified with the guest scanner's markup/JS and given its own staff-link variant — see §5.13. ~~Host ticket dashboard (Overview + Tickets table, per-ticket cancel)~~ shipped early — see §5.11 |
| **4** | ~~Revenue dashboard~~ shipped early as the Overview tab — see §5.11, reading `ticket_revenue_entries` (the ledger itself, `sale` rows, shipped even earlier — see §5.1). Orders / Attendees / Sales / Payouts tabs still not built — the nav shows them disabled. Manual payouts, cancellation fee, terms copy still open |
| **19** | Ticket refunds are manual / off-platform. Removed the host Refund action, refund-policy field, and ledger `recordRefund()` writer. See §5.12. |
| Later | Ticket transfer, comps, door sales (on-ledger), seat maps, trusted-organizer auto-approve. Not an in-app ticket refund flow. |

---

## 8. File map (Phase 1)

| Concern | Where |
|---|---|
| Enums | `app/Enums/EventProductKind.php`, `TicketingStatus.php`, `CommissionMode.php` |
| Settings | `app/Support/TicketingSettings.php` |
| Activation | `app/Services/TicketingActivationService.php` |
| Ticket types | `app/Models/TicketType.php`, `EventTicketTypeController` |
| Host submit / commission mode | `EventTicketingController` |
| Admin queue | `Admin\TicketingController` |
| CSS | `public/css/events-admin.css` (`.tkt-*`, `.evt-product-choice`) |

---

## 9. File map (Phase 2 — planned)

| Concern | Where |
|---|---|
| Enums | `app/Enums/TicketOrderStatus.php`, `TicketReservationStatus.php`, `TicketStatus.php` |
| Reservations | `app/Models/TicketReservation.php`, `app/Services/TicketReservationService.php` |
| Orders | `app/Models/TicketOrder.php`, `TicketOrderItem.php`, `app/Services/TicketCheckoutService.php` |
| Payments | `app/Models/TicketPayment.php`, `app/Services/TicketPaymentStatusService.php` (parallel to `PaymentStatusService`) |
| Fulfillment | `app/Models/Ticket.php`, `app/Services/TicketOrderFulfillmentService.php` |
| Notification | `app/Notifications/TicketOrderConfirmationNotification.php` (mirrors `PaymentReceiptNotification`) |
| Controllers | `EventTicketPurchaseController` (picker/hold), `EventTicketCheckoutController` (checkout/poll), `TicketController` (`/t/{token}` + QR) |
| Webhook | extend existing `PaymentController::webhook` to also try `TicketPayment::findForLencoWebhook()` |
| Scheduler | `app/Console/Commands/ExpireTicketReservations.php` |
| Retry job | `app/Jobs/RetryLencoTicketPayment.php` (twin of `RetryLencoPayment`) |
| Rate limiters | `ticket-hold`, `ticket-checkout` in `AppServiceProvider` |
| Views | `resources/views/events/tickets/{purchase,checkout,order-status}.blade.php`, `resources/views/tickets/show.blade.php` |
| Public landing (fixed template) | `resources/views/events/tickets/landing.blade.php` + `events/tickets/partials/landing-content.blade.php` — shared with the host-only preview via `events/preview.blade.php`'s ticketed branch; see §4 step 2 |
| CSS / JS | `public/css/ticket-checkout.css`, `public/js/ticket-checkout.js` (mirror `billing-checkout.js`'s poll loop) |
| CSS / JS (public landing) | `public/css/ticket-event-public.css` (`.tev-*`, layers on top of `events-public.css` + `ticket-checkout.css`), `public/js/ticket-event-public.js` (share button, mirrors `rsvp-thanks.js`'s `initShareButton()`) |
