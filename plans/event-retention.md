# Feature Plan: Permanent deletion of events 30 days after they are deleted

Status: **In progress** — Phases 1 (the purge), 2 (countdown, API, admin) and 2c (warning email, warned-first
backstop) built, purging disabled by default; Phase 3 (Privacy wording, account-guard fix) planned. All open
questions are resolved (§10). **Do not enable purging until Phase 3 has shipped.**

Today "delete" on an event is a soft delete and nothing ever comes back to finish the job. A deleted event
sits in **Recently deleted** forever, with its guest list, RSVPs, uploaded media and (for ticketed events)
its orders all still in the database. `EventController::destroy()` even carries the comment
*"A later prune job can hard-delete after a retention window."* — this is that job.

The Privacy policy (§7) says event and guest data is kept *"until you delete the event, or delete your
account"*, so as written today the honest reading is that deleting an event removes it immediately. The
30-day recovery window has to be stated there too; §9 covers the copy.

---

## 0. Decisions taken

| Question | Decision |
|---|---|
| Retention window | **30 days** from `deleted_at`, in `config('events.retention.deleted_days')` (env `EVENT_TRASH_RETENTION_DAYS`, default 30). Blank/0 turns purging off entirely |
| What is exempt | An event that has ever **taken money** is never purged: a ticketed event with a **Paid or Refunded** order, or any order still in flight. Extended (confirmed, §10 Q1) to an event with a **completed or pending contribution payment**. "Paid orders" alone was too narrow — see §2 |
| What does an exempt event do instead | Stays in Recently deleted indefinitely, marked *"Kept for payment records"* with no countdown and no purge date. It is still restorable |
| Hard delete or anonymise | **Hard delete** for everything else. Anonymising was considered for exempt events and rejected: it is a separate feature (which fields? what do check-in and the ticket page then show?) and the Privacy policy already carves payment records out |
| Who can trigger it | Nobody. A scheduled command only. Hosts and admins keep soft-delete/restore exactly as today |
| Warning before purge | UI countdown on each card ("Permanently deleted in 9 days") plus a sentence on the section, **and an email digest 7 days before** (§4b). Decided: this is irreversible removal of other people's data, and a countdown only helps someone who opens the page |
| Contribution payments | **Protect the event** (confirmed) — a completed or pending contribution payment exempts it, same as a paid order |
| Reused URLs after a purge | **Accepted** (confirmed). Note it in CLAUDE.md, build no tombstone table |
| Launch-date safety | **The command refuses to run** when purging is on, trash older than the window exists and `starts_at` is unset (§5). Decided: the failure mode it prevents is silent and unrecoverable. `--allow-backlog` is the deliberate override |
| Widening the account-deletion guard | **Deferred to a follow-up phase** (§6b). This plan only adds `withTrashed()` |
| Existing trash at deploy time | **Must not be purged on day one.** See §5 — the clock for anything deleted before launch starts at launch |

---

## 1. What already exists and why it matters

| Fact | Where |
|---|---|
| Delete is `SoftDeletes` on `Event`; host `destroy()` and admin `destroy()` both only soft-delete, and both say a later job may hard-delete | `Event.php:33`, `EventController.php:458-483`, `Admin\EventController.php:171-190` |
| Restore is host `events.restore` and admin `admin.events.restore`, both `withTrashed` route-bound | `routes/web.php:444`, `routes/admin.php:89` |
| **A ticketed event with Paid, in-flight (pending/processing) orders or occupying holds cannot be deleted at all** — checked under a row lock in the host path | `Event::hasBlockingTicketCommerce()`, `Event.php:548-565` |
| That guard does **not** cover **Refunded** orders, and it only runs at delete time — anything trashed before the guard existed, or orders that settle later, are not covered | same |
| Account deletion is blocked while a ticketed event has Paid orders — but the check goes through `$user->events()`, which **excludes trashed events**, and `events.user_id` cascades | `Settings\AccountController.php:34-48` |
| Almost everything cascades off `events`: guests, rsvps, guest_groups, event_tables, event_photos, event_staff, event_staff_links, staged_media, event_slug_redirects, event_contributions → contribution_payments, ticket_types/orders/reservations/tickets → ticket_payments | migrations (`cascadeOnDelete`) |
| Some tables deliberately survive: `ticket_revenue_entries`, `ticket_payouts`, `contribution_payouts`, `credit_transactions` are `nullOnDelete` | migrations; `2026_08_17_120000_prevent_cascade_on_ticket_revenue_entries.php` |
| `notification_logs` and `reports` are `nullOnDelete` — they would **outlive** their guests, orphaned, and hold delivery responses | migrations |
| `reviews.event_id` is `cascadeOnDelete` — a purge would **delete a host's published testimonial** | `2026_08_14_120000_create_reviews_table.php:17` |
| Files: `cover_image` on the public disk; `event-photos/{id}.webp` + `thumbs/`; `invitation-{gallery,hero,couple,media}/{event_id}/`; cached pass PDFs/images per guest token on the private disk | `EventPhotoUploadService`, `PruneOrphanedInvitationFilesCommand`, `GuestPassFileCache` |
| `invitation:prune-orphaned-files` (daily) already deletes unreferenced files under the four `invitation-*` dirs and orphaned guest-pass folders | `PruneOrphanedInvitationFilesCommand` |
| Slugs of trashed events are reserved (`includeTrashed`) so nothing can take one while it might be restored | `Event::sluggable()` |

