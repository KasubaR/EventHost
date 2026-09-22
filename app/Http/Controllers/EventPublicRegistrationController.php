<?php

namespace App\Http\Controllers;

use App\Exceptions\PublicRegistrationException;
use App\Models\Event;
use App\Services\PublicRegistrationService;
use Illuminate\Http\RedirectResponse;

/**
 * Host-facing submit-for-review action for a free-registration public event
 * — the mirror of EventTicketingController::submit(). Completes the loop
 * plans/public-private-portals.md Phase 4c Step 3 needed: without this,
 * PublicRegistrationService::submit() had no route calling it, so an event
 * could never reach PendingReview through the app, and the admin approval
 * card's Decline action (PendingReview-only) would have had nothing to
 * ever act on.
 */
class EventPublicRegistrationController extends Controller
{
    public function submit(Event $event, PublicRegistrationService $service): RedirectResponse
    {
        // 'publish', not 'update' — same billing-adjacent reasoning
        // EventTicketingController::submit() documents for its own identical
        // call: submitting starts the pipeline that ends in a payable quote.
        $this->authorize('publish', $event);
        abort_unless($event->isFreeRegistration(), 404);

        try {
            $service->submit($event);
        } catch (PublicRegistrationException $e) {
            return redirect()
                ->route('events.edit', $event)
                ->withErrors(['public_registration' => $e->getMessage()]);
        }

        return redirect()
            ->route('events.edit', $event)
            ->with('status', 'public-registration-submitted');
    }
}
