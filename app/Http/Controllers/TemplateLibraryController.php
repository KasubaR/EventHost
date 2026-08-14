<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\InvitationTemplate;
use App\Models\InvitationTemplateCategory;
use App\Services\InvitationCustomizationService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class TemplateLibraryController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $categorySlug = $request->query('category');

        // Categories are global — one shared cache key is correct today.
        // TODO: if per-plan or per-user category visibility is ever added, scope this
        // key (e.g. 'tpl_categories_'.$user->subscription_tier) so the shared cache
        // does not leak restricted categories across users.
        $categories = Cache::remember('tpl_categories', 300, function (): Collection {
            return InvitationTemplateCategory::query()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();
        });

        $templates = InvitationTemplate::query()
            ->where('is_active', true)
            ->with('categories')
            ->when($categorySlug, function ($query) use ($categorySlug): void {
                $query->whereHas('categories', function ($q2) use ($categorySlug): void {
                    $q2->where('slug', $categorySlug);
                });
            })
            ->when($q !== '', function ($query) use ($q): void {
                $like = '%'.$q.'%';
                $query->where(function ($inner) use ($like): void {
                    $inner->where('name', 'like', $like)
                        ->orWhere('description', 'like', $like);
                });
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('templates.index', compact('templates', 'categories', 'q', 'categorySlug'));
    }

    public function preview(
        Request $request,
        InvitationTemplate $invitation_template,
        InvitationCustomizationService $customizationService
    ): View {
        if (! $invitation_template->is_active) {
            abort(404);
        }

        $event = $invitation_template->previewSampleEvent();
        abort_unless($event instanceof Event, 500, 'previewSampleEvent() must return an Event instance.');
        $rsvpOpen = $event->isRsvpOpen();
        $rsvpPublicAvailable = $event->is_public && $rsvpOpen;
        $invitation = $customizationService->merge($event);

        // Set when the preview is opened from step 2 of the event wizard, so the
        // page can offer a way back to that event instead of the shared library.
        $fromEvent = $this->resolveFromEvent($request);

        return view('templates.preview', compact('event', 'rsvpOpen', 'rsvpPublicAvailable', 'invitation', 'invitation_template', 'fromEvent'));
    }

    private function resolveFromEvent(Request $request): ?Event
    {
        $id = $request->query('from_event');

        if (! is_numeric($id)) {
            return null;
        }

        $event = Event::find((int) $id);

        // Silently ignore an event the viewer may not edit — the preview itself
        // is public to any signed-in user, only the return path is restricted.
        if (! $event instanceof Event || $request->user()?->cannot('update', $event)) {
            return null;
        }

        return $event;
    }
}