**Design consequence:** most of this feature is *what the purge must not do* — not destroy money records, not
destroy a testimonial, not leave orphaned personal data behind, and not surprise anyone who deleted an event
months ago.

---

## 2. What "has taken money" means (the exemption)

The request was "ticketed events with paid orders should not be deleted". The rule has to be a little wider than
the words, because `ticket_orders` (and through them `ticket_payments`, `tickets`, buyer names/emails/phones)
**cascade** on event delete, while the ledger rows that survive (`ticket_revenue_entries`, payouts) only make
sense next to the orders they describe.

`Event::hasRetainedFinancialRecords(): bool` — the single definition, used by the purge and by the safety
re-check:

- a `ticket_orders` row with status **Paid**, **Refunded**, **PendingPayment** or **PaymentProcessing**
  (Paid: obviously. Refunded: the money moved both ways and the accounting trail is the point. In-flight: it
  can still resolve to Paid after we looked — the pollers and the Lenco webhook are still live for trashed
  events). `Failed` / `Cancelled` / `Expired` took no money and do not protect an event
- a contribution with any payment **Completed** or **Refunded**, any `amount_paid > 0`, or a **pending /
  processing** payment created within the last 7 days (§10 Q1). *Refined while building Phase 1:* nothing ever
  expires a pending contribution payment (there is no poller for them, unlike ticket orders), so counting every
  pending row would let one abandoned checkout shield an event forever. Only a payment recent enough for a late
  webhook to still complete it counts (`Event::CONTRIBUTION_IN_FLIGHT_DAYS`)

It is evaluated **inside the purge transaction, under the event row lock**, not only when selecting
candidates — an order can settle between the two.

Both `EventController::destroy()` and the admin `destroy()` keep using `hasBlockingTicketCommerce()` unchanged.
It answers a different question ("is money moving right now?") and the purge must not weaken it.

---

## 3. Phase 1 — the purge

**Built** (not yet enabled anywhere). As planned, with these specifics and deviations:

- **The default is 0, not 30.** §3.1 said default 30 while §11 said "ships dark"; both cannot be true — a default
  of 30 would turn purging on the moment this deploys. `EVENT_TRASH_RETENTION_DAYS` defaults to **0 (off)** and
  enabling it is the explicit last step of the go-live order in §11
- **Not in Phase 1:** the "warned first" backstop (§3.3 step 2, §4b) — it belongs to Phase 2c with the warning
  email it depends on. Until that ships purging must stay off; nothing in this phase makes it safe to enable
- **`scheduledPurgeDate()` vs `purgeAt()`:** the first is pure date arithmetic (no queries, cheap on a list page);
  the second is the same date or null when the event is exempt. The scope, the service and the command use the
  first; the UI will use the second. `Event::scopePurgeable()` is `Event::onlyTrashed()->purgeable()`
- **The invitation-media directories are deleted explicitly** (`invitation-{gallery,hero,couple,media}/{id}`), not
  left to the orphan sweep as §3.3 step 7 said — deleting them is one call and leaves nothing to wait for. The
  sweep remains the backstop if a delete fails (failures are logged as `event.purge_file_failed`, never thrown)
