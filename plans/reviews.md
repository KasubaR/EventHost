# Feature Plan: Customer Reviews (text + video)

Status: **Phase 1 and Phase 2 shipped** (2026-08-14). Phase 1: text reviews end to end — hosts submit from
`/reviews`, admins moderate and feature at `/admin/reviews`, and the homepage strip is now driven by
`Review::featuredForHomepage()`. Phase 2: admin-authored video reviews — the "Add a video review" form,
click-to-play cards, and poster upload/replace/remove. As planned it needed **no schema change**; the
`video_ref` / `video_poster` columns shipped in the phase 1 migration.

Two deviations from the plan below, both deliberate:

1. **No separate "Video reviews" section** in the admin panel (§4 proposed one). Video reviews are created
   approved, so they already appear under **Published** with an "Added by admin" badge; a fourth section
   would have listed the same rows twice. The "Add a video review" form sits above the status groups.
2. `InvitationVideoBackground` gained a second embed builder, `playerEmbedUrl()` — the existing
   `embedUrl()` is muted, looping and chrome-less because it plays behind an invitation hero, which is
   wrong for a testimonial someone clicks to watch.

The three fictional testimonials the homepage used to hardcode were **not** seeded into the new table —
see the flag in §1.

Three things ship as one feature:

1. **Hosts leave reviews from their portal** — one review per event they hosted, unlocked once the event
   date has passed.
2. **Admins moderate and feature reviews** — approve/reject the queue, pick which reviews appear on the
   homepage and in what order.
3. **Admin-authored video reviews** — the admin adds video testimonials directly and features them
   alongside text reviews. Users never upload video.

---

## 1. Decisions taken

| Question | Decision |
|---|---|
| Who can review, how often | One review per hosted event, once `event_date` has passed |
| Who adds video reviews | **Admin only** — no user-facing video upload anywhere |
| Where reviews appear publicly | Homepage `#testimonials` strip only (replaces the three hardcoded cards) |

### Open assumption — how video is stored

Because video is admin-only and curated, the plan assumes **a pasted YouTube URL**, normalized and
validated by the `App\Support\InvitationVideoBackground` helper that already exists for invitation hero
backgrounds. It parses `youtu.be`, `?v=`, `/embed/`, `/shorts/` and bare 11-char IDs, stores them as
`youtube:<id>`, and builds a `youtube-nocookie.com` embed URL. Reusing it means zero new video code.

The alternative — self-hosted MP4 upload — costs more than it looks: `upload_max_filesize` and
`post_max_size` default to 2 MB on most cPanel accounts and would need raising, there is no `ffmpeg` on
shared hosting so poster frames can't be generated automatically, and video files never shrink the way
the WebP pipeline shrinks images. If self-hosting is wanted anyway, only §2's `video_ref` column and the
admin request rules change; nothing else in this plan moves.

The admin can also upload a **poster image** for each video review (cropped to 16:9 WebP), so the
homepage card shows a still frame instead of an iframe until it's clicked.

### Flag: don't seed the existing testimonials as real reviews

The three cards currently in `home.blade.php:240-269` are attributed to named people
("Namwali Musonda — Wedding · Lusaka") with Unsplash stock portraits. `FaqSeeder` set the precedent of
seeding the copy a view used to hardcode, but doing that here would put fabricated customer reviews into
a table whose whole purpose is holding real ones — and they'd be indistinguishable from genuine
submissions afterwards.

Recommendation: **do not seed them**. The section hides itself when nothing is featured (same as the
featured-templates strip), so the homepage degrades cleanly until the first real review is approved. If
any of that copy came from a real customer, the admin can re-add it through the new panel. `ReviewSeeder`
is therefore listed as **local-development-only**, guarded to non-production, feeding `ReviewFactory`.

---

## 2. Data model

### New table: `reviews`

One table holds both kinds of review; `source` says who wrote it and `media_type` says how it renders.

| column | type | notes |
|---|---|---|
| id | bigint pk | |
| user_id | FK → users, nullable, cascade delete | null for admin-authored reviews; cascade so a deleted account takes its reviews with it |
| event_id | FK → events, nullable, cascade delete | the event being reviewed; null for admin-authored |
| source | string(10) — `user` \| `admin` | explicit rather than inferred from `user_id`, so validation and admin filters can key on it |
| media_type | string(10) — `text` \| `video` | user submissions are always `text` |
| rating | unsigned tinyint, nullable | 1–5, required for user reviews, optional on admin video cards |
| body | text | the quote itself |
| author_name | string | denormalized at submit time |
| author_context | string, nullable | e.g. "Wedding · Lusaka" |
| author_photo | string, nullable | storage path; admin-uploaded avatar |
| video_ref | string, nullable | `youtube:<id>`, normalized by `InvitationVideoBackground` |
| video_poster | string, nullable | storage path, 16:9 WebP still |
| status | string(10) — `pending` \| `approved` \| `rejected` | user submissions start `pending`, admin-authored start `approved` |
| moderation_note | text, nullable | shown back to the author when rejected |
| is_featured | boolean, default false | |
| featured_sort_order | unsigned smallint, default 0 | |
| approved_at | timestamp, nullable | audit trail |
| timestamps | | |

