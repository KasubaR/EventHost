# Invitation page edge cases

Status: planned, nothing built. Origin: edge-case review of `/e/{slug}` (`PublicInvitationResolver`,
`PublicEventController::show`, the invitation renderer). Related: `plans/rsvp-deadline-fixes.md`,
`plans/plus-one-edge-cases.md`.

**Depends on in-progress work.** `PublicInvitationResolver::lacksInvitationLayout()` and the publish blocker
(`Event::invitationTemplatePublishBlocker()`) are uncommitted at the time of writing. Land or rebase onto that
work before Phase 3, which extends it.

## Decisions

1. **Private events keep opening for anyone with the slug** (unlisted, never in Discover). No behaviour change.
   `CLAUDE.md` "Event Preview" still claims `/e/{slug}` 403s for `is_public = false`; that text is wrong and is
   corrected in Phase 5.
2. **A missing template shows the "Invitation unavailable" page; there is no fallback template.** A wedding
   invitation drawn in an unrelated design is worse than an honest "unavailable", and the publish gate already
   means this only happens after something broke. "Missing" means: no template id, an id with no row, or no
   templates configured at all. A **retired (inactive) template keeps rendering** on a live event — unchanged.
   Never a 500 on a public page.
3. **A missing gallery (or other media) file is skipped for guests and reported to the host.** No placeholder
   tile for guests. The host sees a notice naming how many files are gone and where to re-upload.
4. **"Ended" moves to venue time, one calendar day, no end date.** An event is ended once the venue's date
   (`config('events.timezone')`, Africa/Lusaka) is after `event_date`. Chosen over adding an end date because
   every event has exactly one date and time today, and the RSVP deadline already runs on venue time — one clock
   everywhere. Known cost: a party that runs past midnight shows "ended" from 00:00 venue time. An end date or a
   late-night grace is a separate plan if hosts ask.
5. **Deleted, cancelled or paused events that were never published are a plain 404**, not a status page.

## Phase 1 — Drafts never show status pages (gap 1)

- In `resolveInvitationPage()`, `resolveInvitationPageForApi()` and `resolveOpenRsvp()`, move the
  `! $event->is_published` check **above** the trashed / cancelled / paused checks. A published event that is
  later deleted, cancelled or paused keeps `is_published = true`, so it still gets its status page.
- Personal links and `statusForLoadedEvent()` are unchanged: the guest holds a secret.
- Tests: deleted draft, cancelled draft, paused draft → 404, no event name in the body; deleted / cancelled /
  paused published event → the same status pages as today; API twin returns 404 for each draft case.

## Phase 2 — Ended on the venue clock (gap 2)

- Add `Event::venueToday(): Carbon` (venue-timezone date) and use it in `isLocked()`, the second date check at
  `Event.php` ~1175, and `scopeUpcoming()` (~1449). `isLocked()` also drives edit locking, redefine charges,
  pass visibility and the Ended page, so all of them move to the same clock together — that is the point.
- Review the remaining `today()` uses for events: `ReviewController` (web and API, "reviewable once the date has
  passed"), `PublicEventController` discover presets, `PublicDashboardAnalyticsService`. Same rule: if it asks
  "has the event's date passed", it uses `venueToday()`.
- Tests (UTC `setTestNow`, per `CLAUDE.md` RSVP Deadline notes): event on D is live at 21:59 UTC D, **ended at
  22:00 UTC D** (= 00:00 D+1 venue); `isLocked()` flips at the same instant; reviewable and discover agree.

## Phase 3 — Missing template, no 500 (gap 3)

- `lacksInvitationLayout()` becomes "the template cannot be loaded": id null, **or** no `invitation_templates`
  row for the id. Load the relation once and reuse it for rendering (no extra query on the happy path).
- `InvitationCustomizationService::resolvedTemplate()` stops borrowing the first active template for an id
  with no row. When there are no templates at all it must not throw into a public request: the resolver reports
  it (`report()`) and returns `PublicInvitationStatus::Unavailable`.
- Add the same check to `resolveOpenRsvp()` and the personal-link page (`RsvpController::showByToken`) so
  every guest-facing page agrees with the main page.
- Host side: the edit and show pages already tell the host to pick a layout; extend that notice to "your layout
  was removed" for the id-with-no-row case.
- Tests: null id, dangling id, empty catalogue → "Invitation unavailable" (no 500); inactive template still
  renders; open RSVP and token pages agree; API returns the same status.

## Phase 4 — Missing media: skip for guests, tell the host (gaps 4, 5)

- New `App\Support\InvitationMediaHealth::missing(Event): list<array{slot, path}>` — checks the public disk
  for the cover, gallery, hero portrait, couple photos and audio / video. Absolute URLs (template preview
  samples) are skipped. It is the one place that knows what "present" means.
- `InvitationCustomizationService::merge()` drops a gallery / couple / portrait reference whose file is gone,
  so every layout skips it without per-view checks. The cover already falls back to `default-event.png`
  (`Event::hasCoverImage()`); keep that.
- Host notice (event edit, design page and event show): "N image(s) are missing from your invitation and are
  hidden from guests. Re-upload them." with the slots named. Computed on view, not stored, so re-uploading
  clears it. API: additive `media_issues` (count + slots) on the host event resource; null when healthy.
- No guest-visible placeholder. No per-request logging (it would fire on every page view).
- Tests: gallery with one missing file renders the others and not the broken one; all gone → the gallery
  section disappears; host notice appears and clears after re-upload; cover missing still falls back and is
  reported; absolute preview URLs are not flagged.

## Phase 5 — Docs and test coverage (gap 6, 7)

- Correct `CLAUDE.md` "Event Preview": a private invitation renders at `/e/{slug}` for anyone with the link;
  `/events/{event}/preview` is for hosts previewing a draft or their own unpublished page.
- Add the venue-clock, draft-404, template and media rules to `CLAUDE.md` (a short "Invitation page" section).
- Extend `PublicInvitationLifecycleTest`; keep the new cases in one new file, `InvitationPageEdgeCasesTest`.

## Order and risk

Phase 1 (smallest, privacy), then 2 (shared clock, widest blast radius — run the full suite), 3, 4, 5.
No migrations. API changes are additive only. Phase 2 changes when locked events stop being editable by up to
two hours at night; announce it in the PR.
