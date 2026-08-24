<?php

namespace App\Http\Controllers;

use App\Enums\CommissionMode;
use App\Enums\EventProductKind;
use App\Enums\RsvpStatus;
use App\Enums\TicketingStatus;
use App\Exceptions\InsufficientCreditsException;
use App\Http\Requests\StoreEventRequest;
use App\Http\Requests\UpdateEventRequest;
use App\Models\CreditTransaction;
use App\Models\Event;
use App\Models\InvitationTemplate;
use App\Models\StagedMedia;
use App\Services\DashboardAnalyticsService;
use App\Services\EventCreditService;
use App\Services\EventSlugService;
use App\Services\InvitationCustomizationService;
use App\Support\InvitationMediaStager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class EventController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Event::class, 'event');
    }

    public function index(): View
    {
        $kind = EventProductKind::tryFrom((string) request('kind'));

        // Two independent paginators so a long draft list never pushes published
        // events off the page. Distinct page names keep their ?page params apart.
        $mine = fn () => Event::query()
            ->where('user_id', auth()->id())
            ->when($kind, fn ($query) => $query->where('product_kind', $kind))
            ->orderByDesc('event_date')
            ->orderByDesc('created_at');

        $published = $mine()->where('is_published', true)
            ->paginate(10, ['*'], 'published_page')
            ->withQueryString();

        $drafts = $mine()->where('is_published', false)
            ->paginate(10, ['*'], 'draft_page')
            ->withQueryString();

        $deleted = Event::onlyTrashed()
            ->where('user_id', auth()->id())
            ->when($kind, fn ($query) => $query->where('product_kind', $kind))
            ->orderByDesc('deleted_at')
            ->paginate(10, ['*'], 'deleted_page')
            ->withQueryString();

        // Events this user has accepted staff access on (Phase 18) — separate
        // from "mine" above, which is ownership-only.
        $staffing = Event::query()
            ->whereHas('staff', fn ($query) => $query
                ->where('user_id', auth()->id())
                ->whereNotNull('accepted_at'))
            ->orderByDesc('event_date')
            ->paginate(10, ['*'], 'staff_page')
            ->withQueryString();

        return view('events.index', compact('published', 'drafts', 'deleted', 'staffing', 'kind'));
    }

    public function create(Request $request): View|RedirectResponse
    {
        if (Event::openDraftCountFor((int) $request->user()->id) >= Event::MAX_OPEN_DRAFTS) {
            return redirect()->route('events.index')->with('status', 'draft-limit');
        }

        $prefTemplateId = null;
        $templateSlug = $request->query('template');
        if (is_string($templateSlug) && $templateSlug !== '') {
            $prefTemplateId = InvitationTemplate::query()
                ->where('slug', $templateSlug)
                ->where('is_active', true)
                ->value('id');
            if ($prefTemplateId === null) {
                $templateSlug = null;
            }
        } else {
            $templateSlug = null;
        }

        $productKind = $this->resolveCreateProductKind($request, $prefTemplateId);
        if ($productKind === EventProductKind::Ticketed) {
            $prefTemplateId = null;
        }

        return view('events.create', compact('prefTemplateId', 'templateSlug', 'productKind'));
    }

    public function store(StoreEventRequest $request): RedirectResponse
    {
        if (Event::openDraftCountFor((int) $request->user()->id) >= Event::MAX_OPEN_DRAFTS) {
            return redirect()->route('events.index')->with('status', 'draft-limit');
        }

        $data = $request->validated();
        $preferredTemplateId = $data['preferred_invitation_template_id'] ?? null;
        unset($data['preferred_invitation_template_id'], $data['cover_image']);

        $productKind = EventProductKind::from((string) $data['product_kind']);
        $newPath = null;

        try {
            // Ticketed events have no host cover — EventHost uploads the public
            // hero on the ticketing review page. Ignore a file that arrived
            // because the host switched product kind after picking one.
            if ($productKind !== EventProductKind::Ticketed && $request->hasFile('cover_image')) {
                $newPath = $this->storeCoverImage($request->file('cover_image'));
                $data['cover_image'] = $newPath;
            }

            $data['user_id'] = (int) $request->user()->id;
            $data['is_published'] = false;

            if ($productKind === EventProductKind::Ticketed) {
                unset($data['rsvp_deadline'], $data['guest_limit'], $data['allow_plus_one'], $data['show_guest_list']);
                $data['ticketing_status'] = TicketingStatus::Draft;
                $data['commission_mode'] = CommissionMode::Absorb;
                $data['is_public'] = true;
            } else {
                $data['ticketing_status'] = TicketingStatus::NotApplicable;
                $data['commission_mode'] = null;
            }

            $customSlug = $data['slug'] ?? null;
            unset($data['slug']);

            $event = new Event($data);
            $slugService = app(EventSlugService::class);
            $slugService->apply(is_string($customSlug) ? $customSlug : null, $event);
            $event->save();
            // Only bites when no custom slug was given above — that path already
            // checked event_slug_redirects. The auto-generated (from name) path
            // goes through Sluggable, which has no idea that table exists.
            $slugService->resolveAutoSlugCollision($event);
        } catch (\Throwable $e) {
            if ($newPath) {
                Storage::disk('public')->delete($newPath);
            }

            // A concurrent request can win the same custom slug between apply()'s
            // check and this save() — the unique index still stops the duplicate
            // row, but as a raw QueryException. Surface it the same way apply()
            // does when it catches the collision itself.
            if (EventSlugService::isSlugUniqueViolation($e)) {
                throw ValidationException::withMessages([
                    'slug' => 'That custom URL is already taken.',
                ]);
            }

            throw $e;
        }

        // Ticketed events have no invitation layout to pick — they render the
        // one fixed public template (events/tickets/landing.blade.php), so
        // skip the preferred-template shortcut and the choose-template step
        // entirely. They land on the Tickets step (wizard step 3) instead of
        // back on the details form — see plans/ticketing.md's wizard reorder.
        if ($event->isTicketed()) {
            return redirect()->route('events.ticket-types.index', $event)->with('status', 'draft-saved');
        }

        if ($preferredTemplateId !== null) {
            $preferredTemplate = InvitationTemplate::find((int) $preferredTemplateId);
            if ($preferredTemplate && $request->user()->canUseInvitationTemplate($preferredTemplate)) {
                $event->update(['invitation_template_id' => $preferredTemplate->id]);

                return redirect()->route('events.edit', $event)->with('status', 'template-chosen');
            }
        }

        return redirect()->route('events.choose-template', ['event' => $event])->with('status', 'draft-saved');
    }

    public function show(Event $event, DashboardAnalyticsService $analyticsService): View
    {
        $rsvpSummary = [
            'invited' => $event->guests()->count(),
            'pending' => $event->guests()->whereDoesntHave('rsvp')->count(),
            'accepted' => $event->rsvps()->where('status', RsvpStatus::Accepted)->count(),
            'declined' => $event->rsvps()->where('status', RsvpStatus::Declined)->count(),
            'maybe' => $event->rsvps()->where('status', RsvpStatus::Maybe)->count(),
            'accepted_heads' => $event->acceptedAttendeeHeadcount(),
        ];

        $eventAnalytics = $analyticsService->forEvent($event);

        return view('events.show', compact('event', 'rsvpSummary', 'eventAnalytics'));
    }

    public function edit(Event $event, InvitationCustomizationService $customizationService): View
    {
        $invitationMerged = null;
        $templateFingerprint = null;
        $customizationToken = null;
        $publishCostsCredit = ! $event->isTicketed() && ! $event->is_published && ! $event->hasConsumedPublishCredit();

        if ($event->invitation_template_id !== null) {
            $invitationMerged = $customizationService->merge($event);

            $resolvedTemplate = $event->getRelation('invitationTemplate');
            $templateFingerprint = $customizationService->templateFingerprint($resolvedTemplate);
            $customizationToken = md5(json_encode($event->invitation_customization) ?: '');
        }

        // Only ticketed events render the activation panel (step 4's closing
        // action); everyone else gets null and the partial is never included.
        $ticketTypes = $event->isTicketed() ? $event->loadMissing('ticketTypes')->ticketTypes : null;

        return view('events.edit', compact(
            'event',
            'invitationMerged',
            'templateFingerprint',
            'customizationToken',
            'publishCostsCredit',
            'ticketTypes',
        ));
    }

    public function update(
        UpdateEventRequest $request,
        Event $event,
        EventCreditService $credits
    ): RedirectResponse|JsonResponse {
        $newCoverPath = null;
        $previousCover = null;
        // Only a cover written *during this request* may be rolled back on failure.
        // A staged cover was uploaded minutes ago and its form is about to be
        // redisplayed showing it, so deleting it here would break that page.
        $coverIsRollbackable = false;

        $acceptHostCover = ! $event->isTicketed();

        $stagedCover = $acceptHostCover
            ? StagedMedia::query()
                ->ownedBy($event->id, $request->user()->id)
                ->where('slot', StagedMedia::SLOT_COVER)
                ->whereIn('id', array_map('intval', (array) $request->input('staged_media', [])))
                ->latest('id')
                ->first()
            : null;

        // "Publish event" submits this same form, so the pending edits are saved
        // in the same request rather than being discarded by a separate publish post.
        $shouldPublish = $request->boolean('publish');
        $needsPublishCredit = false;
        $chargeable = false;
        $notifyGuestsCount = 0;

        try {
            if ($acceptHostCover && $stagedCover !== null) {
                $previousCover = $event->cover_image;
                $newCoverPath = $stagedCover->path;
            } elseif ($acceptHostCover && $request->hasFile('cover_image')) {
                $previousCover = $event->cover_image;
                $newCoverPath = $this->storeCoverImage($request->file('cover_image'));
                $coverIsRollbackable = true;
            }

            DB::transaction(function () use ($request, &$event, $newCoverPath, $stagedCover, $shouldPublish, $credits, &$previousCover, &$needsPublishCredit, &$chargeable, &$notifyGuestsCount): void {
                $event = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
                $wasPublished = $event->is_published;
                $data = $request->validated();

                // Never a column — it is the receipt for an upload that already happened.
                unset($data['staged_media'], $data['cover_image']);

                $customSlug = array_key_exists('slug', $data) ? $data['slug'] : null;
                unset($data['slug']);

                if ($event->isTicketed()) {
                    unset(
                        $data['is_public'],
                        $data['rsvp_deadline'],
                        $data['guest_limit'],
                        $data['allow_plus_one'],
                        $data['show_guest_list'],
                    );
                }

                if ($newCoverPath !== null) {
                    $data['cover_image'] = $newCoverPath;
                }

                // Recompute after the lock so a concurrent save that already
                // applied the same identity change cannot spend twice, and so
                // an unpaid past draft is not charged as a redefine.
                $needsPublishCredit = $shouldPublish && ! $event->is_published && ! $event->hasConsumedPublishCredit();
                $chargeable = $event->is_published
                    && $event->isLocked()
                    && $event->identityChangedBy($data);

                if ($shouldPublish && $event->isTicketed()) {
                    throw ValidationException::withMessages([
                        'publish' => 'Ticketed events go live after EventHost activates ticket sales — they do not use event credits.',
                    ]);
                }

                if ($shouldPublish) {
                    $credits->chargeFirstPublish($request->user(), $event);
                    $data['is_published'] = true;
                }

                if ($chargeable) {
                    $credits->spend(
                        $request->user(),
                        CreditTransaction::REASON_EVENT_REDEFINED,
                        $event
                    );
                }

                $event->fill($data);

                // A guest who already has this event's invitation link or has
                // already RSVP'd was never told the venue/location changed —
                // nothing pushes an update to them automatically. Surface a
                // count here so the host can be prompted to send one instead
                // of the change going out silently. Scoped to an event that
                // was *already* live: a draft becoming published in this same
                // save has no guests relying on a version of the page they
                // have already seen.
                if ($wasPublished && $event->isDirty(['venue', 'location_name', 'latitude', 'longitude'])) {
                    $notifyGuestsCount = $event->guests()
                        ->where(function ($query): void {
                            $query->where('invitation_sent', true)->orWhereHas('rsvp');
                        })
                        ->count();
                }

                app(EventSlugService::class)->apply(is_string($customSlug) ? $customSlug : null, $event);
                $event->save();

                // Inside the transaction: a rollback must leave the row in place so
                // the redisplayed form still knows about the uploaded cover.
                $stagedCover?->delete();

                if ($previousCover) {
                    DB::afterCommit(function () use ($previousCover): void {
                        Storage::disk('public')->delete($previousCover);
                    });
                }
            });
        } catch (\Throwable $e) {
            if ($newCoverPath !== null && $coverIsRollbackable) {
                Storage::disk('public')->delete($newCoverPath);
            }

            if ($e instanceof InsufficientCreditsException) {
                [$message, $status] = $this->insufficientCreditFeedback($needsPublishCredit, $chargeable);

                // The edit page saves over fetch(), which would follow a redirect
                // and report it as success — it needs a 422 to show the message.
                if ($request->expectsJson()) {
                    return response()->json(['message' => $message], 422);
                }

                return redirect()
                    ->route('billing.show')
                    ->with('status', $status);
            }

            // A concurrent request can win the same custom slug between apply()'s
            // check and save() inside the transaction above — the unique index
            // still stops the duplicate row, but as a raw QueryException. Surface
            // it the same way apply() does when it catches the collision itself.
            if (EventSlugService::isSlugUniqueViolation($e)) {
                throw ValidationException::withMessages([
                    'slug' => 'That custom URL is already taken.',
                ]);
            }

            throw $e;
        }

        if ($shouldPublish) {
            return redirect()->route('events.public', $event->slug)->with('status', 'published');
        }

        if ($notifyGuestsCount > 0) {
            // The edit page saves over fetch() and reloads on success rather than
            // following a redirect — a flashed session value would already be
            // consumed (and aged out) by the fetch's own redirect hop before that
            // reload's request ever sees it. Return it directly in the body instead
            // so event-edit-save.js can act on it. The plain-form fallback (no JS)
            // has no such hop, so the flash still works there.
            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 'event-updated',
                    'notify_guests' => [
                        'count' => $notifyGuestsCount,
                        'url' => route('events.guests.index', $event),
                    ],
                ]);
            }

            return back()->with([
                'status' => 'event-updated',
                'notify_guests_count' => $notifyGuestsCount,
            ]);
        }

        return back()->with('status', 'event-updated');
    }

    public function destroy(Event $event): RedirectResponse
    {
        $this->authorize('delete', $event);

        if ($event->hasBlockingTicketCommerce()) {
            throw ValidationException::withMessages([
                'event' => 'This event has ticket holds or orders in progress and cannot be deleted.',
            ]);
        }

        // Soft-delete only — keep cover/invitation media so restore works.
        // A later prune job can hard-delete after a retention window.
        $event->delete();

        return redirect()->route('events.index')->with('status', 'event-deleted');
    }

    public function restore(Event $event): RedirectResponse
    {
        $this->authorize('restore', $event);

        if (! $event->trashed()) {
            return redirect()->route('events.show', $event);
        }

        $event->restore();

        return redirect()->route('events.show', $event)->with('status', 'event-restored');
    }

    public function pause(Event $event): RedirectResponse
    {
        $this->authorize('pause', $event);

        if (! $event->is_published || $event->trashed() || $event->isCancelled()) {
            return back()->withErrors(['event' => 'Only a live invitation can be paused.']);
        }

        $event->invitation_paused_at = now();
        $event->save();

        return back()->with('status', 'invitation-paused');
    }

    public function resume(Event $event): RedirectResponse
    {
        $this->authorize('pause', $event);

        $event->invitation_paused_at = null;
        $event->save();

        return back()->with('status', 'invitation-resumed');
    }

    public function cancel(Event $event): RedirectResponse
    {
        $this->authorize('cancel', $event);

        if ($event->trashed() || ! $event->is_published) {
            return back()->withErrors(['event' => 'Only a published event can be cancelled.']);
        }

        $event->cancelled_at = now();
        $event->invitation_paused_at = null;
        $event->save();

        return back()->with('status', 'event-cancelled');
    }

    public function uncancel(Event $event): RedirectResponse
    {
        $this->authorize('cancel', $event);

        $event->cancelled_at = null;
        $event->save();

        return back()->with('status', 'event-reopened');
    }

    public function publish(Request $request, Event $event, EventCreditService $credits): RedirectResponse
    {
        $this->authorize('publish', $event);

        if ($event->isTicketed()) {
            return redirect()
                ->route('events.ticket-types.index', $event)
                ->withErrors([
                    'publish' => 'Ticketed events go live after EventHost activates ticket sales — they do not use event credits.',
                ]);
        }

        $needsPublishCredit = ! $event->is_published && ! $event->hasConsumedPublishCredit();

        if ($needsPublishCredit && ! $request->user()->canCreateEvent()) {
            return redirect()->route('billing.show')->with('status', 'no-event-credits');
        }

        try {
            DB::transaction(function () use ($request, $event, $credits): void {
                $locked = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

                $credits->chargeFirstPublish($request->user(), $locked);

                $locked->is_published = true;
                $locked->save();
            });
        } catch (InsufficientCreditsException) {
            return redirect()->route('billing.show')->with('status', 'no-event-credits');
        }

        return redirect()->route('events.public', $event->fresh()->slug)->with('status', 'published');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function insufficientCreditFeedback(bool $needsPublishCredit, bool $chargeable): array
    {
        if ($needsPublishCredit && $chargeable) {
            return [
                'Publishing this event and changing its name, type or date uses 2 event credits, and you do not have enough.',
                'no-event-credits',
            ];
        }

        if ($needsPublishCredit) {
            return [
                'Publishing uses 1 event credit, and you have none left.',
                'no-event-credits',
            ];
        }

        return [
            'Changing the name, type or date of an event that has already taken place '
            .'uses 1 event credit, and you have none left.',
            'no-credits-to-redefine',
        ];
    }

    private function resolveCreateProductKind(Request $request, mixed $prefTemplateId): ?EventProductKind
    {
        $fromQuery = $request->query('kind');
        if (is_string($fromQuery)) {
            $kind = EventProductKind::tryFrom($fromQuery);
            if ($kind !== null) {
                return $kind;
            }
        }

        $fromOld = old('product_kind');
        if (is_string($fromOld)) {
            $kind = EventProductKind::tryFrom($fromOld);
            if ($kind !== null) {
                return $kind;
            }
        }

        if ($prefTemplateId !== null) {
            return EventProductKind::Invitation;
        }

        return null;
    }

    /**
     * Shared with the staging endpoint so a cover uploaded on pick and a cover
     * uploaded with the form land in the same shape and the same directory.
     */
    private function storeCoverImage(UploadedFile $file): string
    {
        return InvitationMediaStager::storeCover($file);
    }
}
