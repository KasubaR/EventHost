<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTicketTypeRequest;
use App\Http\Requests\UpdateTicketTypeRequest;
use App\Models\Event;
use App\Models\TicketType;
use App\Support\TicketingSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class EventTicketTypeController extends Controller
{
    public function index(Event $event): View
    {
        $this->authorizeTicketed($event);

        $event->load('ticketTypes');

        return view('events.tickets.index', [
            'event' => $event,
            'ticketTypes' => $event->ticketTypes,
            'commissionPercent' => TicketingSettings::commissionPercent(),
            // Draft/Rejected = still setting up, never submitted (or fixing a
            // rejection) — show the creation-wizard chrome instead of the
            // ticketing dashboard tabs. Same rule as Event::canSubmitTicketing().
            'setupMode' => $event->canSubmitTicketing(),
        ]);
    }

    public function create(Event $event): View
    {
        $this->authorizeTicketed($event);

        return view('events.tickets.create', [
            'event' => $event,
            'ticketType' => null,
        ]);
    }

    public function store(StoreTicketTypeRequest $request, Event $event): RedirectResponse
    {
        $this->authorizeTicketed($event);

        $data = $this->payloadFrom($request);
        $data['event_id'] = $event->id;

        if ($request->hasFile('image')) {
            $data['image_path'] = $this->storeImage($event, $request->file('image'));
        }

        TicketType::query()->create($data);

        return redirect()
            ->route('events.ticket-types.index', $event)
            ->with('status', 'ticket-type-created');
    }

    public function edit(Event $event, TicketType $ticketType): View
    {
        $this->authorizeTicketed($event);

        return view('events.tickets.edit', [
            'event' => $event,
            'ticketType' => $ticketType,
        ]);
    }

    public function update(UpdateTicketTypeRequest $request, Event $event, TicketType $ticketType): RedirectResponse
    {
        $this->authorizeTicketed($event);

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

        return redirect()
            ->route('events.ticket-types.index', $event)
            ->with('status', 'ticket-type-updated');
    }

    public function destroy(Event $event, TicketType $ticketType): RedirectResponse
    {
        $this->authorizeTicketed($event);

        if ($ticketType->event_id !== $event->id) {
            abort(404);
        }

        if ($ticketType->hasBlockingSales()) {
            return redirect()
                ->route('events.ticket-types.index', $event)
                ->withErrors(['ticket_type' => 'This ticket type has holds or issued tickets and cannot be deleted.']);
        }

        $image = $ticketType->image_path;
        $ticketType->delete();

        if ($image) {
            Storage::disk('public')->delete($image);
        }

        return redirect()
            ->route('events.ticket-types.index', $event)
            ->with('status', 'ticket-type-deleted');
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
