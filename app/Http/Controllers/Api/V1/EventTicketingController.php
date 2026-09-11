<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CommissionMode;
use App\Exceptions\TicketingActivationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateEventTicketingRequest;
use App\Http\Resources\Api\V1\EventResource;
use App\Models\Event;
use App\Services\TicketingActivationService;
use Illuminate\Http\JsonResponse;

/**
 * JSON sibling of App\Http\Controllers\EventTicketingController (Slice D).
 * Reuses UpdateEventTicketingRequest verbatim and TicketingActivationService
 * unchanged.
 */
class EventTicketingController extends Controller
{
    public function update(UpdateEventTicketingRequest $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);
        abort_unless($event->isTicketed(), 404);

        if (! $event->canEditCommissionMode()) {
            return response()->json([
                'message' => 'Commission settings are locked after EventHost approves ticket sales.',
                'errors' => ['commission_mode' => ['Commission settings are locked after EventHost approves ticket sales.']],
            ], 422);
        }

        $event->forceFill([
            'commission_mode' => CommissionMode::from($request->validated('commission_mode')),
        ])->save();

        return response()->json(['event' => new EventResource($event)]);
    }

    public function submit(Event $event, TicketingActivationService $activation): JsonResponse
    {
        // 'publish', not 'update' — same billing-adjacent reasoning as the web
        // controller: owner-only even for a Manager who can otherwise touch
        // everything else about the event.
        $this->authorize('publish', $event);
        abort_unless($event->isTicketed(), 404);

        try {
            $activation->submit($event);
        } catch (TicketingActivationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['ticketing' => [$e->getMessage()]],
            ], 422);
        }

        return response()->json(['event' => new EventResource($event->refresh())]);
    }
}