- **Only storage-relative paths are ever deleted** — anything containing `..`, `://` or a leading `/` is dropped,
  since `cover_image` and photo paths are user-influenced values
- **`PurgeOutcome`** (`purged | would_purge | skipped | failed`) is what the service returns, so the command can
  report kept-for-records separately from failures. A failure makes the command exit non-zero
- Tests: `tests/Feature/EventPurgeTest.php` (26). Mutation-checked: removing Refunded from the protected statuses
  and removing the review detach each make a test fail

### 3.1 Config

`config/events.php`:

```php
'retention' => [
    // Days a deleted event stays restorable before it is permanently removed. 0/blank = never purge.
    'deleted_days' => (int) env('EVENT_TRASH_RETENTION_DAYS', 30),
    // Trash older than this date is treated as deleted on this date (see §5). ISO date, blank = ignore.
    'starts_at' => env('EVENT_TRASH_RETENTION_STARTS_AT'),
],
```

### 3.2 Model

- `Event::purgeAt(): ?Carbon` — `deleted_at + deleted_days`, pushed out to `starts_at + deleted_days` when the
  event was deleted before launch; `null` when purging is off or the event is exempt. **The UI and the purge
  both read this**, so the countdown can never disagree with what the command does.
- `Event::scopePurgeable($query)` — trashed rows past their `purgeAt()`. Deliberately does *not* filter
  exemption in SQL: the exemption is re-checked under lock per event.
- `Event::hasRetainedFinancialRecords()` — §2.

### 3.3 Service and command

`EventPurgeService::purge(Event $event): PurgeOutcome` (`Purged` | `Skipped(reason)` | `Failed`), called by
`events:purge-deleted` (`--dry-run`, `--limit=`, chunked 50 at a time, per-event try/catch so one bad event
never stops the run). Per event, in **one DB transaction**:

1. `Event::onlyTrashed()->whereKey($id)->lockForUpdate()->first()` — gone or no longer trashed means it was
   restored/purged concurrently → `Skipped`.
2. Re-check `purgeAt()` is past and `! hasRetainedFinancialRecords()` — else `Skipped('financial records')`.
   Then require a **`event_purge_warning` log for this deletion** (§4b) — else `Skipped('not yet warned')`. An
   event is never purged unannounced, whatever the schedule or a late deploy did
3. **Collect file paths first** (cover, `event_photos` paths + thumbnails, the four `invitation-*/{id}` dirs,
   guest tokens for their pass caches). Deleting files inside the transaction would leave the row and lose the
   files if it rolled back.
4. **Detach what must outlive the event:**
   - `reviews` for this event → `event_id = NULL`. The `cascadeOnDelete` would otherwise delete a published,
     possibly homepage-featured testimonial; the review already snapshots author name/context precisely so it
     survives (Privacy §7). `unique(user_id, event_id)` permits many NULLs
   - nothing to do for `credit_transactions`, `ticket_revenue_entries`, payouts — they already `nullOnDelete`
5. **Delete what would otherwise be orphaned with personal data:** `notification_logs` where `event_id = id`
   (delivery responses and inbound WhatsApp logs; `nullOnDelete` would keep them forever with no event to hang
   a later erasure request on). `reports` are left to `nullOnDelete` — they are moderation records, not guest data
6. `$event->forceDelete()` — the cascades take guests, RSVPs, groups, tables, photo rows, staff, scanner links,
   staged-media rows, slug redirects, contributions (none paid, by step 2) and any ticket rows that took no money.
7. **After commit**, delete the collected files. A failure is logged (`event.purge_file_failed`) and does not
   fail the purge: `invitation:prune-orphaned-files` already sweeps unreferenced `invitation-*` files and
   orphaned pass-cache folders on its next daily run, so those self-heal. The cover and `event-photos/` files are
   **not** covered by that sweep, which is why they are deleted explicitly here

Logs one `event.purged` line per event (id, name, owner id, counts of guests/RSVPs/photos removed) and a run
summary. No personal data goes in the log.

### 3.4 Schedule

`routes/console.php`, in this order:

- `events:warn-pending-purge` — `dailyAt('02:30')` (§4b)
- `events:purge-deleted` — `dailyAt('03:00')`, `->withoutOverlapping()`

Both `->timezone('Africa/Lusaka')`, both before the 09:00 reminder commands; small and idempotent, so a missed run
just catches up. The warning runs first so the purge's "warned first" check (step 2) sees it.

