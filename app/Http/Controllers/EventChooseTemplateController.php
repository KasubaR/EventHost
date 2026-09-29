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

        return view('events.choose-template', compact('event', 'templates', 'categories', 'q', 'categorySlug', 'preferredIdInt'));
    }

    public function update(ChooseEventTemplateRequest $request, Event $event, InvitationCustomizationService $customizationService): RedirectResponse
    {
        if ($event->isTicketed()) {
            return redirect()->route('events.edit', $event);
        }

        $templateId = (int) $request->validated('invitation_template_id');

        if ($event->invitation_template_id !== $templateId) {
            $customizationService->resetThemeColoursForTemplate($event, InvitationTemplate::query()->findOrFail($templateId));
        }

        $event->invitation_template_id = $templateId;
        $event->save();

        return redirect()->route('events.edit', $event)->with('status', 'template-chosen');
    }
}
