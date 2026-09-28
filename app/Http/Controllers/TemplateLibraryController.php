<?php

namespace App\Http\Controllers;

use App\Enums\SubscriptionTier;
use App\Models\Event;
use App\Models\InvitationTemplate;
use App\Models\InvitationTemplateCategory;
use App\Services\InvitationCustomizationService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TemplateLibraryController extends Controller
{
    /** Plan tabs on the library page; no `plan` query value means "All". */
    private const PLAN_TABS = ['base', 'pro'];

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $categorySlug = is_string($request->query('category')) && $request->query('category') !== ''
            ? $request->query('category')
            : null;
        $plan = in_array($request->query('plan'), self::PLAN_TABS, true) ? $request->query('plan') : null;

        // Only categories that would list something on this plan tab. Not the shared
        // 'tpl_categories' cache — that is the unfiltered list the wizard and API use.
        $categories = InvitationTemplateCategory::query()
            ->whereHas('invitationTemplates', function ($query) use ($plan): void {
                $query->where('is_active', true)
                    ->when($plan !== null, fn ($q) => $q->whereIn('min_subscription_tier', self::tiersForPlanTab($plan)));
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        // A category carried over from another tab that has nothing here would filter
        // to an empty grid while the dropdown reads "All categories".
        if ($categorySlug !== null && ! $categories->contains('slug', $categorySlug)) {
            $categorySlug = null;
        }

        $templates = InvitationTemplate::query()
            ->where('is_active', true)
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
            ->when($plan !== null, function ($query) use ($plan): void {
                $query->whereIn('min_subscription_tier', self::tiersForPlanTab($plan));
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('templates.index', compact('templates', 'categories', 'q', 'categorySlug', 'plan'));
    }

    /**
     * Templates a tab lists, by required tier. The Pro tab covers every tier from
     * Pro upwards so a template never falls out of both tabs.
     *
     * @return list<string>
     */
    private static function tiersForPlanTab(string $plan): array
    {
        return collect(SubscriptionTier::cases())
            ->filter(fn (SubscriptionTier $tier) => $plan === 'base'
                ? $tier->rank() <= SubscriptionTier::Base->rank()
                : $tier->rank() >= SubscriptionTier::Pro->rank())
            ->map(fn (SubscriptionTier $tier) => $tier->value)
            ->values()
            ->all();
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
