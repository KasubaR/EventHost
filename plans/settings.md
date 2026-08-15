# Feature Plan: Account Settings

Status: **Shipped** (2026-08-15). Phase 1 (structure) and Phase 2 (§3, the notification split) are both done.

Three deviations from the plan below, all deliberate:

1. **Controllers live in an `App\Http\Controllers\Settings\` namespace** (`ProfileController`, `SecurityController`,
   `NotificationController`, `AccountController`) rather than flat `SettingsProfileController` classes — this
   matches the existing `Admin\` namespace convention. `routes/web.php` imports them aliased as
   `SettingsProfileController` etc. so the route definitions still read clearly.
2. **Notification saves flash `preferences-updated`**, a new key. §4 said to reuse `profile-updated`, but with
   the tabs split the notifications page needs its own confirmation — reusing the profile key would have left
   a preference save with no visible feedback on its own tab.
3. **`UpdateNotificationPreferencesRequest` gained a `preferences()` helper.** §3 only called for the validation
   rules to move. But `notification_preferences` needs an `array` rule to reject a scalar, and Laravel's
   `validated()` returns that whole attribute — unknown keys included. The helper narrows the array to keys in
   `DEFAULT_NOTIFICATION_PREFERENCES` before it reaches the service.

Phase 1 also fixed a live bug it inherited: on the old combined page, `delete-user-form`'s page-wide
`.profile-eye` binding ran after `update-password-form` had already bound the same buttons, so every click
fired two listeners and the three password eye-toggles were inert. Each script is now scoped to its own
form/modal.

The sidebar has advertised a **Settings** link with a "Soon" badge since the dashboard shell was built
([`layouts/app.blade.php:75-78`](../resources/views/layouts/app.blade.php)). This plan cashes that cheque.
Everything currently at `/profile` moves under `/settings` as tabbed sub-pages, `/profile` redirects, and
the duplicate "Profile" sidebar entry goes away.

The move is mostly mechanical, but it pays for itself by fixing a real wart on the way — see §3.

---

## 1. Decisions taken

| Question | Decision |
|---|---|
| Page structure | **Tabbed sub-pages** — each section is its own route, controller method and view |
| Old `/profile` URL | **301 redirect** to `/settings/profile`; the `profile.edit` route name survives as the redirect target so nothing 500s |
| Scope | **Profile only.** Billing and My Reviews stay as their own sidebar links |
| Sidebar | "Profile" entry is removed; "Settings" loses its `Soon` badge and becomes the single Account destination |

### Why tabs rather than one long page

Today all four cards stack on one page. Three concrete problems:

1. **Error bags scroll you into the wrong form.** A failed password change re-renders the whole page; the
   user lands at the top on Personal Information with the error 600px below the fold. Breeze's
   `validateWithBag('updatePassword')` exists precisely because everything shares one page.
2. **The notification form has to lie to the validator** (§3).
3. It only gets worse. Every setting added from here lengthens the same page.

Separate routes mean each form owns its page, `back()` returns where you were, and the error bags become
belt-and-braces rather than load-bearing.

---

## 2. Route map

`ProfileController` is renamed and split. New routes live in the existing `auth` + `verified` group in
[`routes/web.php:175-177`](../routes/web.php).

**Before**

```
GET    /profile   profile.edit      ProfileController@edit
PATCH  /profile   profile.update    ProfileController@update
DELETE /profile   profile.destroy   ProfileController@destroy
PUT    /password  password.update   Auth\PasswordController@update   (routes/auth.php:60)
```

**After**

```
GET    /settings                 settings.index          → redirect to settings.profile.edit
GET    /settings/profile         settings.profile.edit   SettingsProfileController@edit
PATCH  /settings/profile         settings.profile.update SettingsProfileController@update
GET    /settings/security        settings.security.edit  SettingsSecurityController@edit
GET    /settings/notifications   settings.notifications.edit    SettingsNotificationController@edit
PATCH  /settings/notifications   settings.notifications.update  SettingsNotificationController@update
GET    /settings/account         settings.account.edit   SettingsAccountController@edit
DELETE /settings/account         settings.account.destroy SettingsAccountController@destroy

