# Twilio WhatsApp invitations — setup guide

Laravel sends personalized invitations via
`CommunicationService::sendWhatsAppInvitation()` and records RSVPs from quick-reply button
taps via `POST /webhooks/twilio/whatsapp`. This doc is the **ops checklist**.

Architecture details: [plans/whatsapp-invitations.md](../plans/whatsapp-invitations.md).

---

## 1. What you are setting up

| Piece | Purpose |
|---|---|
| Twilio Account + API Key | Auth for outbound REST (`messages->create`) |
| Account Auth Token | Verify `X-Twilio-Signature` on inbound webhooks |
| WhatsApp sender | Sandbox number (dev) or approved Business number (prod) |
| WhatsApp card Content Template (`HX…`) | Cover image header + Yes / No / Maybe quick-reply buttons |
| Text Content Template (`HX…`) | Event-day reminders (see §5b) |
| Inbound webhook URL | Twilio “A message comes in” → Laravel |
| `.env` flags | Wire credentials and turn the feature on |

Until `COMM_WHATSAPP_ENABLED=true` **and** outbound Twilio keys are set, the app binds
`NullWhatsAppService` and the send UI stays off.

The free **manual** `wa.me` “WhatsApp” link on the guest list does **not** need any of this.

---

## 2. Twilio account credentials

