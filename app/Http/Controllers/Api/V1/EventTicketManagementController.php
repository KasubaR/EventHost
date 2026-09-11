<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TicketStatus;
use App\Exceptions\TicketCheckInException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TicketManagementResource;
use App\Models\Event;
use App\Models\Ticket;
use App\Notifications\TicketOrderConfirmationNotification;
use App\Services\TicketCheckInService;
use App\Services\TicketPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * JSON sibling of App\Http\Controllers\EventTicketManagementController
 * (Slice D). CSV export is unchanged — a StreamedResponse works over
 * OkHttp the same as a browser, no native reimplementation needed
 * (plans/android-app.md §2, PDF row: same "download, hand to the system" idiom).
 * confirmCheckIn() reuses the same TicketCheckInService as
 * EventTicketCheckInController — a table-row action and the camera scanner are
 * two front doors onto one service, matching the web pair.
 */
class EventTicketManagementController extends Controller
{
    public function index(Event $event): JsonResponse
    {
        $this->authorizeTicketed($event);

        $tickets = Ticket::query()
            ->where('event_id', $event->id)
            ->with(['ticketType', 'order', 'checkedInBy'])
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        return response()->json([
            'tickets' => TicketManagementResource::collection($tickets->items()),
            'starting_ordinal' => $tickets->firstItem() ?? 1,
            'meta' => [
                'current_page' => $tickets->currentPage(),
                'last_page' => $tickets->lastPage(),
                'total' => $tickets->total(),
            ],
        ]);
    }

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
                'Ticket Number', 'Name', 'Email', 'Phone', 'Ticket Type',
                'Order Reference', 'Status', 'Checked In', 'Checked In By',
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
                        $ticket->checkedInByLabel() ?? '',
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function resend(Event $event, Ticket $ticket): JsonResponse
    {
        $this->authorizeTicketed($event);
        $this->authorizeTicketBelongsToEvent($event, $ticket);

        $ticket->loadMissing('order');

        if ($ticket->order === null) {
            return response()->json(['message' => 'This ticket has no order to resend a confirmation for.'], 422);
        }

        Notification::route('mail', $ticket->order->buyer_email)
            ->notify(new TicketOrderConfirmationNotification($ticket->order));

        return response()->json(['message' => 'Confirmation resent.']);
    }

    public function reissue(Event $event, Ticket $ticket, TicketPdfService $pdfService): JsonResponse
    {
        $this->authorizeTicketed($event);
        $this->authorizeTicketBelongsToEvent($event, $ticket);

        $previousToken = DB::transaction(function () use ($ticket): ?string {
            /** @var Ticket $locked */
            $locked = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== TicketStatus::Valid) {
                return null;
            }

            $previous = $locked->public_token;
            $locked->forceFill(['public_token' => Ticket::generateUniqueToken()])->save();

            return $previous;
        });

        if ($previousToken === null) {
            return response()->json(['message' => 'Only a valid ticket can be reissued.'], 422);
        }

        Cache::forget(Ticket::qrCacheKeyForToken($previousToken));
        Storage::disk('local')->delete($pdfService->cachePathForToken($previousToken));

        $ticket->refresh()->loadMissing('order');

        if ($ticket->order !== null) {
            Notification::route('mail', $ticket->order->buyer_email)
                ->notify(new TicketOrderConfirmationNotification($ticket->order));
        }

        return response()->json(['ticket' => new TicketManagementResource($ticket)]);
    }

    public function cancel(Event $event, Ticket $ticket): JsonResponse
    {
        $this->authorizeTicketed($event);
        $this->authorizeTicketBelongsToEvent($event, $ticket);

        return DB::transaction(function () use ($ticket): JsonResponse {
            /** @var Ticket $locked */
            $locked = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== TicketStatus::Valid) {
                return response()->json(['message' => 'Only a valid ticket can be cancelled.'], 422);
            }

            $locked->forceFill(['status' => TicketStatus::Cancelled])->save();

            return response()->json(['ticket' => new TicketManagementResource($locked)]);
        });
    }

    public function confirmCheckIn(Event $event, Ticket $ticket, TicketCheckInService $checkInService): JsonResponse
    {
        $this->authorizeTicketed($event);
        $this->authorizeTicketBelongsToEvent($event, $ticket);

        if (! $event->ownerHasPremiumEventTools()) {
            return response()->json(['message' => 'Ticket sales for this event have not been approved yet.'], 403);
        }

        try {
            $result = $checkInService->confirm($ticket, auth()->id());
        } catch (TicketCheckInException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json($result);
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
