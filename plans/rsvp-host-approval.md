# RSVP Host Approval

Status: **built** (2026-09-28). Decisions below were confirmed in chat before writing this file:
opt-in per event, free for every tier, and a pending-review page for the guest with nothing sent
until the host decides. All nine phases in the build order at the bottom are complete, including
tests (`tests/Feature/RsvpHostApprovalTest.php`).

## What this is

Today, when a guest accepts an RSVP, [RsvpController::dispatchRsvpNotifications()](app/Http/Controllers/RsvpController.php:512)
calls straight through to [CommunicationService::dispatchRsvpNotifications()](app/Services/CommunicationService.php:123),
which **synchronously** emails the confirmation + pass, sends the WhatsApp pass, and pings the host
— all before the guest's browser even redirects to the thank-you page. There's no gate: an accepted
RSVP always gets its entry pass.

This adds an optional per-event switch, `require_rsvp_approval`. When it's on, an **Accepted** RSVP
lands in a pending state instead: no confirmation email, no WhatsApp pass, no entry pass/QR, and
the guest sees a "your RSVP is awaiting the host's confirmation" page. The host reviews it from the
guest list and approves or rejects; approving fires the same confirmation + pass flow that runs
today (just later), rejecting tells the guest why and grants no pass. Decline/Maybe responses are
never gated — there's no pass at stake for those, so they behave exactly as they do today.

## Open questions — resolved

1. **Opt-in per event**, off by default. Existing events see zero behavior change unless the host
   turns it on. A host with premium event tools and one with none can both use it — see tier
   question below.
2. **No tier gate.** Every host can require RSVP approval, unlike check-in/tables/photo wall
   (`canUsePremiumEventTools()`). This is a moderation/control feature, not a "premium tool."
3. **Guest sees a pending-review page, nothing sent yet.** No lightweight "we got it" email either —
   the existing `RsvpConfirmationNotification` (and its pass attachments) is the thing that fires
   once, on approval, not twice (once bare, once with the pass).

## Scope

Applies to `isInvitation()` events only (both private and free-registration public audience —
ticketed events have no `Guest`/`Rsvp` rows at all, so the toggle is meaningless there and
`UpdateEventRequest`/`StoreEventRequest` should ignore it for `product_kind = ticketed`, the same
way other invitation-only fields already get silently dropped by `TicketedEventCreator`).

## Data model

**`events`**: one new column, `require_rsvp_approval` (boolean, default `false`) — same shape as
`allow_plus_one`.

**`rsvps`**: four new columns, mirroring the `ticketing_status` / `ticketing_reviewed_at` /
`ticketing_reviewed_by` / `ticketing_rejection_note` shape on `events` ([TicketingActivationService](app/Services/TicketingActivationService.php)):

- `host_approval_status` — string, backed by a new `App\Enums\RsvpApprovalStatus` enum, default
  `not_required`
- `host_reviewed_at` — nullable timestamp
- `host_reviewed_by` — nullable, `foreignId` to `users` (the **host**, not an `Admin` — this is a
  host reviewing their own guest, unlike ticketing which is EventHost staff reviewing a host)
- `host_rejection_note` — nullable text

Why on `Rsvp`, not `Guest`: RSVP status already lives on `Rsvp` ([Rsvp.php](app/Models/Rsvp.php)),
approval is a property of a specific response, and `RsvpSubmissionService::submit()` already
`updateOrCreate`s this exact row inside a locked transaction — the natural place to also decide the
approval state.

