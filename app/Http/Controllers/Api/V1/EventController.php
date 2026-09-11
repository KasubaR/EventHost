<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ContributionStatus;
use App\Enums\EventProductKind;
use App\Enums\RsvpStatus;
use App\Enums\TicketingStatus;
use App\Exceptions\InsufficientCreditsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEventRequest;
use App\Http\Requests\UpdateEventRequest;
use App\Http\Resources\Api\V1\EventListResource;
use App\Http\Resources\Api\V1\EventResource;
use App\Models\CreditTransaction;
use App\Models\Event;
use App\Models\InvitationTemplate;
use App\Models\StagedMedia;
use App\Services\DashboardAnalyticsService;
use App\Services\EventCreditService;
use App\Services\EventSlugService;
use App\Services\TicketedEventCreator;
use App\Support\InvitationMediaStager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * JSON sibling of App\Http\Controllers\EventController — no session, no
 * redirect+flash, no Blade view. Every mutation's underlying logic (row locking,
 * credit spend, slug application, cover-image handling) is copied verbatim from
 * the web controller; only the response tail differs. See the Slice C1 plan for
 * the full rationale behind each structured error code. The web controller is
 * untouched by this class.
 */
class EventController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Event::class, 'event');
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $status = (string) $request->query('status', 'published');
        $kind = EventProductKind::tryFrom((string) $request->query('kind'));
        $userId = (int) $request->user()->id;

        $mine = fn () => Event::query()
            ->where('user_id', $userId)
            ->when($kind, fn ($query) => $query->where('product_kind', $kind))
            ->orderByDesc('event_date')
            ->orderByDesc('created_at');

        $paginator = match ($status) {
            'draft' => $mine()->where('is_published', false)->paginate(10),
            'deleted' => Event::onlyTrashed()
                ->where('user_id', $userId)
                ->when($kind, fn ($query) => $query->where('product_kind', $kind))
                ->orderByDesc('deleted_at')
                ->paginate(10),
            'staffing' => Event::query()
                ->whereHas('staff', fn ($query) => $query
                    ->where('user_id', $userId)
                    ->whereNotNull('accepted_at'))
                ->orderByDesc('event_date')
                ->paginate(10),
            default => $mine()->where('is_published', true)->paginate(10),
        };

        return EventListResource::collection($paginator);
    }

    public function store(StoreEventRequest $request, TicketedEventCreator $ticketedCreator): JsonResponse
    {
        if (Event::openDraftCountFor((int) $request->user()->id) >= Event::MAX_OPEN_DRAFTS) {
            return response()->json([
                'error' => 'draft_limit_reached',
                'limit' => Event::MAX_OPEN_DRAFTS,
            ], 409);
        }

        $data = $request->validated();
        $preferredTemplateId = $data['preferred_invitation_template_id'] ?? null;
        $productKind = EventProductKind::from((string) $data['product_kind']);

        if ($productKind === EventProductKind::Ticketed) {
            $event = $ticketedCreator->create((int) $request->user()->id, $data);

            return response()->json([
                'event' => new EventResource($event),
                'next_step' => 'ticket_setup',
            ], 201);
        }

        unset($data['preferred_invitation_template_id'], $data['cover_image']);
        $newPath = null;

        try {
            if ($request->hasFile('cover_image')) {
                $newPath = $this->storeCoverImage($request->file('cover_image'));
                $data['cover_image'] = $newPath;
            }

            $data['user_id'] = (int) $request->user()->id;
            $data['is_published'] = false;
            $data['ticketing_status'] = TicketingStatus::NotApplicable;
            $data['commission_mode'] = null;

            $customSlug = $data['slug'] ?? null;
            unset($data['slug']);

            $event = new Event($data);
            $slugService = app(EventSlugService::class);
            $slugService->apply(is_string($customSlug) ? $customSlug : null, $event);
            $event->save();
            $slugService->resolveAutoSlugCollision($event);
        } catch (\Throwable $e) {
            if ($newPath) {
                Storage::disk('public')->delete($newPath);
            }

            if (EventSlugService::isSlugUniqueViolation($e)) {
                throw ValidationException::withMessages([
                    'slug' => 'That custom URL is already taken.',
                ]);
            }

            throw $e;
        }

        $nextStep = 'choose_template';

        if ($preferredTemplateId !== null) {
            $preferredTemplate = InvitationTemplate::find((int) $preferredTemplateId);
            if ($preferredTemplate && $request->user()->canUseInvitationTemplate($preferredTemplate)) {
                $event->update(['invitation_template_id' => $preferredTemplate->id]);
                $nextStep = 'edit';
            }
        }

        return response()->json([
            'event' => new EventResource($event->fresh()),
            'next_step' => $nextStep,
        ], 201);
    }

    public function show(Event $event, DashboardAnalyticsService $analyticsService): EventResource
    {
        $rsvpSummary = [
            'invited' => $event->guests()->count(),
            'pending' => $event->guests()->whereDoesntHave('rsvp')->count(),
            'accepted' => $event->rsvps()->where('status', RsvpStatus::Accepted)->count(),
            'declined' => $event->rsvps()->where('status', RsvpStatus::Declined)->count(),
            'maybe' => $event->rsvps()->where('status', RsvpStatus::Maybe)->count(),
            'accepted_heads' => $event->acceptedAttendeeHeadcount(),
        ];

        $contributionSummary = $event->acceptsContributions() ? [
            'pledges' => $event->eventContributions()->count(),
            'completed' => $event->eventContributions()->where('status', ContributionStatus::Completed)->count(),
            'collected' => (float) $event->eventContributions()->sum('amount_paid'),
        ] : null;

        return new EventResource($event, $rsvpSummary, $analyticsService->forEvent($event), $contributionSummary);
    }

    public function update(UpdateEventRequest $request, Event $event, EventCreditService $credits): JsonResponse
    {
        $newCoverPath = null;
        $previousCover = null;
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

                if ($wasPublished && $event->isDirty(['venue', 'location_name', 'latitude', 'longitude'])) {
                    $notifyGuestsCount = $event->guests()
                        ->where(function ($query): void {
                            $query->where('invitation_sent', true)->orWhereHas('rsvp');
                        })
                        ->count();
                }

                app(EventSlugService::class)->apply(is_string($customSlug) ? $customSlug : null, $event);
                $event->save();

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

                return response()->json(['error' => $status, 'message' => $message], 422);
            }

            if (EventSlugService::isSlugUniqueViolation($e)) {
                throw ValidationException::withMessages([
                    'slug' => 'That custom URL is already taken.',
                ]);
            }

            throw $e;
        }

        return response()->json([
            'event' => new EventResource($event->fresh()),
            'notify_guests' => $notifyGuestsCount > 0 ? [
                'count' => $notifyGuestsCount,
                'url' => route('api.v1.host.events.show', $event),
            ] : null,
        ])->setStatusCode(200);
    }

    public function destroy(Event $event): JsonResponse
    {
        $this->authorize('delete', $event);

        if ($event->hasBlockingTicketCommerce()) {
            throw ValidationException::withMessages([
                'event' => 'This event has ticket holds or orders in progress and cannot be deleted.',
            ]);
        }

        $event->delete();

        return response()->json(null, 204);
    }

    public function restore(Event $event): JsonResponse
    {
        $this->authorize('restore', $event);

        if ($event->trashed()) {
            $event->restore();
        }

        return response()->json(new EventResource($event))->setStatusCode(200);
    }

    public function pause(Event $event): JsonResponse
    {
        $this->authorize('pause', $event);

        if (! $event->is_published || $event->trashed() || $event->isCancelled()) {
            return response()->json([
                'error' => 'invalid_lifecycle_transition',
                'message' => 'Only a live invitation can be paused.',
            ], 422);
        }

        $event->invitation_paused_at = now();
        $event->save();

        return response()->json(new EventResource($event->fresh()))->setStatusCode(200);
    }

    public function resume(Event $event): JsonResponse
    {
        $this->authorize('pause', $event);

        $event->invitation_paused_at = null;
        $event->save();

        return response()->json(new EventResource($event->fresh()))->setStatusCode(200);
    }

    public function cancel(Event $event): JsonResponse
    {
        $this->authorize('cancel', $event);

        if ($event->trashed() || ! $event->is_published) {
            return response()->json([
                'error' => 'invalid_lifecycle_transition',
                'message' => 'Only a published event can be cancelled.',
            ], 422);
        }

        $event->cancelled_at = now();
        $event->invitation_paused_at = null;
        $event->save();

        return response()->json(new EventResource($event->fresh()))->setStatusCode(200);
    }

    public function uncancel(Event $event): JsonResponse
    {
        $this->authorize('cancel', $event);

        $event->cancelled_at = null;
        $event->save();

        return response()->json(new EventResource($event->fresh()))->setStatusCode(200);
    }

    public function publish(Request $request, Event $event, EventCreditService $credits): JsonResponse
    {
        $this->authorize('publish', $event);

        if ($event->isTicketed()) {
            return response()->json([
                'error' => 'ticketed_events_use_admin_approval',
                'message' => 'Ticketed events go live after EventHost activates ticket sales — they do not use event credits.',
            ], 422);
        }

        $needsPublishCredit = ! $event->is_published && ! $event->hasConsumedPublishCredit();

        if ($needsPublishCredit && ! $request->user()->canCreateEvent()) {
            return response()->json(['error' => 'no_event_credits'], 402);
        }

        try {
            DB::transaction(function () use ($request, $event, $credits): void {
                $locked = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

                $credits->chargeFirstPublish($request->user(), $locked);

                $locked->is_published = true;
                $locked->save();
            });
        } catch (InsufficientCreditsException) {
            return response()->json(['error' => 'no_event_credits'], 402);
        }

        return response()->json(new EventResource($event->fresh()))->setStatusCode(200);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function insufficientCreditFeedback(bool $needsPublishCredit, bool $chargeable): array
    {
        if ($needsPublishCredit && $chargeable) {
            return [
                'Publishing this event and changing its name, type or date uses 2 event credits, and you do not have enough.',
                'no_event_credits',
            ];
        }

        if ($needsPublishCredit) {
            return [
                'Publishing uses 1 event credit, and you have none left.',
                'no_event_credits',
            ];
        }

        return [
            'Changing the name, type or date of an event that has already taken place '
            .'uses 1 event credit, and you have none left.',
            'no_credits_to_redefine',
        ];
    }

    private function storeCoverImage(UploadedFile $file): string
    {
        return InvitationMediaStager::storeCover($file);
    }
}