---

## 4. Phase 2 — telling the user

**Built.** As planned, with these specifics:

- **`App\Support\EventRetentionNotice`** is the one place that words and dates it (`for($event)` → countdown or
  kept, null when the event isn't deleted or purging is off; `apiFields()` for the API). The host list, both
  admin pages and both API resources read it, so they cannot disagree. The label rounds days **up**, so "1 day"
  means within the next 24 hours and it never reads 0 while the event is still restorable; past due reads
  "Permanently deleted soon" (the next 03:00 run takes it)
- **Nothing changes while purging is off** (`EVENT_TRASH_RETENTION_DAYS=0`): no countdown, no section note, and
  the delete flash keeps its old wording. The UI only starts talking about a window when there is one
- **Host list** (both portals share `my-events-groups.blade.php`): a countdown line on each card, or a lock and
  "Kept for payment records" for an exempt one, plus a note under the heading that also tells the host that
  events with ticket sales or contribution payments are kept. The delete flash gains "within 30 days". (Blade
  does not treat `@if` glued to a word as a directive, so the flash uses an inline expression)
- **API:** both `EventListResource` and `EventResource` gain `purge_at` (ISO 8601, null when it will never be
  purged) and `retained_for_records` (bool) — added beyond the plan's single field because a client cannot tell
  "purging is off" from "kept for records" from `purge_at: null` alone. Additive only. No query runs for a live event
- **Admin:** the index now marks deleted events at all (it listed them with **no** indicator before) with the
  date and the notice; the show page's Deleted callout states the purge date, or "Retained: payment records"
- Cost: an exempt-check is two small `exists` queries per *deleted* event shown, and lists page at 10 (admin 20,
  where only deleted rows pay it)
- Tests: `tests/Feature/EventRetentionNoticeTest.php` (11). Checked in the browser on both portals against a
  throwaway database
- The launch-date grace (§5) needed no new code here: `scheduledPurgeDate()` from Phase 1 already pushes
  pre-release trash out to `starts_at + N`, and the card shows that date

- **Recently deleted** (`events/partials/my-events-groups.blade.php`, used by both portals): each card's meta
  becomes *"Deleted 3 weeks ago · permanently removed in 9 days"* from `purgeAt()`. An exempt event shows
  *"Kept for payment records"* instead. The section gets one line under its heading: *"Deleted events can be
  restored for 30 days, then they are removed permanently."* (interpolating the config value, never hard-coded)
- The delete flash (`events/index.blade.php:22`, `events/public-index.blade.php:21`): *"Event deleted. You can
  restore it from Recently deleted within 30 days."*
- **API** (`Api\V1\EventController` already returns a `deleted` list): additive `purge_at` (ISO 8601, null when
  exempt or purging is off) on the list resource — additive-only, per the Android contract
- **Admin** event show/index: show `purge_at` beside the existing deleted badge, and *"Retained: payment
  records"* for exempt events, so support can answer "where did my event go" without a database
- Update the stale comment in `EventController::destroy()` / `Admin\EventController::destroy()` to point here

## 4b. Phase 2c — the warning email (decided: yes)

**Built.** As below, with these specifics and one refinement:

- **A minimum-notice rule replaces "the schedule order keeps it safe".** The plan relied on 02:30 (warn) running
  before 03:00 (purge) and on the 7-day lead. That is *not* enough for an event that is already overdue when the
  feature is first run (a late deploy, a missed run): it would be warned at 02:30 and purged at 03:00 the same night.
  The purge now requires the warning to be at least `Event::PURGE_MIN_NOTICE_HOURS` (24) old, so an overdue event is
  warned, waits a day, then goes. In the normal path the warning is a week old and this never bites
- **Only a sent or pending warning counts**, never a failed one — the host was not told. Keyed on
  `Event::purgeWarningKey()` (`event-purge-warning:{id}:{deleted_at unix}`), so a restore followed by a second
  delete is a new deletion and needs a new warning. The key is shared by the command, the mailer and the purge so
  they cannot drift
- **`Event::scopePurgeable($withinDays)`** gained the look-ahead argument the command uses (7 days). The date and
  launch-grace logic is unchanged
- **The notification carries plain arrays** (name, dates, list URL), not Event models: the events are soft-deleted,
  and a queued mail should describe what was warned about rather than the row's state when a worker runs
- **Digest ordering and links:** soonest removal first, so the subject and the button lead with the most urgent
  event; each line links to the portal the event belongs to (private list vs public list), since restoring needs
  a login and a POST from the list page
- **Nowhere to send it is not a silent purge:** a suspended host, or one with no email, is skipped and no warning is
  logged — so the purge's warned-first check then *keeps* their events. They accumulate until someone fixes the
  account. Deliberate: erring towards keeping
- **One failing host does not stop the others**; the command exits non-zero so it shows up, and the host is retried
  the next night (no log was written for a failed send)
- **`--dry-run`** lists who would be emailed about which events, sending and logging nothing. Note that after this
  phase `events:purge-deleted --dry-run` only reports *warned* events as purgeable, so on go-live the dry run shows
  nothing until the first warnings have gone out
- Tests: `tests/Feature/EventPurgeWarningTest.php` (20); the Phase 1 purge tests now start each event warned.
  Mutation-checked: removing the warned-first check (5 tests fail), the minimum notice (1) and the exempt filter (1)

`events:warn-pending-purge`, daily at 02:30 Africa/Lusaka (before the 03:00 purge, so an event is never purged
in the same run that first warns about it — see the window rule below).

- **Who/what:** for each host, one **digest** email listing their events whose `purgeAt()` falls within the next
  7 days and that have not been warned about yet — event name, date it was deleted, date it will be removed, and
  a "Restore" link per event (the existing `events.restore` route needs a login, so the link goes to the
  Recently deleted list, `events.index` / `public-events.index` by audience). One email however many events, so
  someone who deleted twenty events gets one message, not twenty
- **Window:** warn when `purgeAt() − now ≤ 7 days`. The purge command must **skip any event that has no warning
  logged**, so the email can never be skipped by a late deploy or a missed run — the purge waits one day rather
  than delete unannounced. (This also handles pre-launch trash: it is warned at `starts_at + 23`, purged from
  `starts_at + 30`)
- **Idempotency:** `NotificationLog` type `event_purge_warning`, key `event-purge-warning:{event_id}:{deleted_at
  unix}`, through `CommunicationService::startLog()` like the other host emails. Keying on `deleted_at` means a
  restore followed by a second delete is a new deletion and gets a new warning
- **Preference:** none. It is a service notice about irreversible data removal, not marketing or an event
  reminder, so it is not tied to any `notification_preferences` toggle — same standing as a payment receipt or
  the account-email-changed notice. Nothing is added to the settings page
- **Exempt events are never mentioned** (they have no `purgeAt()`), and neither are events whose owner has no
  email or is suspended
- **`HostPurgeWarningNotification`**, mail only, queued on `default` like `HostEventReminderNotification`
- **The purge is the backstop:** `EventPurgeService` re-checks for a sent (or pending) warning log inside its
  locked transaction and `Skipped('not yet warned')` otherwise. Purging can be disabled (`deleted_days=0`) with
  no warning sent, so a config mistake cannot cause a silent purge

## 5. Phase 2b — do not purge existing trash on day one

Without this, the first scheduled run permanently deletes **every** event anyone has ever deleted — possibly
months or years ago, by hosts who were never told a clock existed. That is the most dangerous part of the
feature.

`events.retention.starts_at` (§3.1) is set to the deploy date. Eligibility is
`deleted_at <= now − N days` **and** `starts_at <= now − N days`, so an event trashed before launch is purged
no earlier than `starts_at + N` — i.e. it gets a full 30 days from the day the feature ships, and `purgeAt()`
shows that date on its card. No `deleted_at` is rewritten (it is real history and shown in the UI). After
`starts_at + N` has passed the setting is inert and can be removed.

**The command enforces it (decided).** If purging is on, `starts_at` is unset, and any trashed event is already
older than the window, `events:purge-deleted` **exits non-zero without touching anything** and prints why —
naming how many events would have been deleted and which env var to set. A forgotten setting would otherwise
fail silently and irreversibly, and the scheduler would keep retrying it daily. It only trips while such a
backlog exists, so a fresh install with an empty trash is unaffected. `--allow-backlog` is the deliberate
override for an operator who really does want the old trash gone. `--dry-run` is never refused: it reports the
backlog and exits 0, which is how to check before setting the date.

## 6. Phase 3 — close the gap in account deletion

`Settings\AccountController::destroy()` guards on `$user->events()`, which **excludes trashed events**, and
`events.user_id` cascades. A user whose ticketed event with paid orders is sitting in the trash (legacy, or
trashed before the delete guard existed) can delete their account and take those orders with them. Change the
guard to `$user->events()->withTrashed()` — one line, and it makes the account guard and the purge agree that
paid ticket sales are never destroyed. It does **not** widen the guard to Refunded/contribution (that would
change account-deletion behaviour beyond this feature) — that is the deferred follow-up in §6b.

### 6b. Follow-up phase (deferred, separate change) — widen the account-deletion guard

Confirmed as its own phase, not part of this release. Scope when it is picked up: make
`AccountController::destroy()` use the **same** `Event::hasRetainedFinancialRecords()` definition as the purge
(Paid, Refunded and in-flight ticket orders, plus completed/pending contribution payments) instead of its own
Paid-only, ticketed-only query, over `withTrashed()` events; update the error copy (it currently says "ticketed
events with paid orders"); update the Privacy/Terms account-deletion wording; and test each blocking case. The
reason it is a separate phase: it changes what stops a user deleting their own account, which deserves its own
release note and support wording. Until then `withTrashed()` (§6) is the only account-deletion change.

## 7. Phase 3 — the Privacy policy (and Terms)

`resources/views/legal/privacy.blade.php` §7 currently says event and guest data is kept *"until you delete the
event, or delete your account"*. Replace that bullet and the paragraph under it so they match what the code
does:

> **Event and guest data** — until you delete the event. A deleted event stays in *Recently deleted* for 30
> days so you can restore it, then it is permanently removed together with its guest list, RSVPs, photos and
> uploaded media. Deleting your account removes your events immediately, without that 30-day window.
>
> **Events with ticket sales or contribution payments** — not removed when deleted. We keep the event and its
> orders, including the buyer details attached to them, for as long as needed for tax and accounting, as with
> other payment records below.

and keep the existing *Payment records* line. §8 (rights) gains no new right, but the line about deletion should
not promise instant erasure of events. `Terms` §10 ("Deletion is permanent and removes your events") is about
account deletion and stays true. The "Last updated" line is `now()` so it needs no edit.

**The copy is unreviewed by a lawyer** (CLAUDE.md, Legal Pages) — this change makes the policy *more* specific,
so it should get looked at before it ships. It must ship in the **same release** as the command: policy saying
30 days with no job, or a job with the old policy, are both wrong.

## 8. Testing

- Purge: an event trashed 31 days ago is force-deleted with its guests, RSVPs, groups, tables, photo rows,
  staff, links and slug redirects; one trashed 29 days ago is untouched; a non-trashed event is never selected
- Files: cover, `event-photos/` (+ thumbs) removed after commit; a file delete that throws is logged and the
  purge still succeeds; the `invitation-*` dirs are left to the orphan sweep and that sweep then clears them
- **Exemptions:** trashed 60 days ago with a Paid order → kept; with a Refunded order → kept; with an
  in-flight order → kept; with only Failed/Cancelled/Expired orders → purged; with a completed contribution
  payment → kept. An order that turns Paid *between selection and the locked re-check* → skipped, not deleted
- Restore racing purge: an event restored before the lock is skipped; a restored event is never touched
- Reviews: a published/featured review of a purged event survives with `event_id = NULL` and unchanged
  author snapshot; notification logs for the event are gone; `credit_transactions` / revenue entries survive with
  a null event id
- `starts_at`: pre-launch trash is not purged until `starts_at + 30`; post-launch trash purges at its own +30
- Config: `deleted_days = 0` purges nothing; `--dry-run` deletes nothing and reports what it would
- `purgeAt()`: null for exempt events and when disabled; matches what the command does at the boundary (the
  card and the job read the same method)
- UI: countdown renders on both portals' Recently deleted; exempt card says "Kept for payment records" and
  shows no countdown; the API list carries `purge_at`
- Account deletion: blocked when a **trashed** ticketed event has paid orders (the new `withTrashed()` guard)
- Privacy page renders the 30-day wording and the exemption bullet (`LegalPagesTest`-style assertion)
- **Warning email:** an event inside the 7-day window produces one digest for its host; a host with three such
  events gets **one** email listing three; an event outside the window, an exempt event, and an already-warned
  event produce none; running the command twice sends once; restore-then-delete-again warns again (new
  `deleted_at`); a suspended or email-less owner is skipped without erroring
- **Warned-first backstop:** an event past its purge date with no warning logged is `Skipped('not yet warned')`
  and survives; the same event after the warning command has run is purged the next day
- **Launch-date refusal:** purging on + `starts_at` unset + trash older than the window → non-zero exit, nothing
  deleted, message names the env var; same with an empty/recent trash → runs normally; `--allow-backlog` runs it;
  `--dry-run` reports and exits 0 in every case

## 9. Files

| Area | Files |
|---|---|
| New | `app/Console/Commands/PurgeDeletedEventsCommand.php`, `app/Console/Commands/WarnPendingEventPurgeCommand.php`, `app/Services/EventPurgeService.php`, `app/Notifications/HostPurgeWarningNotification.php`, `tests/Feature/EventPurgeTest.php`, `tests/Feature/EventPurgeWarningTest.php` |
| Edit | `app/Models/Event.php` (`purgeAt`, `scopePurgeable`, `hasRetainedFinancialRecords`), `app/Services/CommunicationService.php` (`sendPurgeWarning`, same `startLog` idempotency as the other host emails), `config/events.php`, `routes/console.php`, `events/partials/my-events-groups.blade.php`, `events/index.blade.php`, `events/public-index.blade.php`, `Api/V1/EventListResource.php`, `admin/events/*`, `Settings/AccountController.php`, `legal/privacy.blade.php`, both `destroy()` comments, `.env.example`, `CLAUDE.md` (a short "Event retention" section), `docs/deployment.md` (set `EVENT_TRASH_RETENTION_STARTS_AT` on the release that ships this) |
| Untouched on purpose | `hasBlockingTicketCommerce()`, soft-delete and restore behaviour, slug reservation while trashed |

## 10. Questions — all resolved

1. **Contribution payments protect an event?** **Yes** (confirmed). A completed or pending contribution payment
   exempts the event, same as a paid order (§2). Contributions are switched off platform-wide today, but the rows
   exist and `contribution_payments` cascade off `events`, so purging would destroy a money trail the Privacy
   policy says we keep
2. **Widen the account-deletion guard to Refunded orders / contributions?** **Later, as its own phase** (§6b).
   This release only adds `withTrashed()`
3. **Warning email before purge?** **Yes, decided** — a digest per host 7 days before (§4b). Purging is
   irreversible and takes other people's data with it; a countdown on a page the host may never open is not
   enough notice. It is a service notice, so it has no preference toggle, and the purge itself refuses to delete
   an event that has not been warned about
4. **Reused URLs after a purge?** **Accepted** (confirmed). Once purged an event's slug and old
   `event_slug_redirects` are freed and could be claimed by a new event. No tombstone table; a line in CLAUDE.md
   records that it is deliberate
5. **Command refuses to run when the launch date is unset?** **Yes, decided** (§5), with `--allow-backlog` as the
   explicit override and `--dry-run` never refused

## 11. Phasing

| Phase | Contents | Notes |
|---|---|---|
| 1 | Config, `Event` helpers, `EventPurgeService`, `events:purge-deleted` (exemptions, files, review/log handling, launch-date refusal), schedule | Safe core. Ships dark with `EVENT_TRASH_RETENTION_DAYS=0` |
| 2 | UI countdown, delete flash, API `purge_at`, admin display, launch-date grace (§5) | Must ship **with or before** enabling |
| 2c | `events:warn-pending-purge` + `HostPurgeWarningNotification`, and the purge's "warned first" backstop | Must ship **with or before** enabling; without it the backstop makes the purge skip everything |
| 3 | `withTrashed()` account guard, Privacy copy, CLAUDE.md, `docs/deployment.md`, `.env.example` | Ships in the same release that turns purging on |
| 4 (later) | Widen the account-deletion guard to the full "has taken money" definition (§6b) | Separate change, separate release note |

Never enable purging in production before the warning email, the policy copy and the grace date are all in. The
order to go live: deploy phases 1–3 dark → set `EVENT_TRASH_RETENTION_STARTS_AT` to the deploy date → run
`events:purge-deleted --dry-run` and read the report → set `EVENT_TRASH_RETENTION_DAYS=30`.
