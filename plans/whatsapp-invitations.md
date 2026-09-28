# WhatsApp Invitations via Twilio

Status: **built** (single-guest outbound send with cover image header + inbound quick-reply
RSVP + Accepted entry-pass QR confirmation + post-RSVP event-day reminders). **Web/API RSVP
confirmation is planned, not built** — see that section below. Bulk Twilio send and
delivery-status webhooks are still future work.

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

## Web/API RSVP confirmation (planned)

**The gap:** `CommunicationService::dispatchRsvpNotifications()` — the one path shared by web RSVP,
API RSVP and WhatsApp inbound quick-reply — only ever sends **email**
(`sendRsvpConfirmation`) + notifies the host. The WhatsApp confirmation-with-pass described above
under "Guest taps a button…" only fires for the **inbound quick-reply** path, as a session reply
inside `WhatsAppInboundRsvpService`. A guest who opens their personal `rsvp/{token}` link and
submits the web form — regardless of how they were originally invited — gets email only, today,
by omission rather than design. Reported live 2026-09-28.

**The fix:** a third business-initiated send, `CommunicationService::sendWhatsAppRsvpConfirmation
(Event, Guest, Rsvp)`, called from `dispatchRsvpNotifications()` right after the email send. Unlike
the inbound flow it cannot rely on an open session (a web-form submit never talks to Twilio), so it
needs its **own approved Content Template** — same constraint that already applies to the invitation
and reminder sends.

- **Eligibility mirrors `Guest::hasEntryPassFor($rsvp, $event)` exactly** — Accepted, has an
  `invitation_token`, host on a plan with `ownerHasPremiumEventTools()`. Declined/Maybe RSVPs and
  non-premium hosts get **email only**, same as today; there is no text-only fallback template for
  those, unlike the inbound flow's `sendText` branch — one new template, not two, matches what was
  actually asked for
- **Template type:** `whatsapp/card` (image header + body), mirroring the invitation card exactly,
  category Utility. Header image points at the guest's **own pass**, not the event cover
- New content SID: `TWILIO_RSVP_CONFIRMATION_CONTENT_SID` → `config('services.twilio.rsvp_confirmation_content_sid')`
- New `NotificationLog` type: `guest_rsvp_confirmation_whatsapp` (distinct from the inbound flow's
  `guest_rsvp_whatsapp_inbound` and from `guest_invitation_whatsapp`)
- Same guard shape as `sendWhatsAppInvitation`/`sendWhatsAppEventReminder`: `communications.whatsapp.enabled`,
  phone → `ZambianPhone::toE164()`, `communications.whatsapp.hourly_cap_per_event` event-scoped guard,
  `startLog()`/`markFailed()` bookkeeping. No idempotency key — same posture as `sendRsvpConfirmation`
  (email) already has, so an edited/resubmitted RSVP can notify again exactly like the email side does
- **New `Guest::whatsAppPassMediaPath()`** — the "path after the app origin" twin of
  `Event::whatsAppInviteHeaderMediaPath()`, pointing at `rsvp.token.pass-image` (the same
  `GuestPassImageService`-rendered PNG the inbound flow's `sendMedia` already fetches, and the
  bookmarkable pass page already shows) rather than a static file. Twilio doesn't care that the URL
  is a dynamic route instead of a stored asset — it only needs to resolve to an image at send time,
  and that route already degrades to a plain QR PNG rather than failing (see Guest Invitation Pass
  in CLAUDE.md), so this send is never blocked by a broken renderer. Returns null (caller must skip)
  when `invitation_token` is null, same contract as `entryPassPngUrl()`
- Template body (variables numbered to match `sendWhatsAppInvitation`'s convention — media last):

  ```
  Hello {{1}} 👋

  You're confirmed for {{2}}! 🎉

  📅 {{3}}
  🕐 {{4}}
  📍 {{5}}

  Your entry pass is attached above — view or save it anytime here:
  {{6}}

  We can't wait to see you there. Thank you!
  ```

  | Slot | Source |
  |---|---|
  | `1` | `filled($guest->name) ? $guest->name : 'Guest'` |
  | `2` | `$event->name` |
  | `3` | `$event->event_date?->format('j F Y')` |
  | `4` | `$event->hasStartTime() ? … : 'TBA'` |
  | `5` | `filled($event->venue) ? $event->venue : 'Venue TBA'` |
  | `6` | `$guest->passPageUrl()` |
  | `7` (media, `https://HOST/{{7}}`) | `$guest->whatsAppPassMediaPath()` |

  Static opening/closing lines are deliberate — Meta rejected the reminder template once already
  for being too short relative to its variable count (error `2388293`); do not trim them to make
  the template "cleaner."

**Ops steps (once built):** create the template in Content Template Builder exactly as above →
submit for Meta approval → `TWILIO_RSVP_CONFIRMATION_CONTENT_SID` in `.env` → `config:clear`. Add
to [docs/twilio.md](../docs/twilio.md) as a new §4c once implemented, alongside §4 (invitation) and
§5b (reminders) — including a "How to verify" and "Common failures" entry for this path.

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
