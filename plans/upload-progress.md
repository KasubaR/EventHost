# Feature Plan: Upload images on pick, not on save

Status: **Shipped** (2026-08-15). All four phases done; 25 new tests, full suite green at 390.

Five deviations from the plan below, all deliberate:

1. **Staging caps count staged rows only, not saved + staged.** §3 said to count both. That rejects the
   ordinary "remove three, add three" edit, because the staging endpoint cannot see removals the open
   form has not submitted yet. The cap at pick time is now a per-slot ceiling on queued files; the
   authoritative saved + staged − removed check runs at save. Over-staging costs bounded, pruned disk.
2. **`InvitationMediaStager` handles the cover too**, converting to WebP synchronously.
   `EventController::storeCoverImage()` now delegates to it, so a cover uploaded on pick and one uploaded
   with the form are byte-for-byte the same operation.
3. **The abandoned-staged sweep lives inside `invitation:prune-orphaned-files`**, not a new command — same
   concern, same schedule slot, and running it first means files it stops protecting become eligible for
   the orphan sweep in the same pass.
4. **`setBusy()` in `event-edit-save.js` gained a repeat-label fix.** Its old guard captured
   `originalHtml` and set the label in one branch, so a second call could not change the text — the save
   now passes through "Finishing uploads…" before "Saving…".
5. **`audio_track` was wired after all** (§6 called it take-it-if-free). It fell out of the slot
   machinery for the cost of one blade attribute.

Two things the plan asked for that are **not** done, both from §6/§7: the create page still posts its
cover with the form (no event id exists yet to scope an upload to), and the four non-event upload sites
(profile photo, admin template previews, admin review posters, guest CSV) are untouched.

Right now every image on the event edit page travels as a binary inside the big save/publish POST. The
user picks six gallery photos, carries on editing, hits **Save**, and only then does 30 MB start moving —
behind a single `Saving…` spinner with no progress and no per-file feedback.

This plan moves the transfer to the moment the file is chosen, with a real progress bar per image, so
Save posts nothing but text and finishes instantly.

---

## 1. Why the current behaviour is worse than it looks

| Evidence | Where |
|---|---|
| Every file input is a plain `<input type="file">` inside the design form — nothing intercepts it | [invitation-design-form.blade.php:598](../resources/views/events/partials/invitation-design-form.blade.php), `:653`, `:683`, `:736`, `:812` |
| Save-all posts the whole `FormData` in one `fetch()` and shows one indeterminate spinner | [event-edit-save.js:78](../public/js/event-edit-save.js), `:63` |
| `fetch()` cannot report upload progress at all — there is no byte-level event to hook | — |
| Files are only written to disk once inside the save transaction | [EventInvitationDesignController.php:105](../app/Http/Controllers/EventInvitationDesignController.php), `:163`, `:189`, `:209` |
| **`post_max_size=40M`, but the validation caps allow ~45 MB** — 6 gallery × 5 MB + hero 5 MB + 4 couple × 5 MB. A maxed-out save exceeds the PHP limit, arrives as an empty POST, loses its CSRF token and dies as a bare **419** with no useful message | `php.ini` vs [UpdateInvitationDesignRequest.php:163-231](../app/Http/Requests/UpdateInvitationDesignRequest.php) |

That last row is a live bug, not just a UX complaint. Per-file staging fixes it as a side effect, because
no single request ever carries more than one image.

The pattern already exists in this codebase — [table-upload.js](../public/js/table-upload.js) uploads guest
photos one at a time with a per-tile `Ready → Uploading… → Added` badge. This plan generalises that and
moves it earlier (on pick, not on submit).

---

## 2. Decisions taken

| Question | Decision |
|---|---|
| How does the file reach the server early? | A **staging endpoint** — one request per file, fired on `change`/drop |
| How does Save find it again? | The JS appends `<input type="hidden" name="staged_media[]" value="{id}">`; the controller resolves ids against a `staged_media` table scoped to the event **and** the user, so a path can never be forged |
| Progress source | **`XMLHttpRequest` + `xhr.upload.onprogress`**, not `fetch`. This is the only reason to reach for XHR in 2026, and it is the right one — `fetch` gives no upload progress without request streams |
| Where do staged files land? | Straight into their **final** directory (`invitation-gallery/{id}/gal_src_*`, etc.), not a temp dir. Save then writes zero bytes — it only records the path |
| No-JS behaviour | The native inputs stay in the DOM and still submit binaries. The controller keeps both branches. Same progressive-enhancement contract as `event-edit-save.js`, which only hides the per-form buttons once it has booted |
| WebP conversion | **Stays where it is** — dispatched after the save commits. A staged tile goes to `Ready`, not `Optimising…`. Reworking `ProcessInvitationDesignImageJob` is not worth it; its `$stillReferenced` guard is built around the saved customization array |
| Scope of "all templates" | Slot-driven, not template-driven — one uploader serves all 8 layout variants because the slots are already computed by `InvitationLayoutVariant` |

