<?php

namespace App\Services;

use App\Enums\TicketingStatus;
use App\Exceptions\TicketingActivationException;
use App\Models\Admin;
use App\Models\Event;
use App\Notifications\TicketingApprovedNotification;
use App\Notifications\TicketingRejectedNotification;
use Illuminate\Support\Facades\DB;

class TicketingActivationService
{
    public function submit(Event $event): void
    {
        if (! $event->isTicketed()) {
            throw new TicketingActivationException('Only ticketed events can request ticket sales.');
        }

        if (! in_array($event->ticketing_status, [TicketingStatus::Draft, TicketingStatus::Rejected], true)) {
            throw new TicketingActivationException('This event is already in review or approved.');
        }

        $activeTypes = $event->ticketTypes()->where('is_active', true)->count();
        if ($activeTypes < 1) {
            throw new TicketingActivationException('Add at least one active ticket type before requesting activation.');
        }

        $event->forceFill([
            'ticketing_status' => TicketingStatus::PendingReview,
            'ticketing_submitted_at' => now(),
            'ticketing_rejection_note' => null,
        ])->save();
    }

    public function approve(Event $event, Admin $admin, ?string $agreedPayoutOn = null): void
    {
        $locked = DB::transaction(function () use ($event, $admin, $agreedPayoutOn): Event {
            $locked = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            // White-glove admin create can activate from draft/rejected without
            // a host submit; the host-submitted path still arrives as pending_review.
            $activatable = [
                TicketingStatus::PendingReview,
                TicketingStatus::Draft,
                TicketingStatus::Rejected,
            ];

            if (! in_array($locked->ticketing_status, $activatable, true)) {
                throw new TicketingActivationException('Only draft, declined, or awaiting-review events can be approved.');
            }

            $activeTypes = $locked->ticketTypes()->where('is_active', true)->count();
            if ($activeTypes < 1) {
                throw new TicketingActivationException('Add at least one active ticket type before approving ticket sales.');
            }

            if (! filled($locked->cover_image)) {
                throw new TicketingActivationException('Add a hero image before approving ticket sales.');
            }

            $locked->forceFill([
                'ticketing_status' => TicketingStatus::Approved,
                'ticketing_submitted_at' => $locked->ticketing_submitted_at ?? now(),
                'ticketing_reviewed_at' => now(),
                'ticketing_reviewed_by' => $admin->id,
                'ticketing_rejection_note' => null,
                'agreed_payout_on' => $agreedPayoutOn,
                'is_published' => true,
                'is_public' => true,
            ])->save();

            return $locked;
        });

        // Outside the transaction: a queued notification only needs to fire
        // once the approval has actually committed, and there's no reason to
        // hold the row lock while it dispatches.
        $locked->user?->notify(new TicketingApprovedNotification($locked));
    }

    public function reject(Event $event, Admin $admin, string $note): void
    {
        $locked = DB::transaction(function () use ($event, $admin, $note): Event {
            $locked = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            if ($locked->ticketing_status !== TicketingStatus::PendingReview) {
                throw new TicketingActivationException('Only events awaiting review can be declined.');
            }

            $locked->forceFill([
                'ticketing_status' => TicketingStatus::Rejected,
                'ticketing_reviewed_at' => now(),
                'ticketing_reviewed_by' => $admin->id,
                'ticketing_rejection_note' => $note,
            ])->save();

            return $locked;
        });

        $locked->user?->notify(new TicketingRejectedNotification($locked, $note));
    }
}
