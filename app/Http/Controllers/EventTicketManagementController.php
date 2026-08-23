<?php

namespace App\Http\Controllers;

use App\Enums\TicketStatus;
use App\Exceptions\TicketCheckInException;
use App\Models\Event;
use App\Models\Ticket;
use App\Notifications\TicketOrderConfirmationNotification;
use App\Services\TicketCheckInService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\View\View;

/**
 * The "Ticketing" section's Tickets tab (Phase 16) — every issued ticket for
 * the event with row actions. Deliberately separate from EventTicketTypeController
 * (which manages ticket *types* — name/price/quantity — under the Settings tab)
 * and from TicketCheckInController (the camera scanner, JSON API): this
 * controller's Check-in action is the same TicketCheckInService but a plain
 * redirect-back form, for staff working from the table instead of a phone
 * camera. Two front doors onto one service, same idiom used elsewhere in this
 * codebase (e.g. TicketController::download() and the emailed QR both call
 * QrCodeService::png()).
 */
class EventTicketManagementController extends Controller
{
    public function index(Event $event): View
    {
        $this->authorizeTicketed($event);

        $tickets = Ticket::query()
            ->where('event_id', $event->id)
            ->with(['ticketType', 'order'])
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        // Sequential per-event display label (EH-001, EH-002, …) computed for
        // this page only — there is no stored ticket_number column (the buyer
        // trust model uses public_token, not a guessable sequence; see
        // plans/ticketing.md §5.1). firstItem() offsets the running count so
        // numbering stays continuous across pages.
        $startingOrdinal = $tickets->firstItem() ?? 1;

        return view('events.tickets.manage', [
            'event' => $event,
            'tickets' => $tickets,
            'startingOrdinal' => $startingOrdinal,
        ]);
    }

    public function resend(Event $event, Ticket $ticket): RedirectResponse
    {
        $this->authorizeTicketed($event);
        $this->authorizeTicketBelongsToEvent($event, $ticket);

        $ticket->loadMissing('order');

        if ($ticket->order === null) {
            return back()->withErrors(['ticket' => 'This ticket has no order to resend a confirmation for.']);
        }

        Notification::route('mail', $ticket->order->buyer_email)
            ->notify(new TicketOrderConfirmationNotification($ticket->order));

        return back()->with('status', 'ticket-resent');
    }

    public function cancel(Event $event, Ticket $ticket): RedirectResponse
    {
        $this->authorizeTicketed($event);
        $this->authorizeTicketBelongsToEvent($event, $ticket);

        return DB::transaction(function () use ($ticket): RedirectResponse {
            /** @var Ticket $locked */
            $locked = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== TicketStatus::Valid) {
                return back()->withErrors(['ticket' => 'Only a valid ticket can be cancelled.']);
            }

            // Cancelled tickets stop counting as sold (TicketType::soldQuantity),
            // which restocks the seat. The original sale ledger row is left
            // alone — buyer refunds are off-platform, same as Phase 19.
            $locked->forceFill(['status' => TicketStatus::Cancelled])->save();

            return back()->with('status', 'ticket-cancelled');
        });
    }

    public function confirmCheckIn(Event $event, Ticket $ticket, TicketCheckInService $checkInService): RedirectResponse
    {
        $this->authorizeTicketed($event);
        $this->authorizeTicketBelongsToEvent($event, $ticket);

        if (! $event->ownerHasPremiumEventTools()) {
            return redirect()->route('events.ticket-types.index', $event)->with('status', 'checkin-requires-approval');
        }

        try {
            $result = $checkInService->confirm($ticket, auth()->id());
        } catch (TicketCheckInException $e) {
            return back()->withErrors(['ticket' => $e->getMessage()]);
        }

        return back()->with('status', $result['already_checked_in'] ? 'ticket-already-checked-in' : 'ticket-checked-in');
    }

    private function authorizeTicketed(Event $event): void
    {
        $this->authorize('update', $event);

        abort_unless($event->isTicketed(), 404);
    }

    private function authorizeTicketBelongsToEvent(Event $event, Ticket $ticket): void
    {
        abort_unless($ticket->event_id === $event->id, 404);
    }
}