Indexes:

- `['status', 'is_featured', 'featured_sort_order']` — the homepage query
- `unique(['user_id', 'event_id'])` — enforces one review per hosted event

**Subtlety worth keeping in a comment:** MySQL treats `NULL`s as distinct in a unique index, so every
admin-authored row (both columns null) coexists happily under that same unique constraint. It only binds
real user submissions, which is exactly the intent.

`author_name` / `author_context` are **denormalized on purpose**. The homepage then renders without
joining `users` and `events`, and a host later renaming their profile doesn't silently rewrite a published
testimonial. It also lets the admin fix "Namwali M." → "Namwali Musonda" without touching the account.

### New enums

`app/Enums/ReviewStatus.php` and `app/Enums/ReviewMediaType.php`, both string-backed with a `label()`
method — same shape as the existing `app/Enums/PhotoStatus.php`.

### Model relations

- `User::reviews(): HasMany`
- `Event::review(): HasOne` — an event has at most one
- `Review::user()` / `Review::event()` — both `BelongsTo`, nullable

### `Review` constants and scopes (mirrors `Faq` and `InvitationTemplate`)

```php
public const HOMEPAGE_FEATURED_LIMIT = 6;   // two rows of the existing 3-up .testi-grid

public function scopeFeaturedForHomepage(Builder $query): void
```

The scope filters `status = approved` **and** `is_featured` **and** — for `media_type = video` — requires
a non-empty `video_ref`. That last clause mirrors the existing "a template cannot be featured without an
image" rule, which is enforced both in `UpdateInvitationTemplateRequest` and again in the scope. Belt and
braces in the same two places here.

### Eligibility

`Event::isReviewable(): bool` — true when the event is published, `event_date` is in the past, and no
review exists yet. Note that **the purchase gate is already implicit**: creating an event costs an
`event_credits` credit (`User::canCreateEvent()`), so any user with a reviewable event has necessarily
paid. No separate check against `payments` is needed, and adding one would wrongly exclude users given
credits manually by an admin.

---

## 3. User portal

### Routes — `routes/web.php`, inside the existing `['auth', 'verified']` group

```php
Route::get('/reviews', [ReviewController::class, 'index'])->name('reviews.index');
Route::post('/reviews', [ReviewController::class, 'store'])->name('reviews.store')->middleware('throttle:10,1');
Route::patch('/reviews/{review}', [ReviewController::class, 'update'])->name('reviews.update');
Route::delete('/reviews/{review}', [ReviewController::class, 'destroy'])->name('reviews.destroy');
```

### `App\Http\Controllers\ReviewController`

- `index()` — lists the user's past events, each either with its submitted review (and its status) or a
  "Write a review" form. Empty state when they have no past events yet.
- `store()` — validates via `StoreReviewRequest`, snapshots `author_name` from the user and
  `author_context` from the event (`"{$event->event_type_label} · {$event->location}"`), sets
  `source = user`, `media_type = text`, `status = pending`.
- `update()` / `destroy()` — the host can edit or withdraw their own.

**Editing an approved review resets it to `pending` and clears `is_featured`.** Otherwise a host could
get a bland review approved and featured, then rewrite it into anything at all on a live homepage.
Call that out in the UI copy so the reset isn't a surprise.

### `App\Policies\ReviewPolicy`

`update` / `delete` = `$review->user_id === $user->id`. Registered the same way the seven existing
policies are; the controller uses `authorizeResource`, matching `EventController:25`.

### Views and styling

- `resources/views/reviews/index.blade.php`, using `layouts/app.blade.php`.
- Reuses the `.profile-*` card and form patterns already in `public/css/forms-app.css` — that file is
  explicitly the shared app form/card pattern, so a new page-specific CSS file is only needed for the
  star-rating input.
- New `public/css/reviews.css` pushed via `@push('styles')`, for the star picker and status pills only.
- Star rating input: five radio inputs styled as stars, no JS dependency — matches the "no Alpine, vanilla
  only" rule and keeps it keyboard-accessible for free.

### Sidebar

Add to `layouts/app.blade.php`, in the existing **Account** section next to Profile:

```blade
<a href="{{ route('reviews.index') }}" class="dash-nav-link {{ request()->routeIs('reviews.*') ? 'is-active' : '' }}">
    <i class="fa-solid fa-star"></i> My Reviews
</a>
```

