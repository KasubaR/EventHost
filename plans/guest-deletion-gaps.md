# Guest deletion gaps

What happens when a host deletes a guest: after they RSVP, after an invitation was sent, while a notification is
queued, and when the deleted guest opens their old link. Found by reading the code; nothing has been reproduced yet,
so Phase 0 proves each gap with a failing test before anything is fixed.

## Where things stand

- A guest is **hard-deleted** (`Guest` has no `SoftDeletes`). `rsvps`, `rsvp_changes` and `guest_edits` cascade;
  `notification_logs.guest_id` and `event_contributions.guest_id` are nulled.
- Four delete paths, none shared: `GuestController::destroy`, `Api\V1\GuestController::destroy`, and the `delete`
  action in `GuestBulkActionController` and `Api\V1\GuestBulkActionController`. The bulk ones use the query builder
  (`$builder->delete()`), so no model event ever fires.
- Seven queued notifications hold `Guest` + `Rsvp` + `Event` models and none sets `$deleteWhenMissingModels`.
- A removed token is a bare `firstOrFail()` 404 on every token route.

## Phase 0: tests first (BUILT)

One test per gap below, written to fail today. `GuestDeletionEdgeCasesTest`.

- delete an accepted guest: seats freed, RSVP history gone, pool/limit counts correct
- delete after invitation sent: log row survives with no guest; assert what the host can still see
- queue a `RsvpConfirmationNotification` (and each other guest notification), delete the guest, run the worker:
  assert no row in `failed_jobs`
- GET/POST every `rsvp.token.*` route and the check-in scan with a deleted token
- deleted guest re-submits the open RSVP form / group link with the same email
- WhatsApp inbound reply from a deleted guest's number

## Phase 1: queued mail must survive a deletion (BUILT)

**The plan's first idea did not work.** `$deleteWhenMissingModels` on a notification is ignored: Laravel reads it from
the queued job class (`SendQueuedNotifications`), which does not set it, so a missing guest still failed the job.

- `App\Notifications\Concerns\SkipsWhenGuestDeleted` is on the nine guest/RSVP notifications (`RsvpConfirmation`,
  `RsvpReminder`, `EventUpdated`, `GuestEventReminder`, `PlusOneRemoved`, `RsvpRejected`, `RsvpExtraSeatPending`, and the
  host-facing `NewRsvpReceived`, `RsvpAwaitingApproval`). It restores a missing `Guest` or `Rsvp` as an empty unsaved
  model instead of throwing, and `shouldSend()` returns false for it, so the job finishes with no mail and no failed job
- A missing **Event** still fails as before (out of scope). A new guest notification must use the trait
- `notification_logs` needed no change: the row is written `sent` when the mail is handed to the queue (not on
  delivery), and its `guest_id` is already nulled by the foreign key. It therefore still reads `sent` for a mail that
  was dropped; fine, because it records the hand-off
- Tests: `GuestDeletionEdgeCasesTest` (serialises each notification, deletes the guest, restores it; plus a real
  database-queue worker run that asserts no `failed_jobs` row and no mail)
## Phase 2: one delete path (BUILT)

- `App\Services\GuestDeleter` (`delete()`, `deleteMany()`), a twin of `GuestCreator` / `GuestUpdater`: locks the event
  row, deletes each guest through the model, returns what was lost (`deleted`, `seats_freed`, `answered`,
  `invitation_sent`). Guests of another event are ignored
- Called by `GuestController::destroy`, `Api\V1\GuestController::destroy` and the `delete` action of both bulk
  controllers. Callers do not use the counts yet (Phase 5; `was_pending_approval` is added then)
- `GuestPassFileCache` files for each token are removed after the transaction commits, so they no longer wait for
  `invitation:prune-orphaned-files`
- **Not fixed here:** the race where a bulk *send* reads `$builder->get()` and a guest is deleted before the loop
  reaches them. Phase 1 makes a queued mail harmless; the in-request loop is unchanged

## Phase 3: remember who was removed (BUILT: Option A, tombstone table)

Today the app cannot tell "removed" from "never existed", and the host loses the answer history.

