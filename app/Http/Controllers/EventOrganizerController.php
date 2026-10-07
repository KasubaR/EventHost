<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateEventOrganizerRequest;
use App\Models\Event;
use App\Services\ActingAsService;
use App\Support\ZambianBanks;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Wizard step 4 for a ticketed event ("Organizer Details"), and the same page later
 * from the ticketing settings: who guests can contact, whether that shows on the public
 * ticket page, and the payout account.
 */
class EventOrganizerController extends Controller
{
    public function edit(Event $event, ActingAsService $acting): View|RedirectResponse
    {
        $this->authorizeTicketed($event);

        // Step 4 comes after Tickets: nothing to sell yet means go back to step 3.
        if ($event->canSubmitTicketing() && ! $event->ticketTypes()->where('is_active', true)->exists()) {
            return redirect()
                ->route('public-events.ticket-types.index', $event)
                ->withErrors(['ticket_type' => 'Add at least one ticket type before you add the organizer details.']);
        }

        return view('events.tickets.organizer', [
            'event' => $event,
            'banks' => ZambianBanks::names(),
            'setupMode' => $event->canSubmitTicketing(),
            'payoutLockedForAdmin' => $acting->isActive(),
        ]);
    }

    public function update(UpdateEventOrganizerRequest $request, Event $event): RedirectResponse
    {
        $this->authorizeTicketed($event);

        $data = $request->validated();
        $data['organizer_details_public'] = $request->boolean('organizer_details_public');

        $event->fill($data)->save();

        if ($event->canSubmitTicketing()) {
            // Still setting up: on to step 5, unless an admin acting for the client could
            // not fill in the payout account, which only the client can do.
            if (! $event->hasPayoutAccount()) {
                return redirect()
                    ->route('public-events.organizer.edit', $event)
                    ->with('status', 'organizer-saved-payout-missing');
            }

            return redirect()->route('events.edit', $event)->with('status', 'organizer-saved');
        }

        return redirect()
            ->route('public-events.organizer.edit', $event)
            ->with('status', 'organizer-saved');
    }

    private function authorizeTicketed(Event $event): void
    {
        $this->authorize('update', $event);

        abort_unless($event->isTicketed(), 404);
    }
}
