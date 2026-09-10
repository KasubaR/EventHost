<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateEventContributionRequest;
use App\Models\Event;
use App\Support\AdminActivity;
use Illuminate\Http\RedirectResponse;

/**
 * Admin-only enable/amount toggle for an event's contribution feature — kept
 * separate from Admin\EventController (already sizable with publish/pause/
 * cancel), same split as TicketRevenueController vs TicketingController.
 * See plans/contributions.md.
 */
class EventContributionController extends Controller
{
    public function update(UpdateEventContributionRequest $request, Event $event): RedirectResponse
    {
        abort_unless($event->isInvitation(), 404);

        $data = $request->validated();
        $enabled = (bool) $data['contribution_enabled'];
        $amount = $data['contribution_amount'] ?? null;

        $event->contribution_enabled = $enabled;
        // Disabling without a new amount keeps the old one stored, so
        // re-enabling later doesn't force the admin to retype it.
        if ($amount !== null) {
            $event->contribution_amount = $amount;
        }
        $event->save();

        AdminActivity::log('Admin updated event contribution settings', [
            'event_id' => $event->id,
            'contribution_enabled' => $enabled,
            'contribution_amount' => $event->contribution_amount,
        ]);

        return redirect()->route('admin.events.show', $event)->with('status', 'contribution-updated');
    }
}
