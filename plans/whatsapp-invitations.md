# WhatsApp Invitations via Twilio

Status: **proposed** — nothing built yet.

## What this is

A **server-initiated** WhatsApp send for guest invitations, using Twilio's WhatsApp Business API. A
host clicks "Send WhatsApp Invitation" on a guest row and EventHost sends the message directly —
no dependency on the host's own phone or WhatsApp Web session.

This is a different mechanism from what already exists in this app, not a replacement for it.

## What already exists (do not remove)

[app/Support/WhatsAppInviteLink.php](app/Support/WhatsAppInviteLink.php) builds a `wa.me/<phone>?text=<message>`
deep link. The guest index view ([resources/views/events/guests/index.blade.php:245](resources/views/events/guests/index.blade.php))
renders that link per row; clicking it opens the **host's own** WhatsApp app/Web with the message
prefilled, and the host taps Send themselves. There's also a bulk "Prepare WhatsApp share" action
that stages links for several guests at once.

This flow works today, costs nothing, and needs no Meta template approval — because the message is
sent by the host's own WhatsApp identity, not by a business API on EventHost's behalf. Keep it as
the always-available fallback for hosts who haven't (or can't) configure the Twilio integration
below, and for anyone who prefers the personal touch of sending from their own number.

The new Twilio path is additive: a second, automated option that shows up once
`communications.whatsapp.enabled` is true, sitting next to the existing "WhatsApp" (manual) link in
the row's action menu — probably relabeled "WhatsApp (manual)" vs. "Send WhatsApp Invitation"
once both exist, so hosts can tell them apart.

## Why a template, not a plain message

Meta requires WhatsApp Business API messages sent outside a 24-hour customer-service window (i.e.
any message the business starts, which an invitation always is) to use a **pre-approved template**.
Free-text `body` sends will be rejected by Twilio/Meta outside that window. So the invitation copy
in the user's request —

```
💌 You're invited to John's Wedding
📅 12 December 2026
🕐 14:00
📍 Ciela Resort
Please RSVP here: [RSVP]
```

— has to be submitted to Meta (via the Twilio Console's Content Template Builder) as a template with
named variables, get approved (usually hours, sometimes 1–2 days), and then be invoked by its
**Content SID** (`HX…`) plus a `content_variables` JSON payload, not by sending that string directly.

Proposed template (Twilio Content Template Builder, category "Utility"):

```
💌 You're invited to {{1}}!

📅 {{2}}
🕐 {{3}}
📍 {{4}}

Please RSVP here:
{{5}}
```

Variables: `{{1}}` event name, `{{2}}` formatted date, `{{3}}` formatted time, `{{4}}` venue,
`{{5}}` the guest's personal RSVP URL (`$guest->personalRsvpUrl()`, already exists — see
[app/Models/Guest.php:150](app/Models/Guest.php)). Venue can be blank for a TBD location; confirm
with Meta's reviewers whether a blank variable is acceptable or whether the row needs to be omitted
per-send (templates can't conditionally hide a line, so a fallback string like "Venue TBA" is safer
than an empty `{{4}}`).

**Open question for you:** decide the exact final wording/emoji before submitting for approval —
once approved, editing the body text requires re-submitting and re-approval, so get copy sign-off
first. Also decide whether to register this under a WhatsApp Business "Business Account" you
control, or under Twilio's own facilitated onboarding — that determines how fast approval and future
template edits go.

## Config additions

Mirrors the existing SMS/push pattern exactly (`config/communications.php` `enabled` flag +
`config/services.php` credentials block):

```php
// config/services.php
'twilio' => [
    'account_sid' => env('TWILIO_ACCOUNT_SID'),          // AC...
    'api_key_sid' => env('TWILIO_API_KEY_SID'),           // SK... (already have)
    'api_key_secret' => env('TWILIO_API_KEY_SECRET'),     // (already have — ROTATE before use, see below)
    'whatsapp_from' => env('TWILIO_WHATSAPP_FROM'),       // e.g. 'whatsapp:+14155238886' (sandbox) or approved sender
    'messaging_service_sid' => env('TWILIO_MESSAGING_SERVICE_SID'), // optional, if using a Messaging Service instead of a raw number
    'invitation_content_sid' => env('TWILIO_INVITATION_CONTENT_SID'), // HX... approved template
],

// config/communications.php
'whatsapp' => [
    'enabled' => (bool) env('COMM_WHATSAPP_ENABLED', false),
    'hourly_cap_per_event' => (int) env('COMM_WHATSAPP_HOURLY_CAP_PER_EVENT', 100),
],
```

