# Lenco Payment Integration — Sunconnect Reference

Sunconnect payment stack: Lenco mobile money (MTN / Airtel) for orders, swap
checkout, and layby. This doc describes **what is implemented in this repo** and
how it maps to the **official Lenco v2 API**.

**Official Lenco API docs:** https://lenco-api.readme.io/v2.0/reference

**Related deploy docs:** `docs/LENCO-PRODUCTION-DEPLOY.md`,
`docs/production-deployment-checklist.md` (includes legacy manual purge step).

---

## Table of Contents

1. [Overview](#1-overview)
2. [Implementation status](#2-implementation-status)
3. [Environment Variables](#3-environment-variables)
4. [Config Registration](#4-config-registration)
5. [Database](#5-database)
6. [Architecture & key classes](#6-architecture--key-classes)
7. [Payment methods (Lenco vs Sunconnect)](#7-payment-methods-lenco-vs-sunconnect)
8. [Mobile money flow (implemented)](#8-mobile-money-flow-implemented)
9. [PaymentController & routes](#9-paymentcontroller--routes)
10. [Webhook handling](#10-webhook-handling)
11. [Frontend polling](#11-frontend-polling)
12. [Background retry queue](#12-background-retry-queue)
13. [Scheduled poller](#13-scheduled-poller)
14. [Status mapping](#14-status-mapping)
15. [Observability & security](#15-observability--security)
16. [Legacy manual payments](#16-legacy-manual-payments)
17. [Future: bank transfer](#17-future-bank-transfer)

---

## 1. Overview

Lenco is a Zambian payments platform ([lenco.co](https://lenco.co/zm)) with a
REST v2 API at `https://api.lenco.co/access/v2` (sandbox:
`https://api.sandbox.lenco.co/access/v2`).

Webhooks use `HMAC-SHA512` of the raw body, keyed with
`SHA256(LENCO_API_SECRET_KEY)`.

### Customer-facing flows (Sunconnect v1)

All customer payments use **Lenco** (mobile money or card on standard checkout).
There is no admin manual payment approval path.

| Flow | Model | Reference prefix | Initiation | Completion |
|------|-------|------------------|------------|------------|
| Standard checkout — mobile money | `payments` | `PM-SC-{order_number}-…` | `MobileMoneyPaymentInitiator` | `PaymentCompletionService` |
| Standard checkout — card | `payments` | `PM-SC-{order_number}-…` | Lenco popup widget (browser) | `PaymentCompletionService` |
| Swap checkout | `payments` | `PM-SC-{order_number}-…` | `MobileMoneyPaymentInitiator` | `PaymentCompletionService` |
| Layby deposit / installment | `layby_payments` | `PM-LB-{layby_number}-…` | `MobileMoneyLaybyPaymentInitiator` | `LaybyPaymentCompletionService` |

The webhook routes by reference prefix: `PM-SC-*` → order `Payment`,
`PM-LB-*` → `LaybyPayment`. The scheduled `payments:poll-pending` command polls
both tables.

Admin Filament shows **stalled gateway payments** (pending Lenco longer than
`LENCO_STUCK_PAYMENT_HOURS`) instead of manual approval queues.

---

## 2. Implementation status

| Capability | Lenco v2 API | Sunconnect |
|------------|--------------|------------|
| Mobile money collection | `POST /collections/mobile-money` | **Implemented** |
| Verify by transaction ID | `GET /collections/{id}` | **Implemented** |
| Verify by reference | `GET /collections/status/{reference}` | **Implemented** |
| Webhook + signature verify | `POST` to your URL | **Implemented** |
| Card — popup widget | `LencoPay.getPaid()` (`card` channel) | **Implemented** (standard checkout only) |
| Card — server API | `POST /collections/card` (PCI + JWE) | Not implemented |
| Bank-account collections | `type: bank-account` in status responses; no public initiate endpoint in v2 readme | Not implemented |
| Admin manual payment approve | N/A | **Removed** |

Sunconnect uses the **server-side mobile money API** for MoMo and the **Lenco
Pay popup widget** for card (no PCI on Sunconnect servers). Zambia MoMo
operators in checkout: **MTN** and **Airtel** (Lenco also supports `zamtel` in
the API; not offered in UI).

---

## 3. Environment Variables

```env
LENCO_API_BASE_URL=https://api.lenco.co/access/v2
LENCO_API_SECRET_KEY=your_secret_key_here
LENCO_WEBHOOK_SECRET=          # leave blank — derived from API key automatically
LENCO_WEBHOOK_URL=https://yourdomain.com/lenco/webhook
LENCO_WEBHOOK_PATH=lenco/webhook
LENCO_ENVIRONMENT=production   # or: sandbox
LENCO_PUBLIC_KEY=              # card popup widget; blank hides card at checkout

# Poller / stalled-payment thresholds
LENCO_PENDING_MAX_AGE_HOURS=24
LENCO_STUCK_PAYMENT_HOURS=2
LENCO_POLL_MAX_PER_RUN=50
LENCO_POLL_THROTTLE_MS=200

# Payment logging (see §15)
LOG_PAYMENTS_LEVEL=debug
LOG_PAYMENTS_DAYS=90
```

**Sandbox base URL:** `https://api.sandbox.lenco.co/access/v2`

- `LENCO_API_SECRET_KEY` — Bearer token for all server-side API calls. Never
  expose to the browser.
- `LENCO_WEBHOOK_SECRET` — leave empty unless Lenco support gives an override.
  The service derives the signing key as `SHA256(LENCO_API_SECRET_KEY)`.
- `LENCO_PUBLIC_KEY` — Lenco Pay **public** key for the card popup widget.
  Safe to expose in the browser. Leave blank to hide card at checkout.
- Pay script URL is derived from `LENCO_ENVIRONMENT` in `config/services.php`:
  - Production: `https://pay.lenco.co/js/v1/inline.js`
  - Sandbox: `https://pay.sandbox.lenco.co/js/v1/inline.js`

---

## 4. Config Registration

`config/services.php`:

```php
'lenco' => [
    'base_url' => env('LENCO_API_BASE_URL', 'https://api.sandbox.lenco.co/access/v2'),
    'api_secret_key' => env('LENCO_API_SECRET_KEY'),
    'webhook_secret' => env('LENCO_WEBHOOK_SECRET'),
    'webhook_url' => env('LENCO_WEBHOOK_URL'),
    'webhook_path' => env('LENCO_WEBHOOK_PATH', 'lenco/webhook'),
    'environment' => env('LENCO_ENVIRONMENT', 'sandbox'),
    'public_key' => env('LENCO_PUBLIC_KEY'),
    'pay_script_url' => env('LENCO_PAY_SCRIPT_URL') ?: (
        env('LENCO_ENVIRONMENT', 'sandbox') === 'production'
            ? 'https://pay.lenco.co/js/v1/inline.js'
            : 'https://pay.sandbox.lenco.co/js/v1/inline.js'
    ),
    'pending_max_age_hours' => (int) env('LENCO_PENDING_MAX_AGE_HOURS', 24),
    'stuck_payment_hours' => (int) env('LENCO_STUCK_PAYMENT_HOURS', 2),
    'poll_max_per_run' => (int) env('LENCO_POLL_MAX_PER_RUN', 50),
    'poll_throttle_ms' => (int) env('LENCO_POLL_THROTTLE_MS', 200),
],
```

---

## 5. Database

### `payments` (orders / swap)

Base table: `database/migrations/2026_06_16_000007_create_payments_table.php`  
Lenco columns: `database/migrations/2026_06_25_000001_add_lenco_columns_to_payments_table.php`

Key columns:

| Column | Purpose |
|--------|---------|
| `method` | `mobile_money`, `card` (standard checkout), etc. |
| `provider` | `mtn`, `airtel`, or `lenco` (card) |
| `reference` | Merchant reference sent to Lenco (`PM-SC-…`), unique |
| `lenco_transaction_id` | Lenco collection UUID |
| `lenco_reference` | Lenco human reference |
| `lenco_status` | Raw Lenco status string |
| `payment_instructions` | USSD / prompt text for customer |
| `notified_at` | Idempotency gate for completion side-effects |
| `metadata` | Phone, operator, etc. |

### `layby_payments`

Base table: `database/migrations/2026_06_18_000002_create_layby_payments_table.php`  
Lenco columns: `database/migrations/2026_06_25_100001_add_lenco_columns_to_layby_payments_table.php`

Same Lenco column pattern as `payments`, with reference format `PM-LB-…`.

### Design notes

- **`notified_at`** — set atomically with terminal completion. Prevents duplicate
  emails / stock moves when webhook and poller race.
- **`lenco_transaction_id` vs `lenco_reference`** — store both; webhook may send
  either. Lookup tries reference first, then IDs.
- **`reference`** — always generated before Lenco initiation; required for
  verify-by-reference when `lenco_transaction_id` is not yet assigned.

---

## 6. Architecture & key classes

```
Checkout / Swap / Layby submit
        │
        ▼
MobileMoneyPaymentInitiator  or  MobileMoneyLaybyPaymentInitiator
        │  (after DB commit)
        ▼
LencoService::initiateMobileMoneyPayment()
        │
        ├── success → store lenco_transaction_id, instructions
        └── retryable failure → RetryLencoPayment / RetryLencoLaybyPayment

Webhook / Poller / Customer poll
        │
        ├── PM-SC-* → PaymentStatusService → PaymentCompletionService
        │                              or PaymentFailureService
        └── PM-LB-* → LaybyPaymentStatusService → LaybyPaymentCompletionService
                                               or LaybyPaymentFailureService
```

| Class | Role |
|-------|------|
| `LencoService` | API client: initiate, verify, webhook parse/sign, status map |
| `MobileMoneyPaymentInitiator` | Order payment initiation + dispatch retry job |
| `MobileMoneyLaybyPaymentInitiator` | Layby payment initiation + dispatch retry job |
| `PaymentStatusService` | Verify/sync order payments; webhook handler |
| `PaymentCompletionService` | Paid → order processing, stock, notifications |
| `PaymentFailureService` | Failed/cancelled → release stock, cancel order |
| `LaybyPaymentStatusService` | Verify/sync layby payments; webhook handler |
| `LaybyPaymentCompletionService` | Approved → update layby balance; complete layby if zero |
| `LaybyPaymentFailureService` | Rejected → customer notification |
| `RetryLencoPayment` | Queue job: retry failed order initiations |
| `RetryLencoLaybyPayment` | Queue job: retry failed layby initiations |
| `PollPendingPayments` | Scheduled command: verify stuck pending rows |
| `PaymentLog` | Structured logging to `payments` channel |

---

## 7. Payment methods (Lenco vs Sunconnect)

### 7.1 Mobile money — **Sunconnect v1**

- **Lenco:** [POST /collections/mobile-money](https://lenco-api.readme.io/v2.0/reference/initiate-collection-from-mobile-money)
- Customer authorises on phone; status often `pay-offline` until approved.
- **Sunconnect:** sole customer-facing method.

### 7.2 Card — **standard checkout**

Lenco supports cards two ways ([Accept Payments](https://lenco-api.readme.io/v2.0/reference/accept-payments)):

| Approach | How | PCI |
|----------|-----|-----|
| **Popup widget** | `LencoPay.getPaid({ channels: ["card"], … })` | Lenco hosts card entry |
| **Server API** | [POST /collections/card](https://lenco-api.readme.io/v2.0/reference/initiate-collection-from-card) with JWE-encrypted PAN | **PCI DSS required** |

**Sunconnect (implemented):** popup widget on order confirmation only.

1. Checkout: customer selects **Card** → `CheckoutService` creates pending
   `Payment` (`method=card`, `PM-SC-*` reference) — **no** server initiation.
2. Confirmation: load Lenco inline script; `LencoPay.getPaid()` with
   `LENCO_PUBLIC_KEY` via `LencoService::buildCardWidgetConfig()`.
3. `onSuccess` / `onConfirmationPending` → poll `GET /payment/verify-ref/{reference}`.
4. Webhook `collection.successful` uses the same completion path as mobile money.

Card is hidden at checkout when `LENCO_PUBLIC_KEY` is empty. Swap and layby
remain mobile money only.

**Files:** `public/js/lenco-card-pay.js`, `resources/views/orders/confirmation.blade.php`,
`CheckoutService`, `LencoService::buildCardWidgetConfig()`.

### 7.3 Bank transfer / bank-account

Lenco collection records can have `type: bank-account` (see
[GET /collections](https://lenco-api.readme.io/v2.0/reference/get-collections)),
sometimes with `source: banking-app`. Status/verify endpoints work for these
types.

There is **no** documented v2 **initiate** endpoint equivalent to
`/collections/mobile-money` for bank-account collections in the public readme
(no `POST /collections/bank-transfer` page). Bank transfer checkout would
require confirmation from Lenco support for your merchant account.

`/resolve/bank-account` validates account names for transfers — a payout/transfer
helper, not customer checkout.

**Sunconnect:** not implemented in v1.

### 7.4 Offline / manual

**Removed.** All flows settle via Lenco. Use
`php artisan payments:purge-legacy-manual` to clear pre-cutover pending manual
rows (see §16).

---

## 8. Mobile money flow (implemented)

### Initiation

```php
$result = $lenco->initiateMobileMoneyPayment(
    ['ref' => $orderNumber, 'amount' => 150.00, 'reference' => $reference],
    '0971234567',
    'mtn'   // or 'airtel'
);
```

**Lenco payload:**

```json
{
    "phone": "+260971234567",
    "operator": "mtn",
    "amount": 150.00,
    "currency": "ZMW",
    "reference": "PM-SC-SC-2026-00001-1748000000-a1b2c3d4",
    "country": "ZM",
    "description": "Order payment for SC-2026-00001"
}
```

**Reference helpers:**

```php
$lenco->generatePaymentReference($orderNumber);      // PM-SC-{order_number}-…
$lenco->generateLaybyPaymentReference($laybyNumber); // PM-LB-{layby_number}-…
```

### Lenco statuses (mobile money)

| Lenco status | Meaning |
|--------------|---------|
| `pay-offline` | Prompt sent; customer must approve on phone |
| `pending` | Still processing |
| `successful` | Paid |
| `failed` | Failed |
| `expired` / `cancelled` | Terminal — not completed |

### Zambia phone rules

Normalise to E.164 (`+260XXXXXXXXX`) via `LencoService::normalizeZambianPhone()`.

| Operator | Typical prefixes |
|----------|------------------|
| MTN | 096x, 076x |
| Airtel | 097x, 077x, 057x |

### Zero-amount orders (swap)

When swap top-up is `0`, no Lenco call is made — payment is marked paid
immediately.

---

## 9. PaymentController & routes

`app/Http/Controllers/PaymentController.php`

| Method | Route | Purpose |
|--------|-------|---------|
| `verify` | `GET /payment/verify/{transactionId}` | Verify by Lenco ID (auth: order owner or guest token) |
| `verifyByReference` | `GET /payment/verify-ref/{reference}` | Verify by merchant reference |
| `paymentStatus` | `GET /orders/{orderNumber}/payment-status` | Order confirmation poll endpoint |
| `laybyPaymentStatus` | `GET /laybys/{layby}/payments/{payment}/status` | Layby confirmation poll (auth) |
| `webhook` | `POST {LENCO_WEBHOOK_PATH}` | Lenco webhook (CSRF exempt) |

Payment initiation happens inside `CheckoutService`, `SwapCheckoutService`, and
`LaybyService` — there is no separate `POST /api/payment/initiate` route.

**Guest orders:** poll/verify accepts `?token=` or `X-Guest-Token` matching
`orders.guest_access_token`.

---

## 10. Webhook handling

### Route (no CSRF)

```php
$webhookPath = config('services.lenco.webhook_path') ?: 'lenco/webhook';
Route::post($webhookPath, [PaymentController::class, 'webhook'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('lenco.webhook');
```

Also excluded in `bootstrap/app.php` CSRF exceptions.

### Signature

```
X-Lenco-Signature: HMAC-SHA512(rawBody, SHA256(apiSecretKey))
```

Use `hash_equals`. Strip optional `sha512=` prefix. Reject if API key missing
(fail-closed).

### Routing logic

1. Parse payload → `LencoService::extractWebhookData()`
2. If `reference` starts with `PM-LB-` → `LaybyPayment::findForLencoWebhook()`
   → `LaybyPaymentStatusService::processWebhookPayment()`
3. Else → `Payment::findForLencoWebhook()` → `PaymentStatusService::processWebhookPayment()`
4. Unmatched references → HTTP 200 `acknowledged` (avoid Lenco retry storm)
5. Amount/currency mismatch → fail payment, do not complete order/layby

### Sample payload

```json
{
    "status": "successful",
    "data": {
        "id": "col_xxx",
        "reference": "PM-SC-SC-2026-00001-...",
        "lencoReference": "240720004",
        "status": "successful",
        "amount": "150.00",
        "currency": "ZMW",
        "type": "mobile-money"
    }
}
```

Webhook event: `collection.successful` (configure URL in Lenco dashboard).

---

## 11. Frontend polling

`public/js/payment-poll.js` + `public/css/payment-poll.css`

Used on:

- `resources/views/orders/confirmation.blade.php`
- `resources/views/laybys/confirmation.blade.php`

Config JSON (`#payment-poll-config`):

```json
{
    "initialStatus": "pending",
    "orderStatusUrl": "/orders/SC-2026-00001/payment-status",
    "guestToken": null,
    "orderNumber": "SC-2026-00001"
}
```

Layby confirmation uses `orderStatusUrl` → `laybys.payments.status` (no
`verifyUrl` — order verify endpoint does not apply to layby rows).

**Rules:**

- Poll **your** backend only — never Lenco from the browser.
- Backoff: 5s for first ~1 min, then 10s, max 36 polls.
- Only call `verifyAndSync` server-side when `lenco_transaction_id` exists
  (avoids polling storm on failed initiations).

---

## 12. Background retry queue

On retryable Lenco initiation failure (`5xx` / network), dispatch after commit:

| Job | Model | Queue |
|-----|-------|-------|
| `RetryLencoPayment` | `Payment` | `payments` |
| `RetryLencoLaybyPayment` | `LaybyPayment` | `payments` |

Requires a **payments** queue worker (see `production-deployment-checklist.md`).

Jobs skip if `lenco_transaction_id` already set or payment is terminal.

---

## 13. Scheduled poller

`php artisan payments:poll-pending` — scheduled every 5 minutes in
`bootstrap/app.php` with `withoutOverlapping()` and a cache mutex.

**Order payments (`payments` table):**

- Branch A: pending/processing + `lenco_transaction_id` set
- Branch B: pending + `method=mobile_money` + `reference` but no Lenco ID yet
  (failed initiation / retry in flight)
- Force-fail rows older than `LENCO_PENDING_MAX_AGE_HOURS`

**Layby payments (`layby_payments` table):** same pattern via
`LaybyPaymentStatusService` / `LaybyPaymentFailureService`.

Cap: `LENCO_POLL_MAX_PER_RUN` verify calls per run; throttle:
`LENCO_POLL_THROTTLE_MS`.

---

## 14. Status mapping

`LencoService::mapStatus()` → `PaymentStatus` enum:

| Lenco | Internal |
|-------|----------|
| `successful`, `success`, `paid`, `completed` | `paid` |
| `processing` | `processing` |
| `failed` | `failed` |
| `cancelled`, `canceled`, `expired` | `cancelled` |
| `pending`, `pay-offline`, other | `pending` |

Layby payments map gateway `paid` → `LaybyPaymentStatus::Approved` in
`LaybyPaymentCompletionService`; failures → `Rejected`.

---

## 15. Observability & security

### Logging

- Channel: `payments` → `storage/logs/payments-YYYY-MM-DD.log`
- Helper: `App\Support\PaymentLog` — use for all payment events
- Retention: `LOG_PAYMENTS_DAYS` (default 90)
- Level: `LOG_PAYMENTS_LEVEL` (keep `debug` in production for lifecycle events)

Never log API secrets, card data, or full webhook bodies with PII.

### Security checklist

| # | Check |
|---|-------|
| 1 | Webhook route CSRF-exempt |
| 2 | Webhook signature verified with `hash_equals` before processing |
| 3 | Signing key = `SHA256(apiSecretKey)` |
| 4 | `LENCO_API_SECRET_KEY` never in frontend |
| 5 | Amount + currency verified before completion |
| 6 | `lockForUpdate()` + `notified_at` idempotency on completion |
| 7 | Webhook returns HTTP 200 even when unmatched (avoid retry storm) |
| 8 | Poll endpoint does not call Lenco when `lenco_transaction_id` is null (except card + `widget_complete=1`) |
| 9 | Terminal statuses short-circuit — no re-processing |

Optional: `LOG_SLACK_PAYMENT_ALERTS` for critical payment alerts (deferred in v1).

---

## 16. Legacy manual payments

Pre–Lenco-only deploys may have `method=manual` or `payment_method=manual` rows
still `pending`. These cannot be completed after admin approval was removed.

**One-time cleanup** (per environment):

```bash
php artisan payments:purge-legacy-manual        # dry-run
php artisan payments:purge-legacy-manual --force  # fail/reject listed rows
```

Documented in `docs/production-deployment-checklist.md` §5.1.

---

## 17. Future: bank transfer

To add **bank transfer**, confirm with Lenco (`[email protected]`) which product
/API your Zambia merchant account should use; then mirror the mobile-money
initiation + poll pattern if an initiate endpoint is provided.

PCI server-side card (`POST /collections/card`) is intentionally out of scope.

---

## Quick reference — official Lenco endpoints

| Operation | Endpoint | Sunconnect |
|-----------|----------|------------|
| Initiate mobile money | `POST /collections/mobile-money` | Yes |
| Initiate card (PCI) | `POST /collections/card` | No |
| Card popup | `LencoPay.getPaid()` | Yes (standard checkout) |
| Verify by ID | `GET /collections/{id}` | Yes |
| Verify by reference | `GET /collections/status/{reference}` | Yes |
| List collections | `GET /collections` | No |
| Resolve bank account | `POST /resolve/bank-account` | No (transfers) |
| Webhook | Your URL | Yes |
