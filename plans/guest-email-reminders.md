# Guest event reminders by email

Status: **Phases 1 and 2 built** (shared schedule and the cancelled-event fix; the email itself, shipping dark behind
`COMM_GUEST_EMAIL_REMINDERS_ENABLED`). Phase 3 (a stop link) and Phase 4 (Privacy wording, go-live) are still planned — don't
turn the flag on in production before Phase 4's copy is in.

## 1. What this is, and why

A guest who accepts an invitation to a private (invitation-kind) event is reminded about it **only over WhatsApp**
(`events:send-whatsapp-reminders`: 7 days before, 1 day before, on the day). A guest with no phone number, a
non-Zambian number, or a host with WhatsApp switched off gets nothing between "RSVP recorded" and the door. This plan
adds the same three reminders by **email**, to every Accepted guest who has an email address.

What guests can already receive, so the gap is clear:

| Message | WhatsApp | Email |
|---|---|---|
| Invitation | Card with Yes / No / Maybe buttons | none — hosts share the link by hand |
| RSVP confirmation | Reply to the button tap (+ pass image) | `RsvpConfirmationNotification` (+ pass PDF/PNG) |
| **Event reminders 7 / 1 / 0 days before** | `SendWhatsAppEventRemindersCommand` | **none — this plan** |
| RSVP-deadline reminder to non-responders | none | `rsvp:send-reminders` (7 / 3 / 1 days before the *deadline*) |

Out of scope: an email *invitation* (a separate feature), reminders to ticket holders (ticketed events have orders and
tickets, not guests — `Event::ownerCanSendAutomatedReminders()` is invitation-only for that reason), SMS, and reminding
guests who answered Maybe.

## 2. Found while planning: cancelled events still get WhatsApp reminders — **fixed in Phase 1**

`SendWhatsAppEventRemindersCommand` selects `is_published = true` events with a date and never looks at
`cancelled_at`. Cancelling an event (`EventController::cancel()`) sets `cancelled_at` and leaves `is_published` true, and
neither `CommunicationService::sendWhatsAppEventReminder()` nor the command re-checks it. So a cancelled wedding still
tells every accepted guest "one week away!". `SendHostEventRemindersCommand` filters `whereNull('cancelled_at')`; the
guest-facing one does not. Phase 1 fixes this for WhatsApp and builds the email on the corrected selection, so the two
cannot disagree again.

## 3. Decisions

Recommendations are the default; each is the owner's call.

| # | Question | Recommendation |
|---|---|---|
| D1 | Same plan gate as the other automated reminders (Pro+, `ownerCanSendAutomatedReminders()`)? | **Yes.** One rule for every automated guest reminder; the pricing page already sells "Email + WhatsApp reminders" at Pro+ |
| D2 | Guest who has both an email and a WhatsApp number: both channels, or one? | **Both, independently.** WhatsApp `sent` only means Twilio accepted it, and many hosts will have email only. Add suppression later if hosts complain about doubles |
| D3 | Only Accepted guests? | **Yes**, same as WhatsApp. Maybe / Declined / no reply are not reminded about the event |
| D4 | A way for a guest to stop reminders? | **Phase 3, recommended before turning it on widely.** No guest-facing unsubscribe exists anywhere today, and guests have no account to switch anything off in. It is a transactional reminder to someone who said yes, so it is not a blocker for a first release |
| D5 | Feature flag? | **Yes, off by default** (`COMM_GUEST_EMAIL_REMINDERS_ENABLED`), like WhatsApp, SMS and purging |

## 4. Design

### 4.1 Which events, which guests

A daily command `events:send-guest-email-reminders` at 09:00 Africa/Lusaka (same slot and same shape as
`events:send-whatsapp-reminders`):

- events that are published, not deleted, **not cancelled**, invitation-kind, have an `event_date`, and pass
  `ownerCanSendAutomatedReminders()`
- `daysUntil = today.diffInDays(event_date)` must be 7, 1 or 0
- guests with a non-empty `email` **and** an Accepted RSVP
- a paused invitation (`invitation_paused_at`) still reminds: pausing stops new responses, the event is still happening
- day-of reminder is skipped if the event has a start time that has already passed at send time (09:00 for an 08:00 event)

### 4.2 Send-once guarantee

No new column. The unique `notification_logs.idempotency_key` is the guard, using the existing `startLog()` (a
sent/pending row blocks a repeat; a failed one allows a retry):

```
email-event-reminder:{event_id}:{guest_id}:{bucket}:{event_date Y-m-d}
```