---

## 3. Phase 1 — the staging endpoint

**Migration:** `staged_media`

| Column | Notes |
|---|---|
| `id` | |
| `event_id` | FK, cascade delete |
| `user_id` | FK — resolution is scoped to both, so ids from another session are invisible |
| `slot` | `gallery` \| `hero_portrait` \| `couple` \| `speaker:{0..3}` \| `cover` \| `audio` |
| `path` | relative path on the `public` disk |
| `original_name`, `bytes` | for the tile label and the total-size cap |
| `created_at` | drives pruning |

**Route** (inside the existing auth + verified group, next to the design routes at
[routes/web.php:162](../routes/web.php)):

```
POST /events/{event}/invitation-design/media   → EventInvitationMediaController@store
DELETE /events/{event}/invitation-design/media/{staged}  → @destroy
```

`@destroy` backs the tile's remove button — it deletes the row and the file so a discarded pick does not
sit around for a day waiting on the pruner.

**Its own rate limiter.** `throttle:invitation-design` is sized for form saves; per-file uploads would
burn through it. Add `invitation-media` alongside it in
[AppServiceProvider.php:40](../app/Providers/AppServiceProvider.php) — something like 60/min per user.

**`StoreStagedMediaRequest`** validates exactly one file with the rules already written for that slot in
`UpdateInvitationDesignRequest` (`image`, `mimes:jpeg,jpg,png,webp,gif`, `max:5120`; audio gets
`mimes:mp3,mpeg,ogg,wav`). Extract them to constants on a shared class so the two requests cannot drift.

It also **enforces the caps at pick time** — max 6 gallery, `maxCouplePhotoSlots()` couple photos,
`maxInvitationHeroPortraitSlots()` for the hero — counting saved media **plus** unconsumed staged rows.
Telling the user "that's the seventh gallery image" while they are still picking is most of the point.

**Storage** reuses `storeInvitationRasterOriginal()` from the controller — move it into a small
`App\Support\InvitationMediaStager` so both the staging endpoint and the legacy save path call the same code.

Returns `{ id, slot, url, name, bytes }`.

### The pruner has to learn about staged rows

[`PruneOrphanedInvitationFilesCommand`](../app/Console/Commands/PruneOrphanedInvitationFilesCommand.php)
runs daily and deletes anything in `invitation-gallery|hero|couple|media` that no event's customization
references and that is older than the 60-minute grace window. **Staged files match that description
exactly.** Leave it alone and a user who stages photos and then goes to lunch comes back to broken images.

Fix: add unconsumed `staged_media` paths to `buildReferencedPathSet()`. One extra query.

Then add the opposite sweep — staged rows older than 24 h are abandoned; delete row and file. Either a new
`media:prune-staged` command in [routes/console.php](../routes/console.php) next to the existing dailies, or
a second pass inside the existing command. Prefer the existing command: same concern, same schedule slot.

---

## 4. Phase 2 — controller consumes ids instead of binaries

In `EventInvitationDesignController@update`, each of the four upload branches gains a staged path
alongside the file path. Taking gallery as the model — today's code at `:105`:

```php
foreach ($request->file('gallery_images', []) ?: [] as $file) { ... $newGallery[] = $path; }
```

becomes: resolve staged rows for `slot=gallery` scoped to `$fresh->id` and `$request->user()->id`, take
`$row->path` directly (the file is already in place), push it onto `$newGallery` and
`$galleryOriginalPathsForJobs` exactly as before, delete the row inside the transaction — then run the
existing `$request->file()` loop for the no-JS case.

Everything downstream is untouched: `$uploadedPaths` rollback, `$pathsToDelete`, the optimistic
`customization_token` check, `InvitationCustomizationPersistenceValidator`, and the
`ProcessInvitationDesignImageJob` dispatches in `DB::afterCommit`. Only the *source* of the path string changes.

