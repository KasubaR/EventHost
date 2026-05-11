# Authentication module — deployment notes

This project uses Laravel Breeze (Blade), email verification (`MustVerifyEmail`), queued notifications (`WelcomeNotification`, `EmailChangedNotification` on the `high` queue), and optional profile photo storage on the `public` disk.

## Environment

Set production-safe values in `.env`:

- `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://your-domain.example`
- `SESSION_DRIVER=database` (or `redis`), `SESSION_SECURE_COOKIE=true`, `SESSION_LIFETIME=120`
- `BCRYPT_ROUNDS=12`
- `QUEUE_CONNECTION=database` (upgrade to `redis` when available)
- Mail: `MAIL_MAILER`, `MAIL_HOST`, `MAIL_FROM_ADDRESS`, etc.

Password rules use `Password::uncompromised()` outside PHPUnit (Have I Been Pwned). Ensure outbound HTTPS is allowed from the app server, or adjust `AppServiceProvider` for isolated environments.

Registration and profile requests validate email with `email:rfc` so environments without reliable DNS/MX lookups still work in CI. For stricter production validation, consider adding DNS/MX checks via `Rule::email()` when appropriate.

## Commands after deploy

```bash
php artisan migrate --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
composer install --optimize-autoloader --no-dev
```

Restart queue workers after deploy (Supervisor example):

```ini
[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/artisan queue:work --sleep=3 --tries=3 --max-time=3600 --queue=high,default
autostart=true
autorestart=true
numprocs=2
redirect_stderr=true
stdout_logfile=/path/to/storage/logs/worker.log
```

Monitor `failed_jobs` and configure alerting for stuck jobs.

## Post-launch (not implemented here)

Per product roadmap: Laravel Socialite, Spatie Laravel Permission, S3-compatible disks for profile photos, Fortify two-factor authentication, and Sanctum-backed API versioning under `/api/v1`.