- **Option A, tombstone table (recommended):** `guest_removals` (`event_id`, `token_hash`, `name`, `email`,
  `phone_key`, `had_answered`, `last_status`, `removed_by`, `removed_at`). Written by `GuestDeleter`, cascades with
  the event, purged with it. Small, touches no existing query, gives the removed page, the audit trail and the
  re-signup block. No undo
- **Option B, `SoftDeletes` on `guests`:** gives undo and keeps RSVP history. But the global scope reaches every
  `Guest` query, including raw `DB::table('guests')` and the `GuestPhone::sqlDigits` search; the per-event email and
  phone duplicate checks must ignore trashed rows; cascades no longer fire, so retention/purge needs its own pass.
  Larger and riskier; only worth it if hosts ask for "undo delete"

- Built: migration `2026_10_14_110000_create_guest_removals_table`, `App\Models\GuestRemoval`, `Event::guestRemovals()`.
  `GuestDeleter` writes the row in the delete's own transaction (`GuestRemoval::recordFor()`); the four callers pass the
  deleting user's id. Stored: event, remover (`nullOnDelete`), SHA-256 of the personal link, name, lower-cased email,
  `GuestPhone::key()`, last RSVP status, seats freed, invitation-sent flag, time. `GuestRemoval::forToken()` is the lookup
  Phase 4 uses. The link is only ever stored hashed. Rows cascade with the event (and so with a purge)
- **Privacy §2/§7 must mention it** (name, email and phone are kept after a delete, until the event goes): Phase 7
- Not tombstoned: a link the host *replaced* (`regenerate-link`); that guest still exists, so the old link stays a 404

## Phase 4: what the deleted guest sees (BUILT)

- **Pages.** The existing guest-link 404 handler in `bootstrap/app.php` now looks the token up in `guest_removals`
  first. A match renders `rsvp/guest-removed.blade.php` with **410**: names the event (not the guest), says the link and
  pass no longer work, and includes `rsvp.partials.host-contact`. Covers `rsvp.token.show`, `thanks`, `pass`,
  `pass-download` and a POST to `store` (nothing is ever accepted). It only runs once the link already failed to match,
  so a live link never pays for the query
- **Unknown token** keeps `rsvp/link-not-found` (404). **Image routes** (`pass.png`, `entry-pass.*`) stay a plain 404
- **API** (`api/v1/rsvp/{token}` GET/POST) keeps its 404 and gains `code: invitation_removed`; an unknown token has no
  `code`. Additive, so the Android contract holds
- **Door scanners.** `GuestRemoval::scanResponse()` is used by `CheckInController`, `PublicCheckInController` and
  `Api\V1\EventCheckInController`: a QR that matches a tombstone **of that event** answers 404 with
  `"This guest was removed from the event by the host."` and `code: guest_removed`; anything else keeps the old
  "No matching invitation" message. A QR from another event is not reported as removed
- **WhatsApp.** A reply to an invitation whose guest was removed (matched through the replied-to message's log row, which
  keeps its event, and the sender's number against that event's tombstones) gets one short "no longer on the guest list"
  text; the inbound log row stops a Twilio retry from sending it twice. A different number, or a reply that names no
  message, stays silent as before
- `NormalizeGuestToken` needed no change
- Tests: `GuestDeletionEdgeCasesTest` (pages, API, scanners) and `WhatsAppInboundRsvpTest`
## Phase 5: what the host sees (BUILT)

- **Confirm text.** `Guest::removalWarning()` feeds each row's Remove button: seats that will be freed, "their link and any
  pass already sent will stop working", and that the RSVP history is deleted. Reads the RSVP and the sent flag only
- **After a single removal** the page flashes the seats freed and, for a pooled group, how many the group has left
  (`guest_removed` in the session; `GuestController::destroy`). The existing `guest-deleted` status is unchanged
- **Bulk.** The web redirect adds `bulk_removed` (the `GuestDeleter` summary) and the page says "N seats freed". The API bulk
  response gains `seats_freed` (a number for `delete`, null for every other action): additive
