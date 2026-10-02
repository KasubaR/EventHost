# Astragate (mobile-money collections)

Status: **sandbox test page only.** Customer billing is still Lenco. Docs: https://docs.astragate.africa
(machine-readable index: `/llms.txt`). Code: `AstragateService`, `AstragateTestController`,
`AstragateWebhookController`, `resources/views/astragate-test.blade.php`, tests in
`tests/Feature/AstragatePaymentTest.php`.

## What exists

- **Test page** at `/{ASTRAGATE_TEST_PATH}` (secret; route is not registered when unset). Sends a real sandbox
  collection, shows the initiate response, the callback and a "Check status" result. Creates no `payments` rows, grants
  no credits, amount capped at K20, throttled, `noindex`. Records live 24 h in cache under `astragate.test.{reference}`.
- **Callback** `POST /webhooks/astragate/{ASTRAGATE_WEBHOOK_SECRET}`. Astragate does not sign callbacks, so the secret in
  the path is the only check; wrong/unset secret is a 404. It currently only updates `TEST-` records and acknowledges
  everything else.
- **Env:** `ASTRAGATE_API_BASE_URL`, `ASTRAGATE_AUTH_URL`, `ASTRAGATE_CLIENT_ID`, `ASTRAGATE_CLIENT_SECRET`,
  `ASTRAGATE_WEBHOOK_SECRET`, `ASTRAGATE_TEST_PATH`. There is deliberately **no global enable flag**: an earlier version had
  `ASTRAGATE_ENABLED`, which would have sent live `/billing` mobile-money payments to the sandbox. It was removed.

## Observations from the live sandbox run (2026-10-02)

Auth, initiate, callback and status-check all worked end to end against `api.dev.astragate.africa`.

| Step | Observed |
|---|---|
| Token | `POST {auth}/v1/auth/token`, form-encoded client credentials, returns `access_token` + `expires_in` (3600). Cached. |
| Initiate | `POST /v1/payment/collection` with `accountNumber` as `260XXXXXXXXX` (no `+`) was accepted. Response `data.statusCode` 4001 "Transaction received and processing", plus `astragateTransactionId`. |
| Callback | Arrived unprompted, JSON: `callbackType: COLLECTION`, `statusCode` 4200 (**a number**; docs example showed a string), `correlatorId`, `systemTransactionId` (equals the `astragateTransactionId` from initiate), `statusDescription` "Success", **`amount`**, `metadata: null`. No `currency`, no signature header. |
| Status | `GET /v1/payment/status/{correlatorId}` returns `data.{accountNumber, amount, astragateTransactionId, correlatorId, paymentChannel, paymentProcessorResponseMessage, statusCode 4200, statusDescription "Successful Transaction"}`. **No `currency`.** |

Docs were thin: the callback `amount` and the status response shape were not documented; both were inferred and then
confirmed by this run.

### Hosted checkout (card) — 2026-10-02

- `POST /v1/payment/checkout-sessions` returns `data.{checkoutUrl, sessionId, token}`. **The `token` must be appended to
  `checkoutUrl` as `?token=…`**, otherwise the checkout page says "Payment Request Error — The requested payment could not
  be found". The session `token` is a JWT issued to the merchant client (decoded claims include payout/refund roles, 1 h
  expiry), so it must not be logged, cached in plain view or echoed in a response dump — `AstragateService::createCheckoutSession()`
  returns the URL with the token already appended and a `rawResponse` with the token removed.
- After payment Astragate redirects the customer to the merchant's base URL **configured in the portal** (docs example:
  `…/callback/checkout-success?correlatorId=SO004`), not to a URL passed in the request (no `successUrl`/`returnUrl` field).
  Set it to the test page for sandbox testing.
- No documented webhook for checkout sessions; use the status endpoint or the portal redirect.
- Docs typo: `paymentMode` is `MOBILE_MONEY` in the API reference, `MOBILE_MOBILE` in the checkout guide.

## Not yet observed (verify before relying on it)

- Failure paths: declined / cancelled / timed-out / insufficient balance. Status mapping (4011, 4230, 4777 failed;
  4005, 4007 cancelled; 4220 reversed→refunded; 4003, 4999 pending) comes from the docs only.
- Whether the sandbox ever sends a callback for non-success states, and whether it retries a callback that did not get a 2xx.
- Whether a customer prompt appears on the phone in the sandbox, or it auto-completes. Any special test numbers/PINs.
- Callback ordering: it can arrive before the initiate response is stored. The test page tolerates this only because the
  cache record is written right after initiate; real billing must create its row *before* calling Astragate.
- The key prefix is `astragate-live-` while the URLs are the sandbox's. Confirm with Astragate that this is just naming.

## Improvements to make

1. **Currency in the settlement check.** `Payment::providerSettlementMatchesRecordedPayment()` returns false when currency
   is null, and neither the callback nor the status response carries one. Pass the payment's own currency (all collections
   are ZMW — the initiate endpoint only accepts `^ZMW$`) but **do** compare the `amount` Astragate reports instead of
   echoing the recorded one. (The first webhook version echoed the recorded amount because the docs listed no amount.)
2. **Match on more than the URL secret.** On callback, also require `systemTransactionId` to equal the stored
   `astragateTransactionId`, and re-fetch the status from Astragate before completing money-moving payments rather than
   trusting the unsigned body.
3. **Idempotency / retries.** Handle duplicate callbacks (already-terminal payments) the way `PaymentController::processPaymentWebhook()`
   does; add queued retry for initiate failures with code 0 / >= 500 (Lenco has `RetryLencoPayment`; Astragate currently
   surfaces the error immediately).
4. **Normalise status codes.** `statusCode` is a number in callbacks and may be a string elsewhere; `mapStatusCode()` casts
   to int already — keep a test for both.
5. **Store Astragate ids properly.** Billing currently reuses `lenco_*` columns; a gateway-neutral name or a `gateway`
   column beats `metadata['gateway']` if Astragate becomes a second real provider.
6. **Gate per environment, not globally.** If customer billing moves to Astragate, use an explicit per-gateway setting
   (and keep Lenco for bank transfer, tickets, contributions until each is ported). Never reintroduce a switch whose
   default or stray value changes live behaviour.
7. **Operator field.** Astragate takes only `accountNumber`; the billing form's operator selector and operator/phone
   mismatch validation exist for Lenco. Decide whether to keep or drop it for Astragate.
8. **Production.** Separate production credentials and base URLs (`api.astragate.africa`), KYC and approval, low-value
   live test, new webhook secret and callback registered in the production portal (see the docs' go-live page).
9. **Refunds / payouts / checkout.** Astragate also offers refunds, payouts, hosted checkout sessions and payment links
   (`docs.astragate.africa/reference/...`). Not used; hosted checkout may be a better fit than raw collections for cards.

## Hygiene

- Sandbox client id/secret and the callback secret were pasted into a chat on 2026-10-02. Low risk (sandbox) but rotate
  them, and generate fresh values for production. Never commit them; `.env` is untracked.
