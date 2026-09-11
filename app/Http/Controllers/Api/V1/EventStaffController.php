<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEventStaffRequest;
use App\Http\Requests\UpdateEventStaffRoleRequest;
use App\Http\Resources\Api\V1\EventStaffResource;
use App\Models\Event;
use App\Models\EventStaff;
use App\Models\User;
use App\Notifications\EventStaffInviteNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Notification;

/**
 * JSON sibling of App\Http\Controllers\EventStaffController (Slice D). Ticketed
 * events only — an invitation event has no EventStaff surface at all, every
 * action here 404s otherwise, same as the web controller. Reuses
 * StoreEventStaffRequest/UpdateEventStaffRoleRequest verbatim (both authorize via
 * $this->user()?->can(...), guard-agnostic) and EventStaffInviteNotification
 * unchanged — accepting the invite still happens through the
 * StaffInvitationController endpoints (email link → app or browser), not here.
 */
class EventStaffController extends Controller
{
    public function index(Event $event): AnonymousResourceCollection
    {
        $this->authorize('manage', [EventStaff::class, $event]);
        abort_unless($event->isTicketed(), 404);

        $staff = $event->staff()->with(['user', 'inviter'])->get();

        return EventStaffResource::collection($staff);
    }

    public function store(StoreEventStaffRequest $request, Event $event): JsonResponse
    {
        abort_unless($event->isTicketed(), 404);
        abort_unless($event->ownerHasPremiumEventTools(), 403, 'Staff accounts unlock once EventHost approves ticket sales for this event.');

        $validated = $request->validated();

        $eventStaff = EventStaff::query()->firstOrNew([
            'event_id' => $event->id,
            'email' => $validated['email'],
        ]);

        $eventStaff->fill([
            'role' => $validated['role'],
            'name' => $validated['name'],
            'invited_by' => $request->user()->id,
            'user_id' => User::query()->where('email', $validated['email'])->value('id'),
            'accepted_at' => null,
        ]);
        $eventStaff->issueInviteToken();
        $eventStaff->save();

        Notification::route('mail', $validated['email'])
            ->notify(new EventStaffInviteNotification($eventStaff));

        return response()->json(['staff' => new EventStaffResource($eventStaff)], 201);
    }

    public function update(UpdateEventStaffRoleRequest $request, Event $event, EventStaff $eventStaff): JsonResponse
    {
        abort_unless($eventStaff->event_id === $event->id, 404);
        abort_unless($event->isTicketed(), 404);

        $eventStaff->update(['role' => $request->validated()['role']]);

        return response()->json(['staff' => new EventStaffResource($eventStaff)]);
    }

    public function resend(Event $event, EventStaff $eventStaff): JsonResponse
    {
        abort_unless($eventStaff->event_id === $event->id, 404);
        abort_unless($event->isTicketed(), 404);
        $this->authorize('update', $eventStaff);

        if (! $eventStaff->isPending()) {
            return response()->json(['message' => 'This invite has already been accepted.'], 409);
        }

        $eventStaff->issueInviteToken();
        $eventStaff->save();

        Notification::route('mail', $eventStaff->email)
            ->notify(new EventStaffInviteNotification($eventStaff));

        return response()->json(['staff' => new EventStaffResource($eventStaff)]);
    }

    public function destroy(Event $event, EventStaff $eventStaff): JsonResponse
    {
        abort_unless($eventStaff->event_id === $event->id, 404);
        abort_unless($event->isTicketed(), 404);
        $this->authorize('delete', $eventStaff);

        $eventStaff->delete();

        return response()->json(['message' => 'Staff member removed.']);
    }
}