1. Open [Twilio Console](https://console.twilio.com/).
2. Copy **Account SID** (`AC…`) → `TWILIO_ACCOUNT_SID`.
3. Create an **API Key** (Account → API keys & tokens):
   - SID (`SK…`) → `TWILIO_API_KEY_SID`
   - Secret → `TWILIO_API_KEY_SECRET` (shown once — store it securely)
4. Copy the Account **Auth Token** → `TWILIO_AUTH_TOKEN` (webhook signature verification only;
   outbound REST still uses the API key).
5. If a key/token was ever pasted into chat, **rotate it** before production.

---

## 3. WhatsApp sender

### Dev — Twilio Sandbox

1. Console → Messaging → Try it out → **Send a WhatsApp message** (Sandbox).
2. Note the sandbox number (often `+14155238886`).
3. Set `TWILIO_WHATSAPP_FROM=whatsapp:+14155238886` (include the `whatsapp:` prefix).
4. Every test recipient must first join the sandbox (send the join code from their phone).

### Production — approved WhatsApp Business sender

1. Complete WhatsApp Business onboarding in Twilio.
2. Set `TWILIO_WHATSAPP_FROM=whatsapp:+260XXXXXXXXX` (your real sender, with the prefix).

---

## 4. Invitation Content Template (WhatsApp card: image header + quick replies)

Business-initiated WhatsApp messages **must** use a Meta-approved template. EventHost uses a
**WhatsApp card** (`whatsapp/card`) so one template carries an **image header** (event cover)
plus **quick-reply buttons** for RSVP. The plain **Quick reply** type has no media field, so it
cannot be used. Guests who need a plus-one use the personal RSVP URL in the body. After they
tap Yes on a Pro+ event, the session confirmation attaches their **entry-pass QR** (PNG).

Per Twilio's [whatsapp/card docs](https://www.twilio.com/docs/content/whatsappcard): `body`
(max 1,024 chars), `media` (cannot coexist with `header_text`), optional `footer`, and
`QUICK_REPLY` actions whose IDs come back as `ButtonPayload`. The combined media URL must
include the file type and resolve to a public file; once approved, the template can only send
that one media type (image).

### 4.1 In Twilio Console

1. Go to **Content Template Builder**.
2. Create a new template:
   - **Name:** `eventhost_invitation_rsvp`
   - **Category:** Utility (or the closest Meta accepts for invitations)
   - **Content type:** **WhatsApp card**
3. **Media** URL pattern (Twilio only allows variables *after* the domain):

```
https://YOUR_PRODUCTION_HOST/{{7}}
```

   Sample for Meta approval: `https://YOUR_PRODUCTION_HOST/images/default-event.png`
   (variable `7` sample value: `images/default-event.png`).

4. **Body** — paste exactly (variables must match Laravel):

```
Hello {{1}} 👋

You are invited to
{{2}}.

📅 {{3}}
🕐 {{4}}
📍 {{5}}

Will you attend?
Reply with a button below.

Need a plus-one or to change details?
{{6}}

Thank you.
```

   The closing static line is deliberate: Meta commonly rejects a body that ends in a
   variable. Laravel only fills `{{1}}`–`{{7}}`, so the static text can be reworded freely.

5. **Quick reply buttons** (set stable IDs — Twilio returns these as `ButtonPayload`):

| Title | ID / payload |
|---|---|
| Yes, I'll attend | `rsvp_accepted` |
| No, I can't attend | `rsvp_declined` |
| I'll let you know | `rsvp_maybe` |

6. Submit for Meta approval. Expect hours to 1–2 days.
7. When approved, copy the Content SID (`HX…`) → `TWILIO_INVITATION_CONTENT_SID`.

Do **not** edit an old text-only / CTA template in place. Create a new WhatsApp card
template and swap the SID in `.env`.

### 4.2 What Laravel fills in

| Slot | Meaning | Example |
|---|---|---|
| `{{1}}` | Guest name | `John` |
| `{{2}}` | Event name | `Mary & David Wedding` |
| `{{3}}` | Date | `12 December 2026` |
| `{{4}}` | Time (`TBA` if none) | `14:00` |
| `{{5}}` | Venue (`Venue TBA` if none) | `Ciela Resort` |
| `{{6}}` | Full personal RSVP URL | `https://…/rsvp/<token>` |
| `{{7}}` | Cover path after host (JPEG/PNG) | `storage/events/….jpg` or `images/default-event.png` |

`{{7}}` is never a full URL — the production host is baked into the template header.
WebP covers are converted once to a cached JPEG under `storage/events/wa_cover_{id}.jpg`
(WhatsApp headers require JPEG/PNG).

Accepted via WhatsApp always records **1** attendee. Plus-ones use the web link in `{{6}}`.

---

## 5. Inbound webhook (RSVP replies)

1. In Twilio Console → your WhatsApp sender / Messaging Service → **A message comes in**:
   - Method: `HTTP POST`
   - URL: `https://YOUR_PRODUCTION_HOST/webhooks/twilio/whatsapp`
2. Laravel route: `TwilioWhatsAppWebhookController` (CSRF-exempt, signature-verified).
3. Flow: guest taps Yes/No/Maybe → Twilio POSTs → `WhatsAppInboundRsvpService` →
   `RsvpSubmissionService` → host dashboard stats update → session confirmation:
   - **Accepted** + entry-pass eligible (Pro+): `sendMedia` with
     `GET /rsvp/{token}/entry-pass.png` + thank-you caption
   - **Accepted** without entry pass, or Declined/Maybe: `sendText` caption only

`APP_URL` must be publicly reachable HTTPS so Twilio can fetch cover paths and entry-pass PNGs
(local sandbox needs a tunnel).

Guest matching:

1. Prefer `OriginalRepliedMessageSid` → outbound invite `NotificationLog.provider_message_id`
2. Else unique recent invite by phone (E.164). Ambiguous matches are ignored (no write).

---

## 5b. Event reminders (Accepted guests)

Scheduled command `events:send-whatsapp-reminders` runs **daily at 09:00 Africa/Lusaka** (same
schedule as `rsvp:send-reminders`; the app timezone itself stays UTC). It is separate from email “please RSVP” reminders.

| When (vs `event_date`) | Bucket | Lead (`{{1}}`) |
|---|---|---|
| 7 days before | `7` | `{event} is one week away!` |
| 1 day before | `1` | `Reminder: {event} is tomorrow.` |
| Event day | `0` | `Today is the big day! We look forward to seeing you at {event}.` |

One template serves all three buckets — the changing lead line is `{{1}}`.

**Who gets it:** Accepted RSVP + Zambian phone + Pro+ host + WhatsApp enabled.  
Tracked on `guests.whatsapp_event_reminders_sent`. Log type: `guest_event_reminder_whatsapp`.

**Ops:** create a **Text** Content Template, category Utility, name `eventhost_event_reminder`,
paste this body, get it approved, then set `TWILIO_EVENT_REMINDER_CONTENT_SID`:

```
Hello 👋

{{1}}

📅 {{2}}
🕐 {{3}}
📍 {{4}}

Thank you.
```

Sample values for Meta: `{{1}}` `Mary & David Wedding is tomorrow.`, `{{2}}` `12 December 2026`,
`{{3}}` `14:00`, `{{4}}` `Ciela Resort`. The opening and closing static lines keep the body
from starting or ending with a variable.

---

## 6. Environment variables (still needed)

Outbound invite stack is already in local `.env`
(`TWILIO_ACCOUNT_SID` / API key / `TWILIO_WHATSAPP_FROM` /
`TWILIO_INVITATION_CONTENT_SID` / `COMM_WHATSAPP_ENABLED=true`).

Still missing:

```env
TWILIO_AUTH_TOKEN=xxxxxxxx
TWILIO_EVENT_REMINDER_CONTENT_SID=HXxxxxxxxx
```

Optional: `COMM_WHATSAPP_HOURLY_CAP_PER_EVENT=100` (app default applies if unset).

```bash
php artisan config:clear
# or after deploy:
php artisan config:cache
```

Config maps:

- Credentials → `config/services.php` → `services.twilio.*`
- Feature flag / cap → `config/communications.php` → `communications.whatsapp.*`

---

## 7. Who can send (product gate)

Automated WhatsApp invite is **Pro and above** (`Event::ownerHasPremiumEventTools()`).
Base / none hosts can still use the manual `wa.me` link.

---

## 8. How to verify

### Outbound invite

1. Pro host, invitation event, guest with Zambian phone + `invitation_token`
2. Guests → **Send WhatsApp Invitation**
3. Expect flash sent, `invitation_sent`, `notification_logs` type `guest_invitation_whatsapp`
4. Guest sees Yes / No / Maybe buttons

### Inbound RSVP

1. Tap **Yes, I'll attend** on the phone
2. Host guest list shows Accepted (1 head)
3. Guest receives a confirmation WhatsApp with the web link
4. `notification_logs` type `guest_rsvp_whatsapp_inbound`

### Remaining ops checklist

- [ ] `TWILIO_AUTH_TOKEN` in secrets / server `.env` (webhook signatures)
- [ ] Event reminder template approved (`{{1}}`–`{{4}}`) → `TWILIO_EVENT_REMINDER_CONTENT_SID`
- [ ] Webhook URL points at HTTPS `/webhooks/twilio/whatsapp`
- [ ] Scheduler runs `events:send-whatsapp-reminders` (via `schedule:run`)
- [ ] One live send + button tap + reminder smoke-tested end-to-end

---

## 9. Common failures

| Symptom | Likely cause |
|---|---|
| No “Send WhatsApp Invitation” in UI | Flag off or Twilio outbound keys incomplete |
| Flash invalid phone | Phone not Zambian E.164, or no `invitation_token` |
| Flash rate limited | Hit hourly cap for that event |
| 403 on send | Host is not Pro+ |
| Webhook 403 | Bad/missing `TWILIO_AUTH_TOKEN` or signature URL mismatch (must be exact public URL) |
| Tap does nothing in DB | Wrong button IDs (must be `rsvp_*`), or ambiguous phone without `OriginalRepliedMessageSid` |
| “Event is full” reply | `guest_limit` reached for Accepted |
| RSVP closed reply | Past `rsvp_deadline` / paused / cancelled |
| No event-day WhatsApp reminders | Not Pro+, WhatsApp off, wrong SID, guest not Accepted, or bucket already sent |

---

## 10. What is intentionally not done yet

- Bulk “Send WhatsApp Invitations” (queued jobs)
- Delivery / read status webhooks from Twilio
- Email versions of post-RSVP event-day reminders
- Host UI to manually fire event reminders

See [plans/whatsapp-invitations.md](../plans/whatsapp-invitations.md).
