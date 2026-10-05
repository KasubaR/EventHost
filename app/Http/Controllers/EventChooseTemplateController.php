<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChooseEventTemplateRequest;
use App\Models\Event;
use App\Models\InvitationTemplate;
use App\Models\InvitationTemplateCategory;
use App\Services\InvitationCustomizationService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class EventChooseTemplateController extends Controller
{
    public function show(Request $request, Event $event): View|RedirectResponse
    {
        $this->authorize('update', $event);

        // Ticketed events use the one fixed public template — there is
        // nothing to pick here. Defensive against stale/bookmarked links;
        // nothing in the UI points here for a ticketed event.
        if ($event->isTicketed()) {
            return redirect()->route('events.edit', $event);
        }

        $q = trim((string) $request->query('q', ''));
        $categorySlug = $request->query('category');

        // First visit opens on the event's own type when it has layouts. Any submitted value,
        // including the empty "All categories", is the host's choice and wins.
        $categoryFromEventType = false;
        if (! $request->has('category')) {
            $categorySlug = $this->categoryForEventType($event);
            $categoryFromEventType = $categorySlug !== null;
        }

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

        $preferredId = $request->query('preferred');
        $preferredIdInt = is_numeric($preferredId) ? (int) $preferredId : null;

        $guestsHoldingInvitation = $event->is_published && $event->invitation_template_id !== null
            ? $event->guestsHoldingInvitationCount()
            : 0;

        return view('events.choose-template', compact(
            'event',
            'templates',
            'categories',
            'q',
            'categorySlug',
            'categoryFromEventType',
            'preferredIdInt',
            'guestsHoldingInvitation',
        ));
    }

    public function update(ChooseEventTemplateRequest $request, Event $event, InvitationCustomizationService $customizationService): RedirectResponse
    {
        if ($event->isTicketed()) {
            return redirect()->route('events.edit', $event);
        }

        $templateId = (int) $request->validated('invitation_template_id');
        $switched = $event->invitation_template_id !== null && $event->invitation_template_id !== $templateId;

        if ($event->invitation_template_id !== $templateId) {
            $customizationService->resetThemeColoursForTemplate($event, InvitationTemplate::query()->findOrFail($templateId));
        }

        $event->invitation_template_id = $templateId;
        $event->save();

        $redirect = redirect()->route('events.edit', $event)->with('status', 'template-chosen');

        // Guest links show the new layout at once and nobody is told. Say how many already have it,
        // the same prompt a venue change gets, and leave the decision to notify with the host.
        if ($switched && $event->is_published && ($holding = $event->guestsHoldingInvitationCount()) > 0) {
            $redirect->with('template_switched_guests', [
                'count' => $holding,
                'url' => route('events.guests.index', $event),
            ]);
        }

        return $redirect;
    }

    /**
     * The template category matching the event's type, when at least one active template carries it.
     */
    private function categoryForEventType(Event $event): ?string
    {
        $slug = array_search($event->event_type, Event::CATEGORY_SLUG_TO_TYPE, true);

        if ($slug === false) {
            return null;
        }

        $hasTemplates = InvitationTemplateCategory::query()
            ->where('slug', $slug)
            ->whereHas('invitationTemplates', fn ($query) => $query->where('is_active', true))
            ->exists();

        return $hasTemplates ? $slug : null;
    }
}