The **event date is in the key on purpose.** If a host moves the event after the 7-day reminder went out, the guest
should be reminded again for the new date; a key of just event+guest+bucket would swallow it. (The WhatsApp reminder
tracks sent buckets in a `guests` column with no date, so a moved event silently misses its reminders — worth fixing
there in Phase 1 by keying its log the same way.)

### 4.3 Sending

- `CommunicationService::sendGuestEventReminderEmail(Event, Guest, string $bucket): string` returning
  `'sent' | 'disabled' | 'skipped' | 'rate_limited' | 'failed'`, a twin of `sendWhatsAppEventReminder()`. Checks the
  flag, the plan gate, the bucket, an Accepted RSVP and an email address; logs with channel `email`, type
  `guest_event_reminder_email`, and `meta.bucket`
- `GuestEventReminderNotification` — queued on `default`, 3 tries, 120 s backoff, exactly like
  `RsvpReminderNotification`. Sent with `Notification::route('mail', $guest->email)`
- Volume cap: reuse `communications.reminder_hourly_cap_per_event` (500). Email costs nothing per message, so it does not
  need WhatsApp's stricter 100

### 4.4 The email

One template, three leads, taken from the same source as WhatsApp so the wording cannot drift
(`EventReminderBuckets::lead()`, Phase 1):

| Bucket | Subject | Lead |
|---|---|---|
| 7 | `One week to go: {event}` | `{event} is one week away!` |
| 1 | `Tomorrow: {event}` | `Reminder: {event} is tomorrow.` |
| 0 | `Today: {event}` | `Today is the big day! We look forward to seeing you at {event}.` |

