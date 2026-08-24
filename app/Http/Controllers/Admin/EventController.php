<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\InsufficientCreditsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminEventPublishRequest;
use App\Models\Event;
use App\Models\User;
use App\Services\EventCreditService;
use App\Support\AdminActivity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class EventController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        $events = Event::withTrashed()
            ->with(['user:id,name,email'])
            ->withCount(['rsvps', 'guests'])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($q) use ($search): void {
                    $q->where('name', 'like', '%'.$search.'%')
                        ->orWhereHas('user', function ($uq) use ($search): void {
                            $uq->where('name', 'like', '%'.$search.'%')
                                ->orWhere('email', 'like', '%'.$search.'%');
                        });
                });
            })
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.events.index', [
            'events' => $events,
            'search' => $search,
        ]);
    }

    public function show(Event $event): View
    {
        $event->load(['user:id,name,email,phone,status']);
        $event->loadCount(['guests', 'rsvps']);

        return view('admin.events.show', [
            'adminEvent' => $event,
        ]);
    }

    public function updatePublish(
        UpdateAdminEventPublishRequest $request,
        Event $event,
        EventCreditService $credits
    ): RedirectResponse {
        if ($event->isTicketed()) {
            return redirect()->back()->withErrors([
                'is_published' => 'Ticketed events go live from the Ticketing queue — they do not use event credits.',
            ]);
        }

        $published = (bool) $request->validated()['is_published'];

        // Live invitations are taken down with Pause / Cancel — unpublishing
        // would 404 and look like the event never existed.
        if ($event->is_published && ! $published) {
            return redirect()->back()->withErrors([
                'is_published' => 'To hide a live invitation, pause or cancel it instead of unpublishing.',
            ]);
        }

        try {
            DB::transaction(function () use ($event, $published, $credits): void {
                $locked = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

                if ($published && ! $locked->is_published) {
                    $owner = User::query()->whereKey($locked->user_id)->firstOrFail();
                    $credits->chargeFirstPublish($owner, $locked);
                }

                $locked->is_published = $published;
                $locked->save();
            });
        } catch (InsufficientCreditsException) {
            return redirect()->back()->withErrors([
                'is_published' => 'The host has no event credits. Grant them a credit before publishing this event.',
            ]);
        }

        AdminActivity::log('Admin changed event publish state', [
            'event_id' => $event->id,
            'is_published' => $published,
        ]);

        return redirect()->back()->with('status', 'event-publish-updated');
    }

    public function pause(Event $event): RedirectResponse
    {
        if (! $event->is_published || $event->trashed() || $event->isCancelled()) {
            return redirect()->back()->withErrors(['event' => 'Only a live invitation can be paused.']);
        }

        $event->invitation_paused_at = now();
        $event->save();

        AdminActivity::log('Admin paused invitation', ['event_id' => $event->id]);

        return redirect()->back()->with('status', 'invitation-paused');
    }

    public function resume(Event $event): RedirectResponse
    {
        $event->invitation_paused_at = null;
        $event->save();

        AdminActivity::log('Admin resumed invitation', ['event_id' => $event->id]);

        return redirect()->back()->with('status', 'invitation-resumed');
    }

    public function cancel(Event $event): RedirectResponse
    {
        if ($event->trashed() || ! $event->is_published) {
            return redirect()->back()->withErrors(['event' => 'Only a published event can be cancelled.']);
        }

        $event->cancelled_at = now();
        $event->invitation_paused_at = null;
        $event->save();

        AdminActivity::log('Admin cancelled event', ['event_id' => $event->id]);

        return redirect()->back()->with('status', 'event-cancelled');
    }

    public function uncancel(Event $event): RedirectResponse
    {
        $event->cancelled_at = null;
        $event->save();

        AdminActivity::log('Admin reopened event', ['event_id' => $event->id]);

        return redirect()->back()->with('status', 'event-reopened');
    }

    public function restore(Event $event): RedirectResponse
    {
        if (! $event->trashed()) {
            return redirect()->route('admin.events.show', $event);
        }

        $event->restore();

        AdminActivity::log('Admin restored event', ['event_id' => $event->id]);

        return redirect()->route('admin.events.show', $event)->with('status', 'event-restored');
    }

    public function destroy(Event $event): RedirectResponse
    {
        if ($event->hasBlockingTicketCommerce()) {
            return redirect()->back()->withErrors([
                'event' => 'This event has ticket holds or orders in progress and cannot be deleted.',
            ]);
        }

        $eventId = $event->id;
        $eventName = $event->name;

        // Soft-delete only — keep media so restore works.
        $event->delete();

        AdminActivity::log('Admin deleted event', [
            'event_id' => $eventId,
            'event_name' => $eventName,
        ]);

        return redirect()->route('admin.events.index')->with('status', 'event-deleted');
    }
}
