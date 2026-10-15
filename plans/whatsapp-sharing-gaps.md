# WhatsApp sharing gaps

Status: **built** (Phase 1 and Phase 2). Tests: `WhatsAppShareLinkTest`, `WhatsAppAutomatedSendTest`,
`tests/Unit/ShortTextTest`.

There are two WhatsApp paths and they are kept apart on purpose:

| | Share link (free) | Automated send (paid) |
|---|---|---|
| What it is | A `wa.me` link the host opens; WhatsApp opens with the invitation typed | Twilio sends an approved template from the platform's number |
| Who presses send | The host, inside WhatsApp | The server |
| Cost | Nothing | Per message (Meta + Twilio) |
| Plan | Every plan | Pro and above, `COMM_WHATSAPP_ENABLED` |
| Numbers | Any number `GuestPhone::whatsAppDigits()` can dial (Zambian mobile or international) | Zambian mobiles only (one country's template and price) |
| What the app can know | Only that the host opened the chat | Accepted, then delivered / read / failed via the status callback |
| Bulk | The share queue, one chat at a time | Not built (decision) |

## The checklist, and where each case stands

| Case | Share link | Automated send |
|---|---|---|
| No phone | Item disabled with "No phone number…"; "Copy invitation message" offered instead; the queue lists them separately | Refused (`invalid_phone`) |
| Badly formatted | "This number cannot be opened in WhatsApp. Edit the guest" | Refused with the same flash |
| `+260` / `0` prefix / 9 bare digits / `00260` / `(0)` | All dial (`GuestPhone`) | All send (`ZambianPhone` now delegates to `GuestPhone`) |
| Landline | "Landline: WhatsApp cannot reach it." | Refused |
| International | Dials | Refused; tooltip says "Use Share on WhatsApp instead" |
| WhatsApp not installed / desktop | `wa.me` falls back to WhatsApp Web or the install page; "Copy invitation message" covers SMS, email, anything | Not relevant: the guest's phone receives it |
| Special characters, emoji, `&`, `#`, `+`, newlines | `rawurlencode`, round-trip tested. Links go to `api.whatsapp.com/send`, not `wa.me`: wa.me's redirect to that address turns 4-byte characters (🎉, flags) into "�" (seen on WhatsApp Desktop, 2026-10-15) | Template variables are JSON; `ShortText` cuts on whole grapheme clusters |
| Message too long | ~500 characters at most (255 name + 191 guest + link), far under any `wa.me` limit | Variables capped at 100 (`ShortText::WHATSAPP`) |
| Wrong number | Invisible to the app (the host pressed send in someone else's chat) | Status callback: 63003 un-marks the guest and the list says "This number isn't on WhatsApp" |
| Host sends twice | "Share again on WhatsApp" with the date; harmless and free | Refused for 24 hours (`already_sent`) unless the host presses "Send again"; a double click inside 2 minutes is always refused |
| Host sends hundreds | Share queue: one chat per click, progress count; nothing is marked sent until opened | Route throttle 20/min per user, hourly cap per event counted per message type; no bulk send |

## Phase 1: share link

1. **Opening is recorded.** `GET events/{event}/guests/{guest}/whatsapp-share` (`GuestWhatsAppShareController::open`, owner and
   managers via `GuestPolicy::update`) marks the guest sent, writes a `notification_logs` row (`channel = whatsapp_link`,
   `type = guest_invitation_whatsapp_link`, `status = sent`, `meta.user_id`) and redirects to `wa.me`. A GET opened in a new tab,
   so it works without JavaScript and needs no inline handler. The guest list labels it "Share again on WhatsApp · opened {date}"
   once opened
2. **Bulk share is a queue.** The bulk action no longer marks anyone sent. It stores the selected ids in the session
   (`WhatsAppShareService::QUEUE_SESSION_KEY.{event}`) and redirects to `GET events/{event}/guests/whatsapp-queue`, which lists each
   reachable guest with "Open WhatsApp" (the item-1 route), an Opened/Not opened pill, "N of M chats opened" (since the queue
   started), and the guests it cannot reach with the reason. `public/js/whatsapp-share-queue.js` updates the row on click; a
   Refresh link picks up chats opened elsewhere. The API's `prepare_whatsapp_share` returns `share_links: [{guest_id, name, url}]`
   and `skipped: [{guest_id, name, reason}]`, marks nobody sent (`affected_count` is the number of links), and 422s `unpublished`
3. **Disabled where it leads nowhere.** Unpublished ("Publish the event first…") and RSVP closed (`rsvpClosedReason()`) disable the
   row item with the reason as its tooltip, and the route refuses with an error flash. `.evt-more-item--disabled[title]` takes pointer
   events again so the tooltip shows
4. **Copy invitation message** sits next to "Copy link" and copies `WhatsAppInviteLink::invitationMessage()` through the existing copy
   script

`WhatsAppShareService` holds the reasons, message, URL, the record and the "last opened" lookup; web, queue and API all read it.

## Phase 2: automated send

5. **One normaliser.** `ZambianPhone::isValid()` / `toE164()` read through `GuestPhone::whatsAppDigits()` and accept `260` + 7x/9x only.
   `isInternationalForShare()` drives the tooltip
6. **No second bill by accident.** `CommunicationService::sendWhatsAppInvitation($event, $guest, $resend)` locks the event row, then
   refuses (`already_sent`) when `lastWhatsAppInvitation()` finds a sent or pending invitation in the last
   `WHATSAPP_INVITATION_RESEND_HOURS` (24) that Twilio has not reported undelivered. `$resend` overrides it, except for a pending row
   younger than `WHATSAPP_INVITATION_DOUBLE_SUBMIT_MINUTES` (2). The pending row is written inside the lock and the Twilio call happens
   after the commit, so two clicks cannot both pass. Web: `whatsapp_already_sent` flash with the time and a "Send again" form
   (`resend=1`). API: 409 `{outcome, error: already_sent, message, sent_at, in_flight}`, optional body `resend`
7. **Delivery is tracked.** Every Twilio send passes `statusCallback` (`POST /webhooks/twilio/whatsapp/status`,
   `TwilioWhatsAppStatusController`, signature-checked, only while `TWILIO_AUTH_TOKEN` is set; `TWILIO_STATUS_CALLBACK_URL` overrides the
   host). `WhatsAppDeliveryStatusService::record()` stores `notification_logs.delivery_status` / `error_code`, never moving a row
   backwards. A failed or undelivered invitation un-marks the guest only when `invitation_sent_at` equals the log's `sent_at` (the send
   writes both from one instant). The guest list shows "WhatsApp not delivered: {reason}" (`NotificationLog::undeliveredReason()`)
8. **Separate caps.** The hourly cap counts one message type at a time (invitations include pending rows), so an invitation burst
   never rate-limits RSVP confirmations or reminders
9. **Whole emoji.** `ShortText::limit()` cuts on PCRE `\X` grapheme clusters (no `intl` dependency), so flags, skin tones and family
   emoji are kept or dropped whole

## Not covered

- Bulk automated sending, a per-plan message allowance, and Meta's messaging tier (250 business-initiated conversations a day before
  verification). Future work in [whatsapp-invitations.md](whatsapp-invitations.md)
- Whether the host actually pressed send after opening a share link; "opened" is the strongest signal there is
- Automated sending to international numbers
- The API share links are plain `wa.me` URLs, so opening one from the app is not recorded; the app can follow up with `mark_sent`