- **"Tell them", never automatic.** `POST events/{event}/guests/removed/{removal}/notify` (`GuestController::notifyRemoved`,
  owner only, 404 for another event's removal). Sends one `GuestRemovedNotification` through
  `CommunicationService::sendGuestRemoved()` and sets `guest_removals.notified_at` (migration `2026_10_14_120000`), which
  also hides the button, so it can only go once; the log idempotency key `guest-removed:{id}` backs that up. Refused with no
  email, when already told, or when the guest was never invited and never answered (`GuestRemoval::canBeTold()`). The email
  has no link, names the event and shows the host's number. **Changed from the plan:** a once-ever limit replaces the
  one-hour cooldown, because the guest row is gone and there is nothing to resend to
- **Removed guests list.** A collapsed section under the guest list ("Removed guests (N)", last `GuestRemoval::LIST_LIMIT` = 30)
  with a one-line summary, when, and the Tell them button or "Told". Hidden when empty. Styles in `events-admin.css`
- The `GuestRemovedNotification` carries the name as a string, not a `Guest`, so it does not need `SkipsWhenGuestDeleted`
- Not done: the bulk action's own confirm dialog is still the generic "Apply this action to all selected guests?" (the
  chosen action is only known in the browser); the result flash carries the detail instead
- Tests: `GuestRemovalHostSideTest`

## Phase 6: stop silent re-entry (BUILT)

- `OpenRsvpService` and `GroupRsvpService` check `GuestRemoval::matchesContact()` (the event's tombstones, by lower-cased
  email **or** `GuestPhone::key()`) when they create a **new** guest, and set `guests.previously_removed_at` (migration
  `2026_10_14_130000`). `RsvpSubmissionService::submit()` adds that flag to `$requiresApproval`, so an acceptance is held for
  the host whatever `require_rsvp_approval` says (the same hold group-link joiners get). Nothing is refused: the person
  still gets their answer recorded
- The host's own add (`GuestCreator`), the import and the API add never set it. A decline or maybe is not a review
- `Event::hasRsvpApprovalQueue()` is true when any guest carries the flag, so the "Awaiting approval" filter and stat show
- Guest list: a "Previously removed" pill beside the status. API: additive `previously_removed` on `GuestResource`
- The flag is permanent (a record of how they came back); approving them works as for any held RSVP
- Applies to free-registration events too: a removed registrant who registers again waits for the host
- Tests: `GuestRemovalReentryTest`

## Phase 7: cleanup and docs (BUILT)

- **Privacy.** §3 gains a paragraph on the removal record (name, email, simplified phone, whether they answered, when and by whom;
  what it is used for; deleted with the event) and §7 gains a "Records of removed guests" line. Always on, not tied to a flag,
  because the tombstone is always written. `LegalPagesTest` asserts both. Still unreviewed by a lawyer
- **CLAUDE.md** has a "Deleting guests" section (before "Plus-ones") covering the delete path, the tombstone, the queued-mail trait,
  the removed page, the host side and re-sign-up
- **`invitation:prune-orphaned-files`** needed no change: a test leaves a stray pass folder for a deleted guest and shows the sweep
  removes it while a live guest's folder stays
- **API** is additive only: `seats_freed` on the bulk response, `code: invitation_removed` on the RSVP link 404, `previously_removed`
  on the guest. No existing field changed
- **Contributions:** checked. Nothing in the contribution code reads `event_contributions.guest_id`, so the FK nulling it on a delete
  changes no total or page

## Not covered

- Undo (Option B, `SoftDeletes`, was not chosen)
- An event deleted while its guest mail is queued: a missing Event still fails the job
- A bulk *send* racing a delete inside the request (the loop reads its guests once)
- The bulk bar's own confirm dialog is generic; the result flash carries the detail
- A removed guest the host re-adds by hand is not labelled (the host's own add is deliberate)
- Lawyer review of the new Privacy wording

## Order and size

All phases are built. Phases 0 and 1 were the small, shippable fix; Phase 2 a refactor with no behaviour change; Phases 3 to 5 the
feature; Phase 6 optional. Three migrations ship with it (`guest_removals`, `guest_removals.notified_at`,
`guests.previously_removed_at`): run them before deploying.