**Security note:** the API Key SID/Secret you pasted in chat must be rotated in the Twilio Console
before going into `.env` — see the message above this plan. Nothing from that pair is written into
this plan or any repo file.

## Service layer (mirrors `SmsService` / `PushNotificationService`)

- `app/Services/WhatsAppService.php` — interface:
  ```php
  interface WhatsAppService
  {
      /**
       * @param array<string, string> $templateVariables  keyed "1".."5" for Twilio Content API
       * @return array{status:string,provider_message_id:?string,response:?string}
       */
      public function sendTemplate(string $toE164Phone, string $contentSid, array $templateVariables): array;
  }
  ```
- `app/Services/NullWhatsAppService.php` — returns `status: 'skipped'`, same shape as `NullSmsService`,
  bound until `communications.whatsapp.enabled` + credentials are set. Graceful no-op, never throws.
- `app/Services/TwilioWhatsAppService.php` — real implementation using `twilio/sdk`
  (`composer require twilio/sdk`). Authenticates with the API Key SID/Secret + Account SID (Twilio's
  REST client accepts API Key auth as `new Client($apiKeySid, $apiKeySecret, $accountSid)`). Calls
  `$client->messages->create($to, ['from' => ..., 'contentSid' => ..., 'contentVariables' => json_encode($vars)])`.
  Maps Twilio's message `status` (`queued`/`sent`/`failed`/`undelivered`) to the `sent`/`failed`
  shape the interface expects, and catches `Twilio\Exceptions\RestException` into the `failed`
  branch rather than letting it bubble as an unrelated 500.
- Bind in `AppServiceProvider`, same conditional pattern as `SmsService`:
  ```php
  $this->app->singleton(WhatsAppService::class, function () {
      return config('communications.whatsapp.enabled')
          ? TwilioWhatsAppService::class
          : NullWhatsAppService::class;
  });
  ```
  (Check the exact existing conditional shape in `AppServiceProvider` for SMS/push and match it —
  don't introduce a third style.)

## Phone normalization

Twilio needs E.164 (`whatsapp:+260977123456`). `Guest::phone` is stored however the host typed it.
Reuse the existing normalizer rather than writing a new one — `EventContribution::normalizePhone()`
([app/Models/EventContribution.php:92](app/Models/EventContribution.php)) already strips a Zambian
number down to 9 local digits from any of the `0977…` / `+260977…` / `260977…` shapes. Extract that
into a shared helper (e.g. a static method on `App\Rules\ZambianPhoneNumber` or a small
`App\Support\ZambianPhone` class both can call) so there's one source of truth, then prepend `+260`
for the Twilio call. Guests with a phone that doesn't normalize to 9 digits (non-Zambian numbers, if
any exist) should disable the button with a tooltip, same as the existing "no phone" disabled state
in the guest row's action menu.

## CommunicationService + logging

Add `sendWhatsAppInvitation(Event $event, Guest $guest)` to `CommunicationService`, following the
exact shape of `sendSmsUpdate()`:

```php
public function sendWhatsAppInvitation(Event $event, Guest $guest): void
{
    if (! (bool) config('communications.whatsapp.enabled', false)) {
        return;
    }

    if (! is_string($guest->phone) || trim($guest->phone) === '' || $guest->invitation_token === null) {
        return;
    }

    $log = $this->startLog($event, $guest, 'whatsapp', 'guest_invitation_whatsapp', null, null);
    if ($log === null) {
        return;
    }

    try {
        $result = $this->whatsAppService->sendTemplate(
            ZambianPhone::toE164($guest->phone),
            config('services.twilio.invitation_content_sid'),
            [
                '1' => $event->name,
                '2' => $event->event_date->format('j F Y'),
                '3' => $event->event_time?->format('H:i') ?? '',
                '4' => $event->venue_name ?: 'Venue TBA',
                '5' => $guest->personalRsvpUrl(),
            ]
        );
        $status = $result['status'] === 'sent' ? NotificationLog::STATUS_SENT : NotificationLog::STATUS_FAILED;
        $log->forceFill([
            'status' => $status,
            'provider_message_id' => $result['provider_message_id'],
            'response' => $result['response'],
            'sent_at' => $status === NotificationLog::STATUS_SENT ? now() : null,
        ])->save();
    } catch (\Throwable $e) {
        $this->markFailed($log, $e);
        throw $e;
    }
}
```

`NotificationLog.channel` is a plain `string(32)` column ([migration](database/migrations) —
confirmed, not a DB enum), so `'whatsapp'` needs **no migration**.

Field names above (`event_date`, `event_time`, `venue_name`) are guesses at the actual `Event`
schema — verify against the real model before implementing.