Body: greeting, lead, date, time (`TBA` when none), venue (`Venue TBA`), and a map link when the event has coordinates.
Button: **View your pass** (`passPageUrl()`) when `hasEntryPassFor()` is true, otherwise **View invitation details**
(the guest's personal RSVP link, or the public invitation page when they have no token). While RSVP is still open, one
line offers to change the response via the same link. Salutation as the other guest mails.

**No attachments.** The confirmation email already attached the pass PDF and PNG; rendering them again three times per
guest is wasted work and the pass page has both downloads.

### 4.5 Configuration

`config/communications.php`:

```php
'guest_email_reminders' => [
    'enabled' => (bool) env('COMM_GUEST_EMAIL_REMINDERS_ENABLED', false),
],
```

`phpunit.xml` turns it on so the suite exercises it, as it does for contributions.

## 5. Phases

| Phase | Contents | Ships |
|---|---|---|
| 1 | **Built.** **Shared schedule + the cancelled fix.** `App\Support\EventReminderBuckets` (`ALL`, `forDaysUntil()`, `lead()`); `WhatsAppEventReminderBuckets` delegates its lead to it. One shared "events due a reminder today" selection used by the WhatsApp command (and later the email one) that excludes cancelled events. WhatsApp log/idempotency keyed on event date. Tests: cancelled event gets no WhatsApp reminder; a moved event is reminded again | Safe on its own; fixes a live bug |
| 2 | **Built.** **The email.** Config flag, `GuestEventReminderNotification`, `CommunicationService::sendGuestEventReminderEmail()`, `events:send-guest-email-reminders` + schedule, `NotificationLog` rows. Ships dark | Behind the flag |
| 3 | **Stop reminders link** (D4). `guests.email_reminders_stopped_at`, a signed no-login route, a confirmation page, a footer link on the email, and the command skips stopped guests. Also honoured by the deadline-reminder email | Before enabling for everyone |
| 4 | **Copy, docs, go-live.** Privacy §4 wording, `CLAUDE.md`, `docs/deployment.md` go-live note, this plan marked built | With Phase 2 or 3 |

Order of work is 1 → 2 → (3) → 4. Phase 1 is worth doing even if the email is never built.

**Phase 1 as built** (differences from the table above, and what to reuse):

- `App\Support\EventReminderBuckets` — `ALL`, `forDaysUntil()`, `forEvent(Event, ?now)` (whole calendar days, so the run
  time is irrelevant), `lead()`, `MAX_LEAD_DAYS`. `WhatsAppEventReminderBuckets` keeps only the `guests`-column
  normalisation; its constants and `leadForBucket()` delegate
- `Event::scopeDueForGuestEventReminder(?now)` is the shared selection: invitation kind, published, **not cancelled**,
  dated within the next 7 days (soft-deleted events are already excluded). The email command must use it too. The plan gate
  (`ownerCanSendAutomatedReminders()`) stays per event in the caller. A paused invitation is deliberately still selected
- `CommunicationService::sendWhatsAppEventReminder()` also returns `skipped` for a cancelled or trashed event, so the rule
  holds for any caller, not just the command
- The WhatsApp log key is now `wa-event-reminder:{event}:{guest}:{bucket}:{Y-m-d}`. The `guests.whatsapp_event_reminders_sent`
  column is still the cheap first check, so moving the date has to clear it: `Event::booted()`'s `updated` hook nulls it
  for that event's guests when `event_date` changes (and only then). The two together are what re-arm a moved event —
  the key alone would not, because the column would still say "sent". Keys written before this change have no date
  and simply never match again; the column still protects the deploy day
**Phase 2 as built:**

- `config('communications.guest_email_reminders.enabled')` (env `COMM_GUEST_EMAIL_REMINDERS_ENABLED`, default false; `phpunit.xml`
  turns it on). `.env.example` documents it
- `App\Notifications\GuestEventReminderNotification` — queued, `default`, 3 tries / 120 s backoff, no attachments. Subject
  `One week to go:` / `Tomorrow:` / `Today:` + event name; lead from `EventReminderBuckets::lead()`; date, time, venue; a Google
  Maps link when the event has coordinates; button **View your pass** (`hasEntryPassFor()`), else **View invitation details**
  (personal RSVP link, or `/e/{slug}` for a guest with no token); "update your response" only while RSVP is open; a closing line
  saying why they got it
- `CommunicationService::sendGuestEventReminderEmail()` returns `sent | disabled | skipped | rate_limited`, enforcing every rule
  itself (flag, Pro+, not cancelled/deleted, valid bucket, email present, Accepted RSVP, day-of not after the start time, hourly
  cap) so it is safe for any caller. Log: channel `email`, type `guest_event_reminder_email`, `meta.bucket`, key
  `email-event-reminder:{event}:{guest}:{bucket}:{Y-m-d}`
- `events:send-guest-email-reminders`, scheduled 09:00 Africa/Lusaka next to the WhatsApp one, using the shared
  `dueForGuestEventReminder()` scope. It stops an event's run at the cap and the next run resumes
- **Deviation — retry of a failed send:** §4.2 said a failed log allowed a retry. It did not: `idempotency_key` is unique, so
  `startLog()` threw a unique-constraint error on the second attempt (and would have for the RSVP and WhatsApp reminders too).
  `startLog()` now reuses a *failed* row for the same key (back to pending) and still refuses a pending or sent one. This
  changes the shared method, for the better: nothing relied on the exception
- The day-of skip uses `Event::startsAt()` (venue timezone). Not in the plan's tests: the command's scope is not separately
  provable from the sender's own checks, which enforce the same rules

- Not changed: `rsvp_deadline` reminders have the same "moved date" gap (`rsvp_reminders_sent` is never cleared when the
  deadline moves). Out of scope here; noted so it is not forgotten

## 6. Tests

Phase 1: cancelled event → no WhatsApp reminder, uncancelled → reminded; date change re-arms a bucket; the shared
selection returns nothing for a draft, deleted, ticketed or unpublished event. Phase 2 (mutation-check each):

- sends for 7 / 1 / 0 days, and for no other day
- only Accepted guests with an email; not Maybe, Declined, unanswered or email-less
- not for a cancelled, deleted, unpublished or ticketed event; still for a paused invitation
- Pro+ gate (Base / Pro hosts get nothing); flag off sends nothing
- sends once per bucket across two runs; a failed send retries; a moved event reminds again
- day-of reminder skipped when the start time has passed
- pass button when eligible, invitation link otherwise; no attachments
- hourly cap stops at the limit and the next run continues
- log row has channel `email`, type `guest_event_reminder_email`, bucket in `meta`
- coexists with WhatsApp: a guest with both gets one of each

## 7. Copy and docs

- **Privacy §4** currently says guest contact details are used "to deliver invitations and RSVP confirmations, and to
  collect responses". Add "and to remind them about the event" (it does not cover event reminders today, by WhatsApp
  either). §3 says guest data is used for "nothing else" — reword it with it
- `CLAUDE.md`: a short "Guest event reminders" note under the pass / WhatsApp material (the flag, the idempotency key
  including the date, the shared selection, no attachments)
- `docs/deployment.md`: a line that the flag is off until set, and that it needs the scheduler and queue worker
  (the same two things the WhatsApp reminder needs)

## 8. Risks

- **Doubles.** A guest with both channels gets both at 09:00 (D2). Acceptable to start; a suppression rule is a small
  follow-up if it draws complaints
- **Third-party recipients.** These addresses were typed in by a host, not by the guest. Phase 3 exists for that reason
- **Deliverability.** Nothing new — same sender and queue as the RSVP reminder
- **09:00 day-of.** A morning event has already begun; handled by the skip in §4.1, but an early event simply gets no
  day-of email. The 1-day reminder covers it
