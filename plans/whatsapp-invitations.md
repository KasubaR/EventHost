# WhatsApp Invitations via Twilio

Status: **built** (single-guest outbound send with cover image header + inbound quick-reply
RSVP + Accepted entry-pass QR confirmation + post-RSVP event-day reminders). Bulk Twilio send
and delivery-status webhooks are still future work.

Ops checklist: [docs/twilio.md](../docs/twilio.md).

## What this is

Host clicks **Send WhatsApp Invitation** → **WhatsApp card** template (`whatsapp/card`) with the
**event cover** as image header (`{{7}}` path after production host) + Yes/No/Maybe quick-reply
buttons. The plain Quick reply content type has no media field, so a card type is required.
Two templates in total: this invitation card and a Text template for event reminders.

Guest taps a button → `POST /webhooks/twilio/whatsapp` → `WhatsAppInboundRsvpService` →
`RsvpSubmissionService` (Accepted = `attendee_count` 1).

**Accepted** + `hasEntryPassFor` (Pro+): session `sendMedia` with
`/rsvp/{token}/entry-pass.png` + thank-you caption.
**Declined / Maybe / no entry pass:** session `sendText` only.

## Template variables

| Slot | Source |
|---|---|
| `1`–`5` | guest name, event name, date, time/`TBA`, venue/`Venue TBA` |
| `6` | `personalRsvpUrl()` |
| `7` | `Event::whatsAppInviteHeaderMediaPath()` (cover JPEG/PNG path or `images/default-event-wa.jpg`) |

Media URL in the Meta/Twilio card: `https://PRODUCTION_HOST/{{7}}`.

## Event reminders (post-RSVP)

Separate Content Template + scheduled command, timed off `event_date` (not `rsvp_deadline`):
**Accepted** guests only, Pro+ host, WhatsApp enabled.

- Buckets `7` / `1` / `0` calendar days before the event — `WhatsAppEventReminderBuckets`
  (lead copy per bucket, uses `$event->name`), tracked on `guests.whatsapp_event_reminders_sent`
  (`AsWhatsAppEventRemindersSent` cast, twin of `AsRsvpRemindersSent`)
- `CommunicationService::sendWhatsAppEventReminder(Event, Guest, string $bucket)` — same guard/log
  shape as `sendWhatsAppInvitation`, template SID `config('services.twilio.event_reminder_content_sid')`
  (`TWILIO_EVENT_REMINDER_CONTENT_SID`), vars `1`–`4` (lead, date, time/`TBA`, venue/`Venue TBA`),
  respects `communications.whatsapp.hourly_cap_per_event`
- `events:send-whatsapp-reminders` command — published invitation events, Pro+ owner,
  `$daysUntil ∈ {7,1,0}`, Accepted guests with a phone and bucket not already sent; scheduled
  `dailyAt('09:00')` in `routes/console.php`, alongside but separate from `rsvp:send-reminders`
  (the email "please RSVP" job, which stays deadline-relative and untouched)
- `NotificationLog` type `guest_event_reminder_whatsapp`
- Declined/Maybe guests are never reminded; no email equivalent of this message exists; no host UI
  to trigger it manually — see [docs/twilio.md](../docs/twilio.md) §5b for the ops-facing writeup

## Key classes

- `WhatsAppService` — `sendTemplate`, `sendText`, `sendMedia`
- `CommunicationService::sendWhatsAppInvitation`, `sendWhatsAppEventReminder`
- `WhatsAppInboundRsvpService`
- `TwilioWhatsAppWebhookController`
- `RsvpController::entryPassQrPng`
- `SendWhatsAppEventRemindersCommand` (`events:send-whatsapp-reminders`)
- `WhatsAppEventReminderBuckets` / `AsWhatsAppEventRemindersSent`

## Still future

- Bulk Twilio send
- Delivery/read status webhook
