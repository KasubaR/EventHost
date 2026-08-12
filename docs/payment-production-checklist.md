# Payment integration — production checklist

Use this after uploading the Lenco billing changes. Work through each section in order.

---

## 1. Pre-deploy

- [ ] Code deployed to production (git pull, upload, or CI/CD)
- [ ] **Do not** commit real Lenco keys to the repo — set them only in production `.env`
- [ ] Confirm Lenco merchant account is **live** (not sandbox) before switching keys
- [ ] Confirm with Lenco which collection APIs your account supports (mobile money; bank transfer may need separate confirmation — see §10)

---

## 2. Environment variables

Set these in production `.env`. Copy from `.env.example` for any missing keys.

### App & mail (required for receipts)

- [ ] `APP_ENV=production`
- [ ] `APP_DEBUG=false`
- [ ] `APP_URL=https://your-domain.example` (must match public site URL)
- [ ] Mail configured (`MAIL_MAILER`, `MAIL_HOST`, `MAIL_FROM_ADDRESS`, etc.) so payment receipts can send

### Queue & session

- [ ] `QUEUE_CONNECTION=database` (or `redis` if you use Redis workers)
- [ ] `SESSION_DRIVER=database` (or `redis`)
- [ ] `SESSION_SECURE_COOKIE=true` on HTTPS

### Lenco — production values

- [ ] `LENCO_ENVIRONMENT=production`
- [ ] `LENCO_API_BASE_URL=https://api.lenco.co/access/v2`
- [ ] `LENCO_API_SECRET_KEY=` *(live secret from Lenco dashboard)*
- [ ] `LENCO_PUBLIC_KEY=` *(optional for v1 — card/LencoPay widget deferred)*
- [ ] `LENCO_WEBHOOK_SECRET=` *(if Lenco provides a separate webhook signing secret)*
- [ ] `LENCO_WEBHOOK_PATH=lenco/webhook` *(must match route; change only if you use a custom path)*
- [ ] `LENCO_WEBHOOK_URL=https://your-domain.example/lenco/webhook` *(for your records / Lenco dashboard)*

### Lenco — poller & ops tuning (defaults are fine to start)

- [ ] `LENCO_PENDING_MAX_AGE_HOURS=24`
- [ ] `LENCO_STUCK_PAYMENT_HOURS=2`
- [ ] `LENCO_POLL_MAX_PER_RUN=50`
- [ ] `LENCO_POLL_THROTTLE_MS=200`
- [ ] `LENCO_BANK_TRANSFER_ENABLED=true` *(set `false` until Lenco confirms bank initiate API)*

### Payment logging

- [ ] `LOG_PAYMENTS_LEVEL=info` *(use `debug` temporarily if troubleshooting)*
- [ ] `LOG_PAYMENTS_DAYS=90`

After editing `.env`:

```bash
php artisan config:clear
php artisan config:cache
```

---

## 3. Deploy commands

Run on the server after upload:

```bash
composer install --optimize-autoloader --no-dev
npm ci && npm run build
php artisan migrate --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

- [ ] Migrations ran successfully (includes `payments` table)
- [ ] Frontend assets built (`public/build` up to date)

---

## 4. Permissions (admin payments list)

The `payments.view` permission is added by `RolePermissionSeeder`. On an **existing** production database, re-run the seeder (safe — uses `firstOrCreate`):

```bash
php artisan db:seed --class=RolePermissionSeeder --force
```

- [ ] Seeder completed without errors
- [ ] Admin / super_admin can open `/admin/payments`
- [ ] Clear permission cache if roles look stale: `php artisan permission:cache-reset` (if installed) or re-run seeder

---

## 5. Lenco dashboard

Register the webhook URL in the Lenco merchant dashboard:

```
https://your-domain.example/lenco/webhook
```

*(Replace path if `LENCO_WEBHOOK_PATH` is custom.)*

- [ ] Webhook URL saved in Lenco dashboard
- [ ] Live API secret key copied into `LENCO_API_SECRET_KEY`
- [ ] Sandbox keys removed from production `.env`

**CSRF:** The webhook route is excluded from CSRF in `bootstrap/app.php` and `routes/web.php`. No extra nginx/apache rule needed for CSRF.

---

## 6. Queue worker (required)

Payment initiation retries dispatch to the **`payments`** queue (`RetryLencoPayment`). Without a worker, queued payments stay stuck.

**One-off / testing:**

```bash
php artisan queue:work --queue=payments,default
```

**Production (Supervisor recommended):**

```ini
[program:event-host-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/your/app/artisan queue:work --sleep=3 --tries=3 --max-time=3600 --queue=payments,default
autostart=true
autorestart=true
numprocs=1
redirect_stderr=true
stdout_logfile=/path/to/your/app/storage/logs/worker.log
stopwaitsecs=3600
```

- [ ] Worker process running and set to restart on boot
- [ ] After each deploy: restart workers (`supervisorctl restart event-host-worker:*` or equivalent)
- [ ] Monitor `failed_jobs` table periodically

---

## 7. Scheduler cron (required)

The poller `payments:poll-pending` runs **every 5 minutes** via Laravel’s scheduler (`routes/console.php`). It catches payments that missed webhooks.

Add a system cron entry (runs every minute):

```cron
* * * * * cd /path/to/your/app && php artisan schedule:run >> /dev/null 2>&1
```

Or run `php artisan schedule:work` under Supervisor if you prefer a long-lived scheduler process.

- [ ] Cron active (`php artisan schedule:list` shows `payments:poll-pending`)
- [ ] Server timezone correct (affects `dailyAt` tasks elsewhere)

Manual test:

```bash
php artisan payments:poll-pending
```

---

## 8. Smoke tests (production)

Do these once after deploy. Use a **small real amount** or Lenco’s live test flow if available.

### Billing page

- [ ] Log in as a user with **0 credits** → `/billing` loads
- [ ] Plan cards show Base (K450), Pro (K750), Pro+ (K1500)
- [ ] Mobile money tab: MTN/Airtel + phone validation works

### Mobile money (happy path)

- [ ] Initiate payment → user receives MoMo prompt on phone
- [ ] Approve on phone → checkout completes
- [ ] User gets **+1 `event_credits`** and **tier upgrade** (never downgrade)
- [ ] Payment receipt email received (if `email_payment_receipts` enabled)
- [ ] User can create an event

### Webhook

- [ ] `payments.webhook_received` / completion visible in admin payment detail
- [ ] `storage/logs/payments.log` shows structured events (no full phone numbers or secrets)

### Resilience paths

- [ ] If webhook is delayed, frontend polling completes payment (5s then 10s backoff)
- [ ] Stale pending payments appear in `/admin/payments` with **Stuck** badge after ~2 hours

### Paywall behaviour

- [ ] New registration → tier `none`, 0 credits (cannot create event until payment)
- [ ] “Buy event credit” / billing links work from dashboard and events index

---

## 9. Admin & monitoring

- [ ] `/admin/payments` — list, filters (status, method, stuck), pagination
- [ ] Payment detail — user link, Lenco IDs, reference, timeline fields
- [ ] Tail payment log when debugging: `storage/logs/payments.log`
- [ ] Set up alerting for: queue worker down, scheduler not running, spike in `failed`/`cancelled` payments

---

## 10. Bank transfer (optional / confirm with Lenco)

Bank transfer UI is behind `LENCO_BANK_TRANSFER_ENABLED`.

- [ ] Confirm with Lenco which bank collection API your live account supports
- [ ] If unsupported: set `LENCO_BANK_TRANSFER_ENABLED=false` and redeploy config cache
- [ ] If enabled but API returns 404/422, users should see: *“Bank transfer unavailable — use mobile money or contact support”*
- [ ] MoMo path must still work when bank is disabled or failing

---

## 11. Product copy (already in codebase — verify on live site)

- [ ] Homepage FAQ reflects **paywall** (register free, pay per event to create)
- [ ] Register page does not promise a free first event

---

## 12. Rollback notes

If you must revert:

1. Stop taking payments (maintenance mode or hide billing links temporarily)
2. Deploy previous release
3. **Do not** roll back the `payments` migration if any live payments exist — keep the table and reconcile manually via admin
4. Restart queue workers and clear config cache

---

## Quick reference

| Item | Value |
|------|--------|
| Billing page | `GET /billing` |
| Webhook | `POST /{LENCO_WEBHOOK_PATH}` (default `/lenco/webhook`) |
| Admin payments | `GET /admin/payments` |
| Poller command | `php artisan payments:poll-pending` |
| Queue | `payments,default` |
| Payment log | `storage/logs/payments.log` |
| Plan config | `config/billing.php` |
| Integration docs | `docs/LENCO_INTEGRATION.md`, `docs/PAYMENT-CRON-SCHEDULER.md` |

---

## Post-launch (out of scope for this release)

- Card / LencoPay inline widget (when Lenco re-enables + `LENCO_PUBLIC_KEY`)
- Admin manual “mark payment complete” for offline Pro+ sales (manual credit add on user admin page remains)
- Bulk credit packs, refunds via Lenco API
