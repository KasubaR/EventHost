# Guest email edge cases — gaps and plan

Status: **all four phases built.** Mail goes out over cPanel SMTP. Bounces stop email to that address on every event, and the host sees
"Email bounced". Tests: `EmailSendTimeChecksTest`, `EmailDeliveryTrackingTest`, `EmailBounceTest`, `EmailDuplicatesTest`.
Go-live for bounces: `docs/deployment.md` §3e.

## The ten cases

| Case | Before | Now |
|---|---|---|
| 1. Guest has no email | Covered | Unchanged |
| 2. Invalid email | `email:rfc` accepted `john@localhost`; a stored address Symfony refuses threw in the worker, the log still said "sent" | `App\Rules\GuestEmail` needs a dotted domain; a refused address fails at once (`invalid_address`, "The address is not valid") |
| 3. Bounces | Not handled | Bounce mailbox, `mail:process-bounces`, `email_suppressions`, guest-list line, "Try this address again", API field |
| 4. Provider unavailable | Most guest emails used the worker's 3 tries with no wait; `failover` ended in `log` | 4 tries 1 / 5 / 15 minutes apart; the final failure is on the log and the guest list; `failover` is SMTP only |
| 5. Delayed | Never expired; "in N days" fixed at queue time | Dropped after 24 h; days worked out again when sent |
| 6. Sent twice | Bulk update email had no guard; Accept-then-Decline sent two "Declined" emails | Same text in 10 minutes is skipped; a confirmation whose answer changed is skipped |
| 7. Queued, then event deleted | Soft-deleted: still sent. Purged: `failed_jobs` | Both dropped quietly |
| 8. Event updated after send | Only a venue change prompted the host; the update email had no details | Date and time changes prompt too; the update email restates date, time and venue |
| 9. Reminder queued, guest answered | Sent anyway, possibly to an old address | Dropped (answered, stopped reminders, address changed) |
| 10. Reminder queued, event cancelled | Sent anyway | Dropped |

## Phase 1 — re-check every queued guest email when it is sent (BUILT)

- `Notifications\Concerns\GuardsGuestMail` replaces `SkipsWhenGuestDeleted` on every guest notification. It restores a missing Guest, Rsvp **or
  Event** as an empty stand-in, and `shouldSend()` refuses on: deleted guest / RSVP / event (trashed or purged), `expired` (queued over 24 h ago),
  `address_changed`, `suppressed`, then the notification's own `whyNoLongerRelevant()`
- **Deviation:** no `retryUntil()`. With it set the worker ignores `tries`; the 24 h expiry is a check in `shouldSend()` on `queuedAt` instead. A
  reminder that outlives its point is caught by its own checks (deadline passed, event date past, day-of after the start)
- Per notification: `RsvpReminderNotification` (answered, stopped, `! acceptsRsvps()`, a deadline removed since; "in N days" recomputed),
  `GuestEventReminderNotification` (cancelled, not attending, `forDate` moved, past, day-of after start), `RsvpConfirmationNotification` (answer snapshot
  changed), `EventUpdatedNotification` (shared checks only; a cancelled event may be the announcement)
- A skip sets the log's `delivery_status = superseded` and `meta.skipped`, only when nothing later has been recorded

## Phase 2 — delivery outcome on the log, and retries (BUILT)

- `withLog($log)` carries the log id; `tagMail()` adds `X-EventHost-Ref` = `NotificationLog::mailRef()` (id + HMAC with the app key), verified by `idFromMailRef()`
- `RecordEmailAccepted` (`NotificationSent`, mail channel) sets `accepted`; the trait's `failed()` sets `failed` with `error_code` from
  `emailFailureCode()` (`invalid_address`, `transport`, `error`) and the exception's class name only. `CommunicationService::markFailed()` does the same for a
  synchronous failure. `startLog()` clears both columns when it reuses a failed row
- **Addition:** an address Symfony's `Address` refuses fails in `shouldSend()` without using the retries
- **Addition:** the bulk reminder (web and API) is refused on an unpublished event: the email's link would say "Invitation unavailable"
- 4 tries, `backoff()` 60 / 300 / 900. `failover` is `['smtp']`
- `App\Rules\GuestEmail` on host add/edit (edit: only a changed address), open RSVP web and API, group link, and the import row check

## Phase 3 — bounces over cPanel SMTP (BUILT)

- `MAIL_BOUNCE_ADDRESS` becomes every message's Return-Path (`SetBounceReturnPath` on `MessageSending`), which Symfony uses as the envelope sender
- `mail:process-bounces` (every 15 minutes, `--dry-run`, `--limit`) reads unseen messages over IMAP through `BounceMailbox` (`ImapBounceMailbox`,
  `webklex/php-imap`), moves each to `Processed`, prunes suppressions after `EmailSuppression::KEEP_DAYS` (365) and prints counts only
- `App\Support\BounceReport` parses RFC 3464 reports. **Additions:** Exim's generic `5.0.0` / `4.0.0` take the specific code from the diagnostic;
  addresses in the diagnostic are replaced with `[address]`
- **Deviation:** hard = `5.x` except `5.2.2` **and `5.7.x`**. A policy refusal is about the sender or content, not the address
- `EmailBounceProcessor` matches by signed ref (only while that guest still has the address), else the newest guest email to that address in 7 days. Unmatched
  changes nothing. Hard: log `bounced` + `error_code`, suppression row created or bumped under a lock. Soft: `meta.bounce` only
- Suppression: `CommunicationService::canEmail()` before `startLog()`, again in the guard, counted by bulk actions (`App\Support\EmailSkips`), refused by both
  "Tell them" actions. Receipts for tickets and contributions are not affected
- Host: `EmailDeliveryProblems` feeds the guest-list line and `GuestResource::email_delivery`. "Try this address again" is owner only
  (`EventPolicy::pause`). Privacy §3 and §7

## Phase 4 — duplicates and changes (BUILT)

- Bulk "send update email" (web and API) stores `meta.message_hash` and skips a guest sent the same text within 10 minutes; the skip reasons are counted per reason
  (`bulk_skip_reason` / `skipped_reason`, e.g. "2 have no email address, 1 email bounced")
- `Event::guestFacingChangeSentence()` drives the "tell your guests" prompt for venue **and** date/time changes on a live event (web JSON, flash, JS confirm, API
  `notify_guests.changed`)
- `EventUpdatedNotification` restates the current date, time and venue (or "This event has been cancelled.")

## Not covered

- Spam complaints: cPanel SMTP has no feedback loop. The reminders' List-Unsubscribe link stays the remedy
- Delivery confirmation beyond "the SMTP server accepted it"
- An automatic "event cancelled" email to guests
- Checking whether a domain can receive mail (DNS/MX lookup)
- Host-facing emails (new RSVP, awaiting approval, host reminder) are not tagged or suppressed; a bounce report never matches them