```php
enum RsvpApprovalStatus: string
{
    case NotRequired = 'not_required';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
```
(`label()`/`tone()`/`icon()` methods matching `TicketingStatus`'s, for the guest-list pill.)

## Where the state gets set — `RsvpSubmissionService::submit()`

[RsvpSubmissionService::submit()](app/Services/RsvpSubmissionService.php:18) already loads `$existing`
(the previous `Rsvp` row, if any) before writing `$rsvpData`. Add:

```php
$approvalStatus = RsvpApprovalStatus::NotRequired;

if ($status === RsvpStatus::Accepted && $locked->require_rsvp_approval) {
    $wasAccepted = $existing !== null && $existing->status === RsvpStatus::Accepted;
    $approvalStatus = $wasAccepted
        ? $existing->host_approval_status  // editing attendee_count/message on an already-decided RSVP doesn't reopen review
        : RsvpApprovalStatus::Pending;      // fresh accept, or accept after a prior decline/maybe
}

$rsvpData['host_approval_status'] = $approvalStatus;
```

Rule stated explicitly: **approval only resets to Pending on a transition into Accepted from
something else.** A guest tweaking their attendee count while still Accepted doesn't reopen a
decision the host already made (approved or rejected) — the host would have to explicitly re-review
after a real status change, not a minor edit. This also means toggling `require_rsvp_approval` on
for an event that already has Accepted RSVPs does **not** retroactively demote them to Pending —
only RSVP submissions from that point on are affected.

## Where sending is gated — `CommunicationService::dispatchRsvpNotifications()`

[CommunicationService::dispatchRsvpNotifications()](app/Services/CommunicationService.php:123) is the
single choke point (called from both `storeByToken` and `storeOpen`, and not called at all on the
WhatsApp-inbound quick-reply path, which has its own send). Branch at the top:

```php
if ($rsvp->host_approval_status === RsvpApprovalStatus::Pending) {
    $event->loadMissing('user');
    if ($event->user !== null) {
        $this->notifyHostRsvpAwaitingApproval($event->user, $event, $guest, $rsvp);
    }
    return; // no guest confirmation, no WhatsApp pass, until the host decides
}
// ...existing sendRsvpConfirmation / sendWhatsAppRsvpConfirmation / notifyHostNewRsvp, unchanged
```

`notifyHostRsvpAwaitingApproval()` is a new method, same shape as the existing
`notifyHostNewRsvp()` ([CommunicationService.php:88](app/Services/CommunicationService.php:88)) but
sending a distinct `RsvpAwaitingApprovalNotification` with an "Approve / Reject" CTA instead of the
plain FYI — reuse `notifyHostNewRsvp`'s preference keys (`email_rsvp_updates`/`push_rsvp_updates`)
rather than inventing new ones. Declined/Maybe RSVPs, and Accepted RSVPs on events with the toggle
off, fall through to the existing branch untouched.

## Approval action — new `RsvpApprovalService`

Mirrors [TicketingActivationService::approve()/reject()](app/Services/TicketingActivationService.php:37)
exactly: lock the `Rsvp` row, guard the current state, `forceFill` + `save()`, notify outside the
transaction.

```php
class RsvpApprovalService
{
    public function approve(Rsvp $rsvp, User $host): void
    {
        $locked = DB::transaction(function () use ($rsvp, $host): Rsvp {
            $locked = Rsvp::query()->whereKey($rsvp->id)->lockForUpdate()->firstOrFail();

            if ($locked->host_approval_status !== RsvpApprovalStatus::Pending) {
                throw new RsvpApprovalException('This RSVP is not awaiting approval.');
            }

            $locked->forceFill([
                'host_approval_status' => RsvpApprovalStatus::Approved,
                'host_reviewed_at' => now(),
                'host_reviewed_by' => $host->id,
                'host_rejection_note' => null,
            ])->save();

            return $locked;
        });

        $locked->loadMissing(['guest', 'event']);
        app(CommunicationService::class)->dispatchApprovedRsvpNotifications($locked->event, $locked->guest, $locked);
    }

    public function reject(Rsvp $rsvp, User $host, string $note): void { /* same shape, RsvpRejectedNotification */ }
}
```

`dispatchApprovedRsvpNotifications()` is a thin new `CommunicationService` method that calls the
same `sendRsvpConfirmation()` + `sendWhatsAppRsvpConfirmation()` used today — **not** duplicated,
just invoked later. `reject()` sends a new `RsvpRejectedNotification` (guest-facing, carries the
host's note) and nothing else — no pass, no WhatsApp.

## Controller + routes

New actions on `GuestController` (or a small dedicated `RsvpApprovalController` — either is fine,
`GuestController` already owns `markInvitationSent`/`sendWhatsAppInvitation`, which are the closest
existing precedent), same `$this->authorize('update', $guest)` check every other guest action here
uses ([GuestController.php:254](app/Http/Controllers/GuestController.php:254)):

```
PATCH /events/{event}/guests/{guest}/rsvp/approve   → events.guests.rsvp.approve
PATCH /events/{event}/guests/{guest}/rsvp/reject     → events.guests.rsvp.reject   (body: note)
```

Both `abort_unless($event->isInvitation(), 404)` and `abort_unless($guest->rsvp?->host_approval_status === RsvpApprovalStatus::Pending, 404)`
guard against acting on a guest with nothing to review.

## Guest-facing UI

`RsvpController::confirmationViewData()` ([RsvpController.php:482](app/Http/Controllers/RsvpController.php:482))
already computes `showEntryPass` from `hasEntryPassFor()` — the single choke point every pass
surface (`thank-you`, `token-show`, PDF, PNG, QR route) reads. Extend
[Guest::hasEntryPassFor()](app/Models/Guest.php:129) with one more condition:

```php
public function hasEntryPassFor(Rsvp $rsvp, Event $event): bool
{
    return $this->invitation_token !== null
        && $rsvp->status === RsvpStatus::Accepted
        && $rsvp->host_approval_status !== RsvpApprovalStatus::Pending
        && $rsvp->host_approval_status !== RsvpApprovalStatus::Rejected
        && $event->ownerHasPremiumEventTools();
}
```

This alone hides the pass panel everywhere it's already checked, including the check-in scan flow.
It doesn't explain *why* the pass is missing, so `rsvp/partials/entry-pass.blade.php` itself takes an
optional `$rsvp` prop (already in scope at both call sites — `thank-you.blade.php` has it directly,
`events/invitations/sections/rsvp.blade.php` has it as `$existingRsvp`) and branches on
`$rsvp->host_approval_status` to show the pending/rejected messaging in the same partial that would
otherwise render nothing — one choke point instead of a new view-data key threaded through every
caller.

## Host-facing UI

The guest list ([GuestController::index](app/Http/Controllers/GuestController.php:51)) gets a status
pill per guest (reusing `RsvpApprovalStatus::tone()`/`icon()`, same visual language as the ticketing
pill) and, for `Pending` rows, inline Approve / Reject buttons — reject opens a small modal for the
required note, same UX as the ticketing/public-registration admin reject flows. A "Pending approval"
entry in the existing response filter (`?response=`) lets a host jump straight to what needs action.

## Reminders

[EventReminderBuckets](app/Support/EventReminderBuckets.php) / `Event::scopeDueForGuestEventReminder()`
should not remind a guest whose RSVP is still Pending or was Rejected — add
`whereHas('rsvp', fn ($q) => $q->whereNotIn('host_approval_status', [Pending, Rejected]))` (or
equivalent) alongside the existing Accepted-status filter, so an unapproved/rejected guest doesn't
get "see you tomorrow" reminders for an event they have no pass for.

## Test coverage to add

- Toggle off (default): submitting an Accepted RSVP behaves exactly as today — no regression.
- Toggle on: Accepted RSVP → `host_approval_status = Pending`, no `RsvpConfirmationNotification`,
  no WhatsApp send, `notifyHostRsvpAwaitingApproval` fires instead, guest sees the pending page with
  no pass/QR.
- Decline/Maybe on a toggle-on event: unaffected, confirmation sends immediately, no approval state.
- Approve: guest now gets the confirmation + pass (same content as the toggle-off path), check-in
  QR becomes valid, guest list shows Approved.
- Reject: guest gets `RsvpRejectedNotification` with the note, no pass ever, re-`approve`/`reject`
  on an already-decided RSVP is refused.
- Editing attendee count on an already-Approved RSVP does not reset it to Pending.
- Turning the toggle on after guests already accepted doesn't retroactively touch their state.
- Authorization: a non-owner (or a host on someone else's event) cannot approve/reject.
- Reminder command skips Pending/Rejected guests on a toggle-on event.

## Phased build order

1. Migrations (`events.require_rsvp_approval`, four `rsvps` columns) + `RsvpApprovalStatus` enum +
   model casts/fillable.
2. `RsvpSubmissionService` approval-state logic (behavior-neutral while the toggle is off everywhere).
3. `CommunicationService` gating + `RsvpAwaitingApprovalNotification` / `RsvpRejectedNotification` +
   `dispatchApprovedRsvpNotifications()`.
4. `RsvpApprovalService` + routes + controller actions + policy checks.
5. `Guest::hasEntryPassFor()` extension + guest-facing pending/rejected messaging on thank-you/token-show.
6. Guest list pill + Approve/Reject UI + reject-note modal.
7. Event create/edit toggle (`StoreEventRequest`/`UpdateEventRequest` validation + form field, next
   to `allow_plus_one`).
8. Reminder scope exclusion.
9. Tests.