---

## 4. Admin panel

### Permission

Add `reviews.manage` to `RolePermissionSeeder::PERMISSIONS`. It lands on `admin` and `super_admin`
automatically (admin gets everything except `users.delete`). `support` has view-only permissions and is
**not** given this one — worth confirming, since support staff are the people most likely to be triaging
a moderation queue. If they should triage, add a separate `reviews.view` to the support list.

### Routes — `routes/admin.php`, mirroring the FAQ block exactly

```php
Route::middleware('permission:reviews.manage,admin')->group(function (): void {
    Route::get('/reviews', [AdminReviewController::class, 'index'])->name('reviews.index');
});

Route::middleware(['permission:reviews.manage,admin', 'throttle:admin-mutations'])->group(function (): void {
    Route::post('/reviews', [AdminReviewController::class, 'store'])->name('reviews.store');
    Route::patch('/reviews/{review}', [AdminReviewController::class, 'update'])->name('reviews.update');
    Route::delete('/reviews/{review}', [AdminReviewController::class, 'destroy'])->name('reviews.destroy');
    Route::delete('/reviews/{review}/poster', [AdminReviewController::class, 'destroyPoster'])->name('reviews.poster.destroy');
});
```

### `App\Http\Controllers\Admin\ReviewController`

Single-page CRUD like `Admin\FaqController`, with the list grouped into three sections:

1. **Pending** — the moderation queue: approve, reject with a note, edit attribution before approving.
2. **Approved** — feature/unfeature, set `featured_sort_order`, unpublish, delete.
3. **Video reviews** — the admin-authored ones, plus the "Add a video review" form.

Every mutation calls `AdminActivity::log(...)`, matching `FaqController` and `InvitationTemplateController`.

Image handling for `author_photo` and `video_poster` copies
`InvitationTemplateController::storePreviewImage()` verbatim in shape — Imagick when loaded, GD otherwise,
`->cover()` to a fixed size, WebP at 85, `uniqid()` filename on the `public` disk, and the old file
deleted in a `DB::afterCommit()` callback only once the new path is committed. Sizes: `88×88` for the
avatar (matching `.testi-av img` in `home.css:733`), `640×360` for the poster.

### Requests

- `Admin\ReviewRequest` — for admin-authored video reviews: `body`, `author_name`, `author_context`,
  `video_ref` (validated through `InvitationVideoBackground::normalizeUserInput()`, rejected when it
  returns null), optional `author_photo` / `video_poster` uploads.
- `Admin\UpdateReviewRequest` — moderation: `status`, `moderation_note`, `is_featured`,
  `featured_sort_order`, plus editable attribution. Enforces that a video review can't be featured with
  no `video_ref`, and that `moderation_note` is required when `status = rejected`.

### View

`resources/views/admin/reviews/index.blade.php`. This page carries many forms at once, exactly like the
FAQ admin page, so **reuse the `$oldFor()` closure trick** from `admin/faqs/index.blade.php` — hidden
`_form` / `_review_id` fields scoping `old()` repopulation to the form that actually failed validation.
Without it, one failed edit repopulates every form on the page.

Sidebar entry in `layouts/admin.blade.php`, after FAQs:

```blade
@if(auth('admin')->user()?->can('reviews.manage'))
    <a href="{{ route('admin.reviews.index') }}" class="dash-nav-link {{ request()->routeIs('admin.reviews.*') ? 'is-active' : '' }}">
        <i class="fa-solid fa-star"></i> Reviews
    </a>
@endif
```

---

## 5. Homepage

`HomeController::index()` gains a third curated query, alongside the featured templates and FAQs it
already runs:

```php
$featuredReviews = Review::query()
    ->featuredForHomepage()
    ->limit(Review::HOMEPAGE_FEATURED_LIMIT)
    ->get();
```

`home.blade.php:232-272` loses its three hardcoded `.testi-card` blocks and loops instead, with the whole
`#testimonials` block wrapped in `@if ($featuredReviews->isNotEmpty())` — same hide-when-empty treatment
the templates strip and FAQ block already get.

The existing card markup (`.testi-card` / `.stars` / `.testi-author` / `.testi-av` / `.testi-info`) stays
as-is for text reviews, so `home.css:690-740` needs no changes for phase 1.

**Video card variant** (`.testi-card.is-video`): the poster image with a play button overlay, the quote
beneath it, the same author row. Clicking swaps the poster for the `youtube-nocookie` iframe built by
`InvitationVideoBackground::embedUrl()` — click-to-play, so no third-party iframe loads on first paint and
the homepage stays fast. That toggle goes into the existing `public/js/homepage.js` (which already handles
the FAQ accordion, chart animations and the auth eye-toggle) rather than a new file.

