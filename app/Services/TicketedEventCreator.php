<?php

namespace App\Services;

use App\Enums\CommissionMode;
use App\Enums\EventProductKind;
use App\Enums\TicketingStatus;
use App\Models\Event;
use Illuminate\Validation\ValidationException;

/**
 * Shared insert for ticketed drafts — host self-serve create and admin
 * white-glove create both land here so defaults cannot drift.
 */
class TicketedEventCreator
{
    public function __construct(
        private EventSlugService $slugService,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public function create(int $userId, array $validated): Event
    {
        unset(
            $validated['preferred_invitation_template_id'],
            $validated['cover_image'],
            $validated['rsvp_deadline'],
            $validated['guest_limit'],
            $validated['allow_plus_one'],
            $validated['show_guest_list'],
            $validated['user_id'],
            $validated['product_kind'],
        );

        $validated['user_id'] = $userId;
        $validated['product_kind'] = EventProductKind::Ticketed;
        $validated['is_published'] = false;
        $validated['ticketing_status'] = TicketingStatus::Draft;
        $validated['commission_mode'] = CommissionMode::Absorb;
        $validated['is_public'] = true;

        $customSlug = $validated['slug'] ?? null;
        unset($validated['slug']);

        try {
            $event = new Event($validated);
            $this->slugService->apply(is_string($customSlug) ? $customSlug : null, $event);
            $event->save();
            // Only bites when no custom slug was given above — that path already
            // checked event_slug_redirects. The auto-generated (from name) path
            // goes through Sluggable, which has no idea that table exists.
            $this->slugService->resolveAutoSlugCollision($event);
        } catch (\Throwable $e) {
            // A concurrent request can win the same custom slug between apply()'s
            // check and this save() — the unique index still stops the duplicate
            // row, but as a raw QueryException. Surface it the same way apply()
            // does when it catches the collision itself.
            if (EventSlugService::isSlugUniqueViolation($e)) {
                throw ValidationException::withMessages([
                    'slug' => 'That custom URL is already taken.',
                ]);
            }

            throw $e;
        }

        return $event;
    }
}