## Route / controller / UI

- New route, owner-only (same policy as `markInvitationSent`):
  `POST /events/{event}/guests/{guest}/whatsapp-invite` → `GuestController::sendWhatsAppInvitation`
  (or a small dedicated `WhatsAppInvitationController` if `GuestController` is getting crowded).
- Controller calls `CommunicationService::sendWhatsAppInvitation()`, then redirects back with a flash
  message (`guest-whatsapp-sent` / `guest-whatsapp-failed`), matching the `guest-invitation-marked-sent`
  flash pattern already in the index view.
- On success, also forceFill `invitation_sent = true, invitation_sent_at = now()` — a real automated
  send is stronger evidence than the manual "mark as sent" checkbox, so it should set the same flag
  rather than requiring a second manual click.
- UI: in the row's `evt-more-menu`, add a "Send WhatsApp Invitation" item next to the existing
  manual "WhatsApp" link, visible when `config('communications.whatsapp.enabled')` — pass that down
  as a view variable, don't call `config()` directly in the Blade file (match how `.env`-gated UI
  is already toggled elsewhere in this codebase, e.g. check how `push`/`fcm` config gates any
  existing view, if it does).
- Bulk version: extend the existing `GuestBulkActionController` "Prepare WhatsApp share" action with
  a sibling "Send WhatsApp Invitations" bulk action, capped by the new
  `communications.whatsapp.hourly_cap_per_event` config (same spirit as
  `reminder_hourly_cap_per_event`), to avoid a host blasting hundreds of guests past Twilio's/Meta's
  own rate limits in one click. Queue the bulk send (a job per guest) rather than sending inline in
  the request — a few hundred guests × Twilio round-trip time will time out a synchronous request.

## Rate limiting / cost control

WhatsApp Business API messages are billed per-conversation by Meta (via Twilio pass-through pricing),
unlike the manual `wa.me` link which is free. Recommend:
- Route-level `throttle` middleware on the send endpoint, same as `throttle:invitation-media` elsewhere.
- The `hourly_cap_per_event` config above as a second, event-scoped guard.
- Decide whether this counts against anything (event credits, a tier gate) or is unmetered — the
  existing SMS feature (`sendSmsUpdate`) doesn't appear to be tier-gated either, so consistency
  suggests leaving WhatsApp ungated too, but confirm this is acceptable given it has a real per-send
  cost unlike email.

## Delivery status (phase 2, not required for launch)

Twilio can POST delivery/read status callbacks to a `statusCallback` URL per message. A follow-up
phase could add `POST /webhooks/twilio/whatsapp-status` (public, signature-verified via Twilio's
`X-Twilio-Signature` header) that updates the matching `NotificationLog` row's `status`/`response`
from `sent` to `delivered`/`read`/`failed`. Not needed for the initial "click to send" feature —
`sendTemplate()`'s immediate Twilio API response (`queued`/`failed`) is enough to show the host
whether the send was accepted.

## Testing

- Unit test `TwilioWhatsAppService` against a faked HTTP client (Twilio SDK supports injecting a
  custom `Client` — don't hit the real API in tests).
- Feature test binding `WhatsAppService` to a fake in the container (same pattern any existing
  `SmsService`/`PushNotificationService` fake test uses, if one exists — check
  `tests/Unit/Notifications/` and mirror it) to assert `CommunicationService::sendWhatsAppInvitation()`
  writes the right `NotificationLog` row and calls the service with the right content variables.
- Feature test for the controller/route: authorization (only the event owner can send), the
  "no phone" and "no invitation_token" no-op branches, and the flash message.

## Sequencing

1. Rotate the Twilio API key/secret; get the Account SID and a WhatsApp sender (start with the
   Twilio Sandbox for dev — no Meta approval needed for sandbox testing, but recipients must first
   join the sandbox by messaging a join code, which is fine for internal testing but not for real
   guests).
2. Draft and submit the invitation Content Template for Meta approval (this has the longest lead
   time — start it in parallel with everything else).
3. Build `WhatsAppService`/`NullWhatsAppService`/`TwilioWhatsAppService`, config, and
   `CommunicationService::sendWhatsAppInvitation()`, tested against the sandbox template.
4. Wire up the single-guest send route + UI.
5. Once approved for production, get a real WhatsApp Business sender live, flip
   `COMM_WHATSAPP_ENABLED=true` in production `.env`, swap `TWILIO_WHATSAPP_FROM` /
   `TWILIO_INVITATION_CONTENT_SID` to the production values.
6. Bulk-send action + hourly cap.
7. (Optional) delivery-status webhook.
