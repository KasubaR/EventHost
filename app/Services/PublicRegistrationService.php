<?php

namespace App\Services;

use App\Enums\PublicRegistrationStatus;
use App\Exceptions\PublicRegistrationException;
use App\Models\Admin;
use App\Models\Event;
use App\Notifications\PublicRegistrationApprovedNotification;
use App\Notifications\PublicRegistrationRejectedNotification;
use Illuminate\Support\Facades\DB;

/**
 * Admin-approval pipeline for a free-registration public event (public
 * audience + invitation product kind) — plans/public-private-portals.md
 * Phase 4c. Deliberately mirrors TicketingActivationService's
 * submit/approve/reject shape closely: same state machine, same "why" for
 * each guard, same "notify outside the transaction, once committed" split.
 * The one real difference is approve() does not publish the event — unlike
 * ticketed approval (which goes straight live because money only ever moves
 * later, as commission on sales), here the admin is setting a one-off price
 * the host still has to pay, so is_published stays false until that payment
 * completes (PaymentCompletionService, Step 2).
 */
class PublicRegistrationService
{
    public function submit(Event $event): void
    {
        if (! $event->isFreeRegistration()) {
            throw new PublicRegistrationException('Only a free-registration public event can request review.');
        }

        if (! $event->canSubmitPublicRegistration()) {
            throw new PublicRegistrationException('This event is already in review or approved.');
        }

        $event->forceFill([
            'public_registration_status' => PublicRegistrationStatus::PendingReview,
            'public_registration_submitted_at' => now(),
            'public_registration_rejection_note' => null,
        ])->save();
    }

    public function approve(Event $event, Admin $admin, float $quoteAmount): void
    {
        if ($quoteAmount <= 0) {
            throw new PublicRegistrationException('The quote amount must be greater than zero.');
        }

        $locked = DB::transaction(function () use ($event, $admin, $quoteAmount): Event {
            $locked = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isFreeRegistration()) {
                throw new PublicRegistrationException('Only a free-registration public event can be approved.');
            }

            // Once the quote is paid the event is live and the money has moved —
            // re-approving (and silently changing the price) after that point
            // would rewrite settled financial data, so it's refused outright,
            // unlike Draft/PendingReview/Rejected/unpaid-Approved below, all of
            // which are safe to (re)approve because nothing has been paid yet.
            if ($locked->public_registration_quote_paid_at !== null) {
                throw new PublicRegistrationException('This event has already been paid for and cannot be re-approved.');
            }

            // Includes Approved (re-quoting before payment) alongside the same
            // allowance TicketingActivationService::approve() gives ticketed
            // events: an admin can approve straight from Draft, no host
            // submission required first.
            $activatable = [
                PublicRegistrationStatus::Draft,
                PublicRegistrationStatus::PendingReview,
                PublicRegistrationStatus::Approved,
                PublicRegistrationStatus::Rejected,
            ];

            if (! in_array($locked->public_registration_status, $activatable, true)) {
                throw new PublicRegistrationException('Only draft, declined, awaiting-review, or unpaid-approved events can be approved.');
            }

            $locked->forceFill([
                'public_registration_status' => PublicRegistrationStatus::Approved,
                'public_registration_submitted_at' => $locked->public_registration_submitted_at ?? now(),
                'public_registration_reviewed_at' => now(),
                'public_registration_reviewed_by' => $admin->id,
                'public_registration_rejection_note' => null,
                'public_registration_quote_amount' => $quoteAmount,
                // Re-approving after a prior payment (e.g. the quote changed)
                // would otherwise leave a stale paid-at from the old amount.
                'public_registration_quote_paid_at' => null,
            ])->save();

            return $locked;
        });

        // Outside the transaction, same reasoning as
        // TicketingActivationService::approve(): a queued notification only
        // needs to fire once the approval has actually committed, and there's
        // no reason to hold the row lock while it dispatches.
        $locked->user?->notify(new PublicRegistrationApprovedNotification($locked));
    }

    public function reject(Event $event, Admin $admin, string $note): void
    {
        $locked = DB::transaction(function () use ($event, $admin, $note): Event {
            $locked = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            if ($locked->public_registration_status !== PublicRegistrationStatus::PendingReview) {
                throw new PublicRegistrationException('Only events awaiting review can be declined.');
            }

            $locked->forceFill([
                'public_registration_status' => PublicRegistrationStatus::Rejected,
                'public_registration_reviewed_at' => now(),
                'public_registration_reviewed_by' => $admin->id,
                'public_registration_rejection_note' => $note,
            ])->save();

            return $locked;
        });

        $locked->user?->notify(new PublicRegistrationRejectedNotification($locked, $note));
    }
}
