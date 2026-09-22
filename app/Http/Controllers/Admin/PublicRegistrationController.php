<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\PublicRegistrationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ApprovePublicRegistrationRequest;
use App\Http\Requests\Admin\RejectPublicRegistrationRequest;
use App\Models\Admin;
use App\Models\Event;
use App\Services\PublicRegistrationService;
use App\Support\AdminActivity;
use Illuminate\Http\RedirectResponse;

/**
 * Approve/reject for a free-registration public event's admin review —
 * plans/public-private-portals.md Phase 4c Step 3, the twin of
 * Admin\TicketingController's approve()/reject() (same permission-gated
 * mutation shape), rendered from an inline card on the event show page
 * rather than a dedicated queue page, same posture as
 * Admin\EventContributionController — there's no separate list of
 * free-registration events to browse, just this event's own status.
 */
class PublicRegistrationController extends Controller
{
    public function approve(
        ApprovePublicRegistrationRequest $request,
        Event $event,
        PublicRegistrationService $service
    ): RedirectResponse {
        abort_unless($event->isFreeRegistration(), 404);

        $admin = auth('admin')->user();
        if (! $admin instanceof Admin) {
            abort(403);
        }

        try {
            $service->approve($event, $admin, (float) $request->validated('quote_amount'));
        } catch (PublicRegistrationException $e) {
            return redirect()
                ->route('admin.events.show', $event)
                ->withErrors(['public_registration' => $e->getMessage()]);
        }

        AdminActivity::log('Admin approved public event registration', [
            'event_id' => $event->id,
            'quote_amount' => $event->fresh()->public_registration_quote_amount,
        ]);

        return redirect()
            ->route('admin.events.show', $event)
            ->with('status', 'public-registration-approved');
    }

    public function reject(
        RejectPublicRegistrationRequest $request,
        Event $event,
        PublicRegistrationService $service
    ): RedirectResponse {
        abort_unless($event->isFreeRegistration(), 404);

        $admin = auth('admin')->user();
        if (! $admin instanceof Admin) {
            abort(403);
        }

        try {
            $service->reject($event, $admin, $request->validated('public_registration_rejection_note'));
        } catch (PublicRegistrationException $e) {
            return redirect()
                ->route('admin.events.show', $event)
                ->withErrors(['public_registration' => $e->getMessage()]);
        }

        AdminActivity::log('Admin declined public event registration', [
            'event_id' => $event->id,
        ]);

        return redirect()
            ->route('admin.events.show', $event)
            ->with('status', 'public-registration-rejected');
    }
}
