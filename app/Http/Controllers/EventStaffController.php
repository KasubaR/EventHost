<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEventStaffRequest;
use App\Http\Requests\UpdateEventStaffRoleRequest;
use App\Models\Event;
use App\Models\EventStaff;
use App\Models\User;
use App\Notifications\EventStaffInviteNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Notification;
use Illuminate\View\View;

/**
 * Ticketed events only (Phase 18 brief) — an invitation/RSVP event has no
 * staff surface. Guests, tables, photos and invitation design stay owner-only
 * regardless of EventAccess::canManage()'s reach, simply because no
 * EventStaff row can ever exist on one: every action here 404s for
 * !$event->isTicketed(), so staffRoleFor() is always null off a ticketed event.
 */
class EventStaffController extends Controller
{
    public function index(Event $event): View
    {
        $this->authorize('manage', [EventStaff::class, $event]);
        abort_unless($event->isTicketed(), 404);

        $staff = $event->staff()->with(['user', 'inviter'])->get();

        return view('events.staff.index', compact('event', 'staff'));
    }

    public function store(StoreEventStaffRequest $request, Event $event): RedirectResponse
    {
        abort_unless($event->isTicketed(), 404);
        abort_unless($event->ownerHasPremiumEventTools(), 403, 'Staff accounts unlock once EventHost approves ticket sales for this event.');

        $validated = $request->validated();

        // One row per (event, email) — re-inviting the same address refreshes
        // it (new token/expiry, possibly a new role) instead of erroring.
        $eventStaff = EventStaff::query()->firstOrNew([
            'event_id' => $event->id,
            'email' => $validated['email'],
        ]);

        $eventStaff->fill([
            'role' => $validated['role'],
            'name' => $validated['name'],
            'invited_by' => $request->user()->id,
            // An existing account is linked immediately so the staff list
            // shows who they are, but accepted_at stays null — they still
            // have to click the invite link to prove the mailbox before it
            // grants access. See EventAccess (only accepted_at counts).
            'user_id' => User::query()->where('email', $validated['email'])->value('id'),
            'accepted_at' => null,
        ]);
        $eventStaff->issueInviteToken();
        $eventStaff->save();

        Notification::route('mail', $validated['email'])
            ->notify(new EventStaffInviteNotification($eventStaff));

        return redirect()
            ->route('events.staff.index', $event)
            ->with('status', 'staff-invited');
    }

    public function update(UpdateEventStaffRoleRequest $request, Event $event, EventStaff $eventStaff): RedirectResponse
    {
        abort_unless($eventStaff->event_id === $event->id, 404);
        abort_unless($event->isTicketed(), 404);

        $eventStaff->update(['role' => $request->validated()['role']]);

        return redirect()
            ->route('events.staff.index', $event)
            ->with('status', 'staff-role-updated');
    }

    public function resend(Event $event, EventStaff $eventStaff): RedirectResponse
    {
        abort_unless($eventStaff->event_id === $event->id, 404);
        abort_unless($event->isTicketed(), 404);
        $this->authorize('update', $eventStaff);

        if (! $eventStaff->isPending()) {
            return redirect()->route('events.staff.index', $event);
        }

        $eventStaff->issueInviteToken();
        $eventStaff->save();

        Notification::route('mail', $eventStaff->email)
            ->notify(new EventStaffInviteNotification($eventStaff));

        return redirect()
            ->route('events.staff.index', $event)
            ->with('status', 'staff-invite-resent');
    }

    public function destroy(Event $event, EventStaff $eventStaff): RedirectResponse
    {
        abort_unless($eventStaff->event_id === $event->id, 404);
        abort_unless($event->isTicketed(), 404);
        $this->authorize('delete', $eventStaff);

        $eventStaff->delete();

        return redirect()
            ->route('events.staff.index', $event)
            ->with('status', 'staff-removed');
    }
}