Answers render with `{{ }}`, never `{!! !!}` — same rule the FAQ implementation follows, and it matters
more here because this text comes from users rather than admins.

---

## 6. Files

**New**

| Path | |
|---|---|
| `database/migrations/2026_08_14_120000_create_reviews_table.php` | |
| `app/Models/Review.php` | |
| `app/Enums/ReviewStatus.php`, `app/Enums/ReviewMediaType.php` | |
| `app/Policies/ReviewPolicy.php` | |
| `app/Http/Controllers/ReviewController.php` | |
| `app/Http/Controllers/Admin/ReviewController.php` | |
| `app/Http/Requests/StoreReviewRequest.php` | |
| `app/Http/Requests/Admin/ReviewRequest.php`, `Admin/UpdateReviewRequest.php` | |
| `resources/views/reviews/index.blade.php` | |
| `resources/views/admin/reviews/index.blade.php` | |
| `public/css/reviews.css` | |
| `database/factories/ReviewFactory.php`, `database/seeders/ReviewSeeder.php` | seeder is dev-only, see §1 |

**Modified**

`routes/web.php` · `routes/admin.php` · `app/Http/Controllers/HomeController.php` ·
`app/Models/User.php` · `app/Models/Event.php` · `resources/views/home.blade.php` ·
`resources/views/layouts/app.blade.php` · `resources/views/layouts/admin.blade.php` ·
`database/seeders/RolePermissionSeeder.php` · `database/seeders/DatabaseSeeder.php` ·
`public/js/homepage.js` · `public/css/home.css` (video variant, phase 2) · `CLAUDE.md`

---

## 7. Tests

| File | Covers |
|---|---|
| `tests/Feature/ReviewSubmissionTest.php` | a host can review a past event; cannot review a future one; cannot review twice; cannot review another user's event; editing an approved review resets it to pending and unfeatures it |
| `tests/Feature/AdminReviewTest.php` | permission gate (`reviews.manage`); approve/reject with note; feature/unfeature and ordering; admin-authored video review created and normalized from a pasted YouTube URL; a video review can't be featured without a `video_ref`; poster upload replaces and deletes the old file |
| `tests/Feature/FeaturedReviewsOnHomepageTest.php` | featured approved reviews render; pending/rejected/unfeatured ones don't; the section is hidden entirely when the collection is empty; the limit is respected |

These mirror the existing `AdminFaqTest` / `FaqOnPublicPagesTest` / `FeaturedTemplatesOnHomepageTest`
trio, which is the same three-way split of concerns.

---

## 8. Phasing

**Phase 1 — text reviews end to end.** Migration, model, enums, policy, portal page, admin moderation and
featuring, homepage loop replacing the hardcoded cards. Shippable on its own: the homepage strip becomes
admin-curated and real.

**Phase 2 — video reviews.** `video_ref` / `video_poster` put to work: the admin "Add a video review" form,
the `.testi-card.is-video` variant, the click-to-play toggle in `homepage.js`.

Both columns land in the phase 1 migration so phase 2 adds no schema change.

### Phase 2 — what shipped

- `Admin\StoreReviewRequest` + `Admin\ReviewController::store()` — creates the review as `admin`/`video`,
  already approved (the admin is the moderator, so there is no queue to join), normalizing the pasted link
  through `InvitationVideoBackground::normalizeUserInput()`
- Poster and reviewer-photo uploads reuse the `InvitationTemplateController::storePreviewImage()` shape —
  Imagick when loaded, GD otherwise, `cover()` to 640×360 / 88×88, WebP at 85 on the `public` disk, old
  file dropped in `DB::afterCommit()` only once the new path is committed
- `destroyPoster()` clears the still without unfeaturing the review, unlike the template equivalent: a
  template with no image cannot render a card at all, whereas a video review just falls back to a plain
  play button
- On edit, a blank link keeps the stored video — the admin is usually there to fix wording. The
  "cannot feature a video review without a video" rule now also accepts a link supplied in the same request
- Homepage cards render a poster and a button carrying the embed URL in `data-testi-video`; `homepage.js`
  builds the iframe on click, so the page ships with no third-party frame and none loads until asked
- Verified in the browser: 16:9 box, zero iframes on first paint, one iframe with the `youtube-nocookie`
  src after a click, focus moved to the frame, sibling cards untouched, and the poster-less fallback
  rendering as a play button on the navy ground

---

## 9. Documentation

Add a `### Reviews (homepage testimonials)` section to `CLAUDE.md`, next to the existing
"Featured Templates" and "FAQs" sections, recording: the two review sources and how `source` /
`media_type` separate them; the one-per-hosted-event unique index and its NULL behaviour; that video is
admin-only and stored as a `youtube:<id>` reference through `InvitationVideoBackground`; that editing an
approved review resets it to pending; and that the homepage section hides itself when nothing is featured.
