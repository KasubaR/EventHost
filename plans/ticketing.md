# Feature Plan: Paid ticketing

Status: **Phase 1 shipped** (schema, product kind, ticket types, admin
activation). **Phase 2 planned** (§5) — reservations, checkout, Lenco
collections, order/ticket issuance, QR delivery. Not started. Refunds, the
scanner, revenue ledger, and payouts are still later phases — see §7.

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
| Refunds | Organizer-initiated (Phase 3). Lenco reversal + ledger |
| Ticket transfer | Phase 2 |
| Seat selection | Phase 3 |
| Complimentary / door / cash | **Not in V1.** Off-platform comps are the organizer's problem; the site will not mark tickets paid by hand |
| Payouts | Manual. Admin records a payout on the date agreed with the organizer. No Lenco disbursement in V1 |
| Ticket sales go live | Organizer submits → **admin approves**. Approval publishes the public page **without spending an event credit** |
| Cancellation | Organizer pays EventHost a cancellation fee (admin-configurable %). Buyer refunds are a later phase |
| Bypass | EventHost only commissions tickets sold on the platform. Terms prohibit directing buyers off-platform; the product does not try to stop cash-in-hand deals |
| Manual "mark as paid" | Never. Tickets are issued only by the payment-confirmed path |

### What the organizer controls

Ticket name, price, quantity, sales window, min/max per order, description,
image, ticket terms, refund-policy copy, commission mode (absorb vs
pass-through).

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
| refund_policy | text, nullable | Copy only; enforcement is Phase 3 |
| sort_order | unsigned smallint | default 0 |
| is_active | boolean | default true |
| timestamps | | |

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
nothing sets or reads it until a refund flow exists, same "don't build ahead
of the phase that uses it" rule as everywhere else in this doc. Money columns
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

**`ticket_revenue_entries`** — append-only host ledger (`sale | commission |
refund | payout | cancellation_fee`), `balance_after`, `$guarded = ['*']`,
same shape as `CreditTransaction`. Still Phase 4 — Phase 2 does not write to
a ledger table that doesn't exist yet; `ticket_orders.host_amount` is enough
to reconstruct it later.

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
2. Ticketed: still pick an invitation layout (the public `/e/{slug}` page reuses
   it). Guest-limit / plus-one / RSVP-deadline fields are hidden.
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
| status | string(20) | `valid \| used \| refunded \| cancelled` — **no check-in columns yet, see §3** |
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

Per-seat attendee names, ticket transfer, refunds, the scanner accepting
ticket tokens, revenue ledger writes, payouts — all later phases per §7.
This phase's job is: money in, tickets out, buyer can see and re-open their
own ticket.

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
| **3** | Orders / attendees in the portal, scanner accepts ticket QR, organizer refunds |
| **4** | Revenue dashboard, manual payouts, cancellation fee, terms copy |
| Later | Ticket transfer, comps, door sales (on-ledger), seat maps, trusted-organizer auto-approve |

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
| CSS / JS | `public/css/ticket-checkout.css`, `public/js/ticket-checkout.js` (mirror `billing-checkout.js`'s poll loop) |
