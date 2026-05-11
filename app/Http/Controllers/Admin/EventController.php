<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminEventPublishRequest;
use App\Models\Event;
use App\Support\AdminActivity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class EventController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        $events = Event::query()
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

    public function updatePublish(UpdateAdminEventPublishRequest $request, Event $event): RedirectResponse
    {
        $published = (bool) $request->validated()['is_published'];
        $event->is_published = $published;
        $event->save();

        AdminActivity::log('Admin changed event publish state', [
            'event_id' => $event->id,
            'is_published' => $published,
        ]);

        return redirect()->back()->with('status', 'event-publish-updated');
    }

    public function destroy(Event $event): RedirectResponse
    {
        $eventId = $event->id;
        $eventName = $event->name;
        $cover = $event->cover_image;
        $customization = $event->invitation_customization;
        $customizationPrevious = $event->invitation_customization_previous;

        DB::transaction(function () use ($event): void {
            $event->delete();
        });

        AdminActivity::log('Admin deleted event', [
            'event_id' => $eventId,
            'event_name' => $eventName,
        ]);

        DB::afterCommit(function () use ($cover, $customization, $customizationPrevious): void {
            if ($cover) {
                Storage::disk('public')->delete($cover);
            }

            foreach ([$customization, $customizationPrevious] as $custom) {
                if (! is_array($custom)) {
                    continue;
                }
                foreach ($custom['media']['gallery'] ?? [] as $path) {
                    Storage::disk('public')->delete($path);
                }
                $hero = $custom['media']['hero_portrait'] ?? null;
                if (is_string($hero) && $hero !== '') {
                    Storage::disk('public')->delete($hero);
                }
                foreach ($custom['media']['couple_photos'] ?? [] as $path) {
                    if (is_string($path) && $path !== '') {
                        Storage::disk('public')->delete($path);
                    }
                }
                $video = $custom['effects']['video_background'] ?? null;
                $audio = $custom['effects']['audio_track'] ?? null;
                if (is_string($video) && $video !== '') {
                    Storage::disk('public')->delete($video);
                }
                if (is_string($audio) && $audio !== '') {
                    Storage::disk('public')->delete($audio);
                }
            }
        });

        return redirect()->route('admin.events.index')->with('status', 'event-deleted');
    }
}
