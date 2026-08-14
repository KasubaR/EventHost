# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# Start full dev environment (server + queue + logs + vite, all concurrently)
composer dev

# First-time setup
composer setup

# Run tests
composer test

# Run a single test file
php artisan test --filter=TestClassName

# Build assets
npm run build

# Lint PHP with Pint
./vendor/bin/pint

# Run migrations
php artisan migrate

# Create storage symlink (needed for profile photo uploads)
php artisan storage:link
```

## Architecture Overview

Laravel 12 application. Auth via Laravel Breeze (Blade stack). No Alpine.js — all interactivity is vanilla JS. Tailwind is installed but barely used; all real styling is in custom CSS files under `public/css/`.

### CSS Design System

**Global and shared components** (always load in this order where applicable):

| File | Purpose |
|---|---|
| `public/css/global.css` | Design tokens (`:root`), resets, `body`, site `nav {}`, footer, shared buttons (including `.btn-hero-*` for reuse) |
| `public/css/account-components.css` | Breeze/guest control styles (`eh-*`), site header nav extras, legacy account shell |
| `public/css/dashboard-shell.css` | Authenticated chrome only: `.dash-*` sidebar and layout |
| `public/css/forms-app.css` | Profile and shared app form/card patterns (`.profile-*`, modals — also used by event forms) |

**Page-specific** (push from views or layouts):

| File | Loaded by |
|---|---|
| `public/css/home.css` | `home.blade.php` via `@push('head')` — marketing landing sections only |
| `public/css/auth.css` | `login` / `register` via `@push('head')` — auth hero + `.auth-*` fields |
| `public/css/dashboard-home.css` | `dashboard.blade.php` via `@push('styles')` — overview stats / empty state |
| `public/css/events-admin.css` | Event CRUD views (`events/*` except public) via `@push('styles')` |
| `public/css/events-public.css` | `events/public.blade.php` — public invitation page |
| `public/css/datetime-picker.css` | Custom date/time picker (`.dtp-*`) — pair with `js/datetime-picker.js` |
| `public/css/custom-select.css` | Custom dropdown (`.cs-*`) — pair with `js/custom-select.js` |

Layouts: `layouts/site.blade.php` loads `global.css` + `account-components.css` + Vite; `layouts/app.blade.php` adds `dashboard-shell.css` + `forms-app.css`. Tailwind ships via Vite (`resources/css/app.css`) alongside these files.

### Custom Form Controls

Two reusable, dependency-free controls that progressively enhance native inputs. The native `<input>` / `<select>` stays in the DOM with its `name`, `value` and `required` intact, so controllers, validation and `old()` repopulation are unchanged — only the UI is replaced.

| Control | Opt in | Notable options |
|---|---|---|
| `js/datetime-picker.js` | `data-dtp` on `type="date"`, `time` or `datetime-local` | `data-minute-step` (default 5), `data-hour-format="24"`, `data-week-start="1"`, `data-placeholder`; native `min`/`max` are honoured and also accept `today` / `now` |
| `js/custom-select.js` | `data-cs` on any `<select>` (incl. `multiple`) | `data-cs-search="auto\|always\|never"`, `data-cs-placeholder`, `data-cs-icon`, `data-cs-size="sm"`; per-option `data-icon` / `data-hint`; `<optgroup>` supported |

Both auto-initialise on `DOMContentLoaded`; call `DateTimePicker.refresh(root)` / `CustomSelect.refresh(root)` after injecting markup dynamically.

**Two gotchas worth keeping:**

1. Panels are portalled to `<body>` with `position: fixed` because `.evt-section` sets `overflow: hidden` — an absolutely-positioned panel inside the section would be clipped.
2. The native control is hidden with `opacity: 0` over the trigger's own box, never `display: none`. A `display: none` control makes Chrome throw "An invalid form control is not focusable" and silently block submission; keeping it sized means validation bubbles still point at the visible trigger.

**Critical gotcha:** `global.css` has a bare `nav {}` rule that targets every `<nav>` element, including the sidebar's `<nav class="dash-nav">`. Overrides live in `dashboard-shell.css` (`.dash-nav`). Keep that pattern when adding new `<nav>` elements inside the app shell.

### Blade Layouts

- `layouts/site.blade.php` — public-facing pages; loads `global.css` + `account-components.css` + Vite; `@stack('head')` for page CSS; includes `<x-site-header />` and `<x-site-footer />`
- `layouts/guest.blade.php` — Breeze card flows (verify email, password reset); same CSS base as site minus marketing pushes
- `layouts/app.blade.php` — dashboard shell; sticky sidebar + main content area; supports named slots `$title`, `$pageHeader`, `$slot`

### Routing

- `/` → `home` view (public)
- `/dashboard` → `DashboardController@index` (auth + verified)
- `/profile` → `ProfileController` PATCH/DELETE (auth + verified)
- Auth routes in `routes/auth.php` — standard Breeze scaffold + `PUT /password` for password updates

### User Model

`App\Models\User` implements `MustVerifyEmail`. Extra columns beyond standard Laravel:

- `phone`, `company_name`, `profile_photo` (path relative to `storage/app/public/`)
- `notification_preferences` — JSON cast, keyed array of booleans, defaults set in `booted()` via `DEFAULT_NOTIFICATION_PREFERENCES` constant
- `status` — enum: `pending` | `active` | `suspended`, defaults to `pending`
- `last_login_at`, `last_login_ip`
- `profile_photo_url` accessor returns `storage/` URL or `public/images/default-avatar.png` fallback

### Profile Update Flow

Single `PATCH /profile` handles both personal info and notification preferences:

1. `UpdateProfileRequest` validates all fields including `notification_preferences.*` as booleans; uses `email:rfc`
2. `ProfileService::update()` runs inside a DB transaction; converts photo to 400×400 WebP via `intervention/image` (uses Imagick if available, falls back to GD); deletes old photo after commit; sends `EmailChangedNotification` to old address when email changes
3. The notification preferences toggle pattern uses a hidden input (value `0`) immediately before the checkbox (value `1`) — the last submitted value wins

### Registration Flow

`RegisteredUserController::store()`:
1. Creates user (notification prefs set automatically in `User::booted()`)
2. `event(new Registered($user))` — triggers the signed verification email
3. `$user->notify(new WelcomeNotification)` — separate welcome-only email (no verify link)
4. Redirects to `verification.notice`

### Sanctum

API tokens expire after 10080 minutes (7 days). `sanctum:prune-expired --hours=24` runs daily via the scheduler (`routes/console.php`).

### PHP Extensions Required

This app requires the **GD** extension (or Imagick) for image processing (profile photos, event cover images).

**Local (XAMPP):**
1. Open `C:\xampp\php\php.ini`
2. Find `;extension=gd` and remove the leading `;`
3. Restart Apache in the XAMPP Control Panel
4. Verify: `php -r "echo extension_loaded('gd') ? 'ok' : 'missing';"`

**cPanel (production):**
1. Log in to cPanel → find **"Select PHP Version"** (or "PHP Selector")
2. Make sure the PHP version matches the project requirement (8.2+)
3. Click **"Extensions"** — find `gd` in the list and tick the checkbox to enable it
4. Click **Save** / **Apply**
5. If `imagick` is available in the list, enabling it is preferred over GD for better image quality

### Event Credits (Payments)

Users have an `event_credits` column. Each event creation costs 1 credit (`User::canCreateEvent()` checks `event_credits > 0`, controller decrements on success). Admins assign credits manually via the user show page in the admin panel.

When payments are implemented, call `$user->increment('event_credits')` in the payment webhook and it will plug straight in.

### Asset Bundling

Vite bundles `resources/css/app.css` (Tailwind) and `resources/js/app.js`. These are loaded with `@vite()` in the layouts. The custom CSS files in `public/css/` are loaded directly with `<link>` tags — they are not processed by Vite.

`public/js/homepage.js` is loaded with a plain `<script src>` tag (not Vite). It handles: filter tabs, FAQ accordion, chart bar animations, and password eye-toggle for auth pages (`.auth-eye` class).