One asymmetry worth stating: on a failed save, staged files must **not** be deleted — the user did not
re-pick them, and the form is about to be redisplayed with the tiles still on it. So staged paths go into
`$galleryOriginalPathsForJobs` but never into `$uploadedPaths`. Rolling them back would be the obvious
mistake here.

**`withValidator` needs the same treatment.** The cap checks at `:339-407` count `$request->file()` only.
They have to count staged rows too, or the 6-image gallery cap stops holding the moment uploads move.

---

## 5. Phase 3 — the shared uploader

New `public/js/media-uploader.js` + `public/css/media-uploader.css`, loaded by the event edit page.
Opt in per input with `data-upload-slot="gallery"` etc., matching how `data-dtp` and `data-cs` already work.

Per file, a tile:

1. `URL.createObjectURL(file)` thumbnail appears instantly
2. determinate bar 0–100 % from `xhr.upload.onprogress`
3. `Ready` check, or `Failed — retry` with a working retry button
4. remove button that calls the DELETE route

Plus: drag-and-drop, a client-side size/type check before the request so a 12 MB photo is rejected in
zero bytes, and a form-level guard so **Save** waits on in-flight uploads instead of racing them.

Reuse the `.tbup-queue-badge--busy/--done/--error` state vocabulary from
[table-upload.css](../public/css/table-upload.css) rather than inventing a second one.

Per the CSS conventions in CLAUDE.md this is a page-specific file pushed via `@push('styles')`, not
something added to `global.css`.

---

## 6. Phase 4 — wire up every slot

The uploader is slot-driven, so this is markup only. Every variant is covered by the four fields below —
which of them render is already decided by `InvitationLayoutVariant`:

| Field | Form control | Which variants |
|---|---|---|
| `gallery_images[]` | batch, ≤ 6 | all variants that do not block the `gallery` section — i.e. all but `standard` and `event_invite` |
| `invitation_hero_portrait` | single | `botanical_graduation` only (`maxInvitationHeroPortraitSlots`) |
| `couple_photos[]` | batch | `botanical_graduation` (2), `wedding_invitation` (3) |
| `speaker_photo[0..3]` | per-slot | `beauty_for_ashes` only — 4 fixed positional slots |
| `cover_image` | single | all — lives on the **details** form, [form-fields.blade.php:178](../resources/views/events/partials/form-fields.blade.php) |

`cover_image` posts to a different endpoint (`EventController@update`), so it needs its own small staging
branch there. Worth doing in the same pass — it is the one image every event has, and it is the most
common thing a user is waiting on.

`audio_track` has the identical problem and the identical fix, but it is not an image and the user did not
ask for it. Wire the slot, ship it if it falls out for free, drop it if it does not.

---

## 7. Explicitly out of scope

The other four upload sites in the app are untouched: `/settings/profile` photo, admin template preview
images, admin review posters and reviewer photos, and the guest CSV import. They are single small files on
short forms — the wait is not the complaint. If the uploader proves itself here, the profile photo is the
natural second customer.

---

## 8. Testing

- **Feature:** staging endpoint stores and returns an id; rejects oversize/wrong-mime; rejects the 7th
  gallery image; a staged id belonging to another user or another event resolves to nothing
- **Feature:** a save carrying only `staged_media[]` produces the same `invitation_customization` as the
  same save carrying binaries — assert this per layout variant, since the couple-photo branch differs
  between BFA's positional array and everyone else's compact one
- **Feature:** a save that fails validation leaves the staged rows and files intact
- **Command:** the pruner does not delete a file referenced only by an unconsumed staged row; it does
  delete one whose row is 25 h old
- **Manual:** throttle to Slow 3G in devtools, pick six images, confirm six independent progress bars, then
  confirm Save returns in well under a second

---

## 9. Known gotchas to carry into implementation

1. **Do not use `fetch` for the upload.** No upload progress. The whole feature depends on `xhr.upload.onprogress`.
2. **The pruner will eat staged files** unless `buildReferencedPathSet()` learns about them. This is the one
   change that fails silently and an hour late — do it in the same commit as the endpoint.
3. **Staged paths must not join `$uploadedPaths`** or a validation failure will delete the user's uploads out
   from under a form that is still showing them.
4. **The `customization_token` optimistic lock is unaffected** — staging does not touch
   `invitation_customization`, so it cannot invalidate an open form's token. Confirm this holds if staging
   ever starts writing to the event row.
5. `max_file_uploads=20` and `post_max_size=40M` stop being reachable limits once this ships, but leave both
   alone — the no-JS fallback path still relies on them.
