# Deploying a code update — checklist

Read this before uploading/pulling any change to eventhostzm.com (or any other production host).
It exists because skipping the steps below has caused real outages: on 2026-07-19 the `/contact`
page went down site-wide for over three weeks (`Target class [App\Http\Controllers\ContactController]
does not exist`) because new code was deployed without regenerating the Composer autoloader — the
class existed on disk but nothing told the optimized classmap it was there. The same failure mode
(class file present, autoloader stale) also took out the Vite build on day one and dompdf on 2026-05-24.
All three are the same mistake in different clothes — run every command below, every deploy, in order.

## 1. Every deploy — run these in order

```bash
composer install --no-dev --optimize-autoloader
composer deploy
```

`composer deploy` (see `composer.json`) runs `migrate --force`, `storage:link`, `optimize:clear`,
`config:cache`, `route:cache`, `view:cache` and `queue:restart` in that order — it's the second half
of this checklist as one command, so there's nothing left to mistype or forget. It cannot come first:
`composer install` has to finish before `composer deploy` (or any `artisan` command) can run at all,
since that's what generates the autoloader those commands depend on. If you'd rather run the steps
individually or see exactly what runs, the equivalent is:

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan storage:link
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

- **`composer install` must run first**, before any `artisan ... :cache` command. Route caching and
  config caching don't themselves fail if a class is missing, but the very next request that hits
  that class will 500 until the classmap is regenerated — that's exactly what happened with
  ContactController. Running it first removes the window entirely.
- `storage:link` is safe to re-run — Laravel prints "The [public/storage] link already exists." and
  exits cleanly if it's already there.
- `queue:restart` picks up code changes for already-running queue workers (Supervisor keeps them
  alive across the restart signal — see §"Queue workers" in
  [authentication-deployment.md](authentication-deployment.md)). Skipping this means workers keep
  running the *old* code in memory until something else restarts them.

If you only changed Blade/CSS/JS with no new PHP classes, the full sequence is still cheap enough
to run every time — don't try to guess which steps you can skip. Guessing wrong is exactly how the
ContactController outage happened.

## 2. When the change touches frontend assets (`resources/js`, `resources/css`, anything Vite bundles)

```bash
npm ci
npm run build
```

Run this **before** `php artisan view:cache` above if you're doing a one-shot deploy, so the first
cached view render already sees the new `public/build/manifest.json`. If Node isn't available on the
production host, build assets locally or in CI and upload the resulting `public/build/` directory —
either way, confirm `public/build/manifest.json` exists and is fresh *before* traffic hits the site.
A missing manifest is the `Vite manifest not found at: .../public/build/manifest.json` error.

## 3. Sanity check after every deploy

- [ ] Load `/` and `/contact` in a real browser (not just `curl` — confirm no 500, no stale-looking assets)
- [ ] Tail the log for a minute: `tail -f storage/logs/laravel.log` and click around the nav
- [ ] Confirm queue workers are alive: `php artisan queue:monitor` or check Supervisor status
- [ ] If migrations ran, spot-check one changed table in `php artisan tinker` or phpMyAdmin

## 4. If something in this checklist was skipped and a page is now 500ing

`Target class [...] does not exist` or `Class "..." not found` in `storage/logs/laravel.log` almost
always means step 1's `composer install --optimize-autoloader` didn't run after the class was added.
Run it now — it's safe to run at any time, not just right after a deploy — then clear + rebuild the
caches (`config:cache`, `route:cache`, `view:cache`) since a stale cache can also point at the old
state. This resolves the class-not-found family of errors without a code change; there is nothing to
patch in the repository for this specific failure mode.

See also: [authentication-deployment.md](authentication-deployment.md) for auth-specific env vars and
the Supervisor worker config, [payment-production-checklist.md](payment-production-checklist.md) for
the Lenco/billing-specific checklist.