GET    /profile                  profile.edit            → Route::redirect(301) to /settings/profile
PUT    /password                 password.update         unchanged — stays in routes/auth.php
```

Notes:

- **`password.update` does not move.** It lives in `routes/auth.php` with the rest of the Breeze auth
  scaffold, its controller already returns `back()`, and `back()` from `/settings/security` returns to
  `/settings/security`. Moving it would touch the auth scaffold for zero benefit. `SettingsSecurityController@edit`
  only renders the form.
- The `PATCH /profile` and `DELETE /profile` routes are **renamed, not redirected**. They are form targets
  used solely by our own views — a redirect cannot preserve a PATCH body anyway. The only external caller is
  [`tests/Unit/Services/ProfileServiceTest.php:21`](../tests/Unit/Services/ProfileServiceTest.php), updated in §7.
- Only `GET /profile` gets the 301, for bookmarks and any email that ever linked there.

---

## 3. The refactor this pays for: notification preferences

This is the one part of the move that is not cosmetic.

[`notification-preferences-form.blade.php:12-18`](../resources/views/profile/partials/notification-preferences-form.blade.php)
posts to `profile.update` and smuggles the user's current name and email through as hidden inputs:

```blade
<form method="post" action="{{ route('profile.update') }}" class="profile-form">
    {{-- carry over other fields unchanged --}}
    <input type="hidden" name="name" value="{{ auth()->user()->name }}">
    <input type="hidden" name="email" value="{{ auth()->user()->email }}">
```

They exist only to satisfy `UpdateProfileRequest`'s `required` rules on `name` and `email`. Consequences:

- Toggling "SMS reminders" runs the full profile update path, including the `email !== $oldEmail` comparison
  in [`ProfileService.php:36`](../app/Services/ProfileService.php).
- The user's email is round-tripped through a hidden field on every preference save. If those inputs ever
  drift from the stored value — a stale cached page, a tampered form — a preference toggle silently rewrites
  the account email, which nulls `email_verified_at` and fires `EmailChangedNotification`.

**Fix:** give preferences their own endpoint and FormRequest.

| New file | Contents |
|---|---|
| `app/Http/Requests/UpdateNotificationPreferencesRequest.php` | Only the `notification_preferences.*` boolean rules, built from `User::defaultNotificationPreferences()` exactly as `UpdateProfileRequest:22-27` does today |

Then:

- Remove the `$prefRules` block and its `array_merge` from `UpdateProfileRequest` — that request goes back to
  validating profile fields only.
- Move the pref-merging loop out of `ProfileService::update()` ([lines 25-34](../app/Services/ProfileService.php))
  into a new `ProfileService::updateNotificationPreferences(User $user, array $prefs): User`. Keep the
  merge-over-defaults behaviour: only keys present in the input are overwritten, so an added preference key
  defaults correctly for existing users.
- `ProfileService::update()` keeps its transaction, photo pipeline and email-change notification untouched.

Keep the hidden-`0`-before-checkbox toggle pattern ([line 30](../resources/views/profile/partials/notification-preferences-form.blade.php)).
It is documented in CLAUDE.md and is the only way an unticked box submits a value.

---

## 4. Views

```
resources/views/settings/
├── layout.blade.php          wraps <x-app-layout>, renders the tab strip + $slot
├── profile.blade.php
├── security.blade.php
├── notifications.blade.php
├── account.blade.php
└── partials/
    ├── profile-form.blade.php        ← from profile/partials/update-profile-information-form
    ├── password-form.blade.php       ← from profile/partials/update-password-form
    ├── notifications-form.blade.php  ← from profile/partials/notification-preferences-form
    ├── delete-account-form.blade.php ← from profile/partials/delete-user-form
    └── header-card.blade.php         ← the avatar/name/status block, profile/edit.blade.php:16-29
