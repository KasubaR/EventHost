<?php

namespace App\Http\Controllers;

use App\Enums\CommissionMode;
use App\Exceptions\TicketingActivationException;
use App\Http\Requests\UpdateEventTicketingRequest;
use App\Models\Event;
use App\Services\TicketingActivationService;
use Illuminate\Http\RedirectResponse;

class EventTicketingController extends Controller
{
    public function update(UpdateEventTicketingRequest $request, Event $event): RedirectResponse
    {
        $this->authorize('update', $event);
        abort_unless($event->isTicketed(), 404);

        if (! $event->canEditCommissionMode()) {
            return redirect()
                ->route('events.ticket-types.index', $event)
                ->withErrors([
                    'commission_mode' => 'Commission settings are locked after EventHost approves ticket sales.',
                ]);
        }

        $event->forceFill([
            'commission_mode' => CommissionMode::from($request->validated('commission_mode')),
        ])->save();

        return redirect()
            ->route('events.ticket-types.index', $event)
            ->with('status', 'ticketing-settings-updated');
    }

    public function submit(Event $event, TicketingActivationService $activation): RedirectResponse
    {
        $this->authorize('update', $event);
        abort_unless($event->isTicketed(), 404);

        try {
            $activation->submit($event);
        } catch (TicketingActivationException $e) {
            // Validation failure (no active ticket type) — send them back to
            // where they can fix it, not away to My Events.
            return redirect()
                ->route('events.ticket-types.index', $event)
                ->withErrors(['ticketing' => $e->getMessage()]);
        }

        // Success leaves the wizard entirely — My Events, not back to the
        // Tickets page, since there's nothing further to do here until
        // EventHost reviews it.
        return redirect()
            ->route('events.index')
            ->with('status', 'ticketing-submitted');
    }
}
