<?php

namespace App\Http\Controllers;

use App\Enums\TicketStatus;
use App\Exceptions\TicketCheckInException;
use App\Models\Event;
use App\Models\Ticket;
use App\Notifications\TicketOrderConfirmationNotification;
use App\Services\TicketCheckInService;
use App\Services\TicketPdfService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            ->with(['ticketType', 'order', 'checkedInBy'])
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

    /**
     * Host CSV of every issued ticket — same contact-list pattern as
     * GuestController::export(). Ticket Number is the EH-### display ordinal
     * (by id), not a stored column.
     */
    public function export(Event $event): StreamedResponse
    {
        $this->authorizeTicketed($event);

        $ticketsQuery = Ticket::query()
            ->where('event_id', $event->id)
            ->with(['ticketType', 'order', 'checkedInBy'])
            ->orderBy('id');

        $filename = 'tickets-'.str($event->name)->slug().'.csv';

        return response()->streamDownload(function () use ($ticketsQuery) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Ticket Number',
                'Name',
                'Email',
                'Phone',
                'Ticket Type',
                'Order Reference',
                'Status',
                'Checked In',
                'Checked In By',
            ]);

            $ordinal = 0;

            $ticketsQuery->chunk(200, function ($chunk) use ($handle, &$ordinal) {
                foreach ($chunk as $ticket) {
                    $ordinal++;
                    $order = $ticket->order;

                    $checkedIn = in_array($ticket->status, [TicketStatus::Refunded, TicketStatus::Cancelled], true)
                        ? ''
                        : ($ticket->isCheckedIn() ? 'Yes' : 'No');

                    fputcsv($handle, [
                        'EH-'.str_pad((string) $ordinal, 3, '0', STR_PAD_LEFT),
                        $ticket->attendee_name ?: ($order?->buyer_name ?? ''),
                        $ticket->attendee_email ?: ($order?->buyer_email ?? ''),
                        $ticket->attendee_phone ?: ($order?->buyer_phone ?? ''),
                        $ticket->ticketType?->name ?? '',
                        $order?->order_reference ?? '',
                        $ticket->status->label(),
                        $checkedIn,
                        // A dashboard scan resolves to the staff member's name;
                        // a staff-link scan to that link's label. Blank only
                        // when nobody has scanned this ticket.
                        $ticket->checkedInByLabel() ?? '',
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
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

    /**
     * Rotate a leaked ticket's public_token: the copies in circulation stop
     * resolving, the buyer gets a fresh QR, and the seat is untouched.
     *
     * The gap this closes is that cancel() was the only way to kill a
     * circulating QR, and cancelling also restocks the seat and voids the
     * buyer's pass — the wrong trade when the buyer did nothing wrong and a
     * screenshot simply got shared.
     */
    public function reissue(Event $event, Ticket $ticket, TicketPdfService $pdfService): RedirectResponse
    {
        $this->authorizeTicketed($event);
        $this->authorizeTicketBelongsToEvent($event, $ticket);

        $previousToken = DB::transaction(function () use ($ticket): ?string {
            /** @var Ticket $locked */
            $locked = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();

            // A used ticket has already been admitted, and a cancelled or
            // refunded one is void — in neither case does a new QR mean
            // anything, so there is nothing worth rotating.
            if ($locked->status !== TicketStatus::Valid) {
                return null;
            }

            $previous = $locked->public_token;
            $locked->forceFill(['public_token' => Ticket::generateUniqueToken()])->save();

            return $previous;
        });

        if ($previousToken === null) {
            return back()->withErrors(['ticket' => 'Only a valid ticket can be reissued.']);
        }

        // Both caches are keyed on the token, so the old entries are already
        // unreachable — but the PDF is a file on a disk nobody prunes, and the
        // QR entry holds a week-long TTL. Dropping them keeps a revoked token
        // from lingering as a servable artefact.
        Cache::forget(Ticket::qrCacheKeyForToken($previousToken));
        Storage::disk('local')->delete($pdfService->cachePathForToken($previousToken));

        $ticket->refresh()->loadMissing('order');

        if ($ticket->order !== null) {
            // The confirmation covers the whole order; the other tickets in it
            // keep their tokens and their already-cached PDFs.
            Notification::route('mail', $ticket->order->buyer_email)
                ->notify(new TicketOrderConfirmationNotification($ticket->order));
        }

        return back()->with('status', 'ticket-reissued');
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
