<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdminTicketTypeRequest;
use App\Http\Requests\Admin\UpdateAdminTicketTypeRequest;
use App\Models\Event;
use App\Models\TicketType;
use App\Support\AdminActivity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class TicketTypeController extends Controller
{
    public function create(Event $event): View
    {
        abort_unless($event->isTicketed(), 404);

        return view('admin.ticketing.ticket-types.create', [
            'adminEvent' => $event,
            'ticketType' => null,
        ]);
    }

    public function store(StoreAdminTicketTypeRequest $request, Event $event): RedirectResponse
    {
        abort_unless($event->isTicketed(), 404);

        $data = $this->payloadFrom($request);
        $data['event_id'] = $event->id;

        if ($request->hasFile('image')) {
            $data['image_path'] = $this->storeImage($event, $request->file('image'));
        }

        $ticketType = TicketType::query()->create($data);

        AdminActivity::log('Admin created ticket type', [
            'event_id' => $event->id,
            'ticket_type_id' => $ticketType->id,
        ]);

        return redirect()
            ->route('admin.ticketing.show', $event)
            ->with('status', 'ticket-type-created');
    }

    public function edit(Event $event, TicketType $ticketType): View
    {
        abort_unless($event->isTicketed(), 404);
        abort_unless($ticketType->event_id === $event->id, 404);

        return view('admin.ticketing.ticket-types.edit', [
            'adminEvent' => $event,
            'ticketType' => $ticketType,
        ]);
    }

    public function update(
        UpdateAdminTicketTypeRequest $request,
        Event $event,
        TicketType $ticketType
    ): RedirectResponse {
        abort_unless($event->isTicketed(), 404);
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

        AdminActivity::log('Admin updated ticket type', [
            'event_id' => $event->id,
            'ticket_type_id' => $ticketType->id,
        ]);

        return redirect()
            ->route('admin.ticketing.show', $event)
            ->with('status', 'ticket-type-updated');
    }

    public function destroy(Event $event, TicketType $ticketType): RedirectResponse
    {
        abort_unless($event->isTicketed(), 404);
        abort_unless($ticketType->event_id === $event->id, 404);

        if ($ticketType->hasBlockingSales()) {
            return redirect()
                ->route('admin.ticketing.show', $event)
                ->withErrors(['ticket_type' => 'This ticket type has holds or issued tickets and cannot be deleted.']);
        }

        $image = $ticketType->image_path;
        $ticketTypeId = $ticketType->id;
        $ticketType->delete();

        if ($image) {
            Storage::disk('public')->delete($image);
        }

        AdminActivity::log('Admin deleted ticket type', [
            'event_id' => $event->id,
            'ticket_type_id' => $ticketTypeId,
        ]);

        return redirect()
            ->route('admin.ticketing.show', $event)
            ->with('status', 'ticket-type-deleted');
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFrom(StoreAdminTicketTypeRequest $request): array
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
