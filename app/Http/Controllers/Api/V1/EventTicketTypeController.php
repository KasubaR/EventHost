<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTicketTypeRequest;
use App\Http\Requests\UpdateTicketTypeRequest;
use App\Http\Resources\Api\V1\TicketTypeResource;
use App\Models\Event;
use App\Models\TicketType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * JSON sibling of App\Http\Controllers\EventTicketTypeController (Slice D). No
 * create()/edit() — those web routes only return blank/prefilled form data,
 * which the app already has natively. Reuses StoreTicketTypeRequest /
 * UpdateTicketTypeRequest verbatim, including the image upload as multipart.
 */
class EventTicketTypeController extends Controller
{
    public function index(Event $event): JsonResponse|AnonymousResourceCollection
    {
        $this->authorizeTicketed($event);

        $event->load('ticketTypes');

        return response()->json([
            'ticket_types' => TicketTypeResource::collection($event->ticketTypes),
            'commission_percent' => $event->commissionPercent(),
            // Draft/Rejected = still setting up, never submitted (or fixing a
            // rejection) — same flag the web setup-vs-dashboard chrome switches on.
            'setup_mode' => $event->canSubmitTicketing(),
        ]);
    }

    public function store(StoreTicketTypeRequest $request, Event $event): JsonResponse
    {
        $this->authorizeTicketed($event);

        $data = $this->payloadFrom($request);
        $data['event_id'] = $event->id;

        if ($request->hasFile('image')) {
            $data['image_path'] = $this->storeImage($event, $request->file('image'));
        }

        $ticketType = TicketType::query()->create($data);

        return response()->json(['ticket_type' => new TicketTypeResource($ticketType)], 201);
    }

    public function update(UpdateTicketTypeRequest $request, Event $event, TicketType $ticketType): JsonResponse
    {
        $this->authorizeTicketed($event);
        abort_unless($ticketType->event_id === $event->id, 404);

        $data = $this->payloadFrom($request);
        $previousImage = $ticketType->image_path;

        if ($request->hasFile('image')) {
            $data['image_path'] = $this->storeImage($event, $request->file('image'));
        }

        $ticketType->fill($data);
        $ticketType->save();

        if (isset($data['image_path']) && $previousImage && $previousImage !== $data['image_path']) {
            Storage::disk('public')->delete($previousImage);
        }

        return response()->json(['ticket_type' => new TicketTypeResource($ticketType)]);
    }

    public function destroy(Event $event, TicketType $ticketType): JsonResponse
    {
        $this->authorizeTicketed($event);
        abort_unless($ticketType->event_id === $event->id, 404);

        if ($ticketType->hasBlockingSales()) {
            return response()->json([
                'message' => 'This ticket type has holds or issued tickets and cannot be deleted.',
                'errors' => ['ticket_type' => ['This ticket type has holds or issued tickets and cannot be deleted.']],
            ], 422);
        }

        $image = $ticketType->image_path;
        $ticketType->delete();

        if ($image) {
            Storage::disk('public')->delete($image);
        }

        return response()->json(['message' => 'Ticket type deleted.']);
    }

    private function authorizeTicketed(Event $event): void
    {
        $this->authorize('update', $event);

        abort_unless($event->isTicketed(), 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFrom(StoreTicketTypeRequest $request): array
    {
        $data = $request->validated();
        unset($data['image']);
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }

    private function storeImage(Event $event, UploadedFile $file): string
    {
        return $file->store('ticket-types/'.$event->id, 'public');
    }
}