```

`resources/views/profile/` is deleted once the four partials have moved.

**The tab strip** is a `<nav>` inside the settings layout. Per the CLAUDE.md gotcha, `global.css` has a bare
`nav {}` rule, so the strip needs its own class (`.set-tabs`) with explicit overrides in the new stylesheet —
same pattern `.dash-nav` already follows.

Tabs mark themselves active with `request()->routeIs('settings.profile.*')` etc., mirroring the sidebar.

**The header card** (avatar, name, email, member-since, status badge) renders above the tab strip on every
sub-page, so the user keeps their bearings. It is read-only — the editable photo control stays in the profile
form.

**Flash messages.** The three existing `session('status')` checks — `profile-updated`, `password-updated`,
`verification-link-sent` — move with their partials unchanged. `SettingsProfileController@update` keeps
redirecting with `'profile-updated'` rather than inventing a new key, so nothing else has to change.

**The delete modal** in `delete-user-form.blade.php` is already self-contained (its own trigger, backdrop and
`profile-modal-form`). It moves verbatim; only `route('profile.destroy')` → `route('settings.account.destroy')`.

---

## 5. CSS

Per the CLAUDE.md table, page-specific styles get their own file pushed from the view.

| File | Change |
|---|---|
| `public/css/settings.css` | **New.** `.set-tabs` strip (incl. the bare-`nav` overrides), `.set-panel` spacing, horizontal scroll for the strip on mobile. Pushed via `@push('styles')` from `settings/layout.blade.php` |
| `public/css/forms-app.css` | **Unchanged.** `.profile-*` card, form, toggle and modal classes are reused as-is — they are documented as shared app form patterns and `layouts/app.blade.php` always loads this file |
| `public/css/dashboard-shell.css` | Drop `.dash-nav-soon` ([line 122](../public/css/dashboard-shell.css)). The Settings link is its only user — verified, no other view or stylesheet references it |

Reusing the `.profile-*` class names inside `settings/` views is deliberate. Renaming them to `.set-*` would
be a large diff across `forms-app.css` for no behavioural gain, and the classes are shared with event forms
already.

---

## 6. Navigation updates

Six call sites reference `route('profile.edit')`. All become `route('settings.profile.edit')`:

| File | Line | Change |
|---|---|---|
| [`layouts/app.blade.php`](../resources/views/layouts/app.blade.php) | 69-78 | Delete the Profile link. Point Settings at `settings.profile.edit`, drop the `Soon` badge, set active on `request()->routeIs('settings.*')` |
| [`components/site-header.blade.php`](../resources/views/components/site-header.blade.php) | 38 | Dropdown link → Settings |
| [`components/site-header.blade.php`](../resources/views/components/site-header.blade.php) | 70 | Mobile nav link → Settings |
| [`layouts/navigation.blade.php`](../resources/views/layouts/navigation.blade.php) | 37, 83 | **Unused Breeze leftover** — no view references `layouts.navigation`. Delete the file rather than updating it (verify with a grep first) |

---

## 7. Tests

[`tests/Feature/ProfileTest.php`](../tests/Feature/ProfileTest.php) hardcodes the `/profile` path in 11 places
(`get('/profile')`, `patch('/profile', …)`, `assertRedirect('/profile')`, `delete('/profile', …)`). Rename the
file to `SettingsTest.php` and repoint the paths. The assertions themselves — photo upload, photo replacement
deleting the old file, wrong-password deletion erroring in the `userDeletion` bag — all still hold.

[`tests/Unit/Services/ProfileServiceTest.php:21`](../tests/Unit/Services/ProfileServiceTest.php) posts to
`route('profile.update')` → `route('settings.profile.update')`.

**New coverage worth adding:**

- `GET /profile` returns a 301 to `/settings/profile`
- Each of the four tabs renders for a verified user and redirects a guest to login
- `GET /settings` redirects to `/settings/profile`
- Saving notification preferences **does not** change the user's email — the regression this refactor prevents,
  and the reason §3 is in scope
- A failed password change re-renders `/settings/security`, not the profile tab

---

## 8. Phases

**Phase 1 — routes, controllers, views.** Split `ProfileController` into the four settings controllers, add
the routes, move the four partials, build the tab strip and `settings.css`, update the six nav links, add the
`/profile` redirect. Repoint the tests. At the end of this phase the feature is complete and shippable.

**Phase 2 — the notification split (§3).** New `UpdateNotificationPreferencesRequest`, new service method,
drop the hidden `name`/`email` inputs, thin out `UpdateProfileRequest`. Add the "prefs don't touch email" test.

Phase 2 is separable and can ship a day later, but do not skip it — the hidden inputs are the reason this
plan is worth more than a URL rename.

---

## 9. Deliberately out of scope

- **Billing and My Reviews** stay put, per the scope decision.
- **No new settings.** Timezone, language, 2FA, active-session management and API tokens are all things a
  settings page usually grows. None exist today; this plan moves what is there and no more. `/settings/security`
  is the natural home for 2FA later — note that Sanctum tokens already expire after 7 days
  (`config/sanctum.php`), so a token-management tab would need that surfaced.
- **No schema change.** Nothing in this plan touches a migration.
- **`status`, `last_login_at`, `last_login_ip`** stay read-only. The status badge already renders in the header
  card; login metadata is admin-facing.

---

## 10. Deployment

No migration, no seeder, no `composer`/`npm` change — this is PHP and Blade plus one new CSS file in
`public/css/`, which is served directly and not built by Vite.

```bash
cd ~/public_html && git pull origin main
```

If config or view caching is enabled in production, clear the view cache afterwards since Blade files moved:

```bash
php artisan view:clear
```

Then load `/profile` on the live site and confirm it lands on `/settings/profile`.
