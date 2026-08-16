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
use App\Services\InvitationCustomizationService;
use App\Support\InvitationMediaStager;
use App\Support\InvitationVideoBackground;
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
        // Two independent paginators so a long draft list never pushes published
        // events off the page. Distinct page names keep their ?page params apart.
        $mine = fn () => Event::query()
            ->where('user_id', auth()->id())
            ->orderByDesc('event_date')
            ->orderByDesc('created_at');

        $published = $mine()->where('is_published', true)
            ->paginate(10, ['*'], 'published_page')
            ->withQueryString();

        $drafts = $mine()->where('is_published', false)
            ->paginate(10, ['*'], 'draft_page')
            ->withQueryString();

        return view('events.index', compact('published', 'drafts'));
    }

    public function create(Request $request): View|RedirectResponse
    {
        if (Event::openDraftCountFor((int) $request->user()->id) >= Event::MAX_OPEN_DRAFTS) {
            return redirect()->route('events.index')->with('status', 'draft-limit');
        }

        $prefTemplateId = null;
        $slug = $request->query('template');
        if (is_string($slug) && $slug !== '') {
            $prefTemplateId = InvitationTemplate::query()
                ->where('slug', $slug)
                ->where('is_active', true)
                ->value('id');
        }

        return view('events.create', compact('prefTemplateId'));
    }

    public function store(StoreEventRequest $request): RedirectResponse
    {
        if (Event::openDraftCountFor((int) $request->user()->id) >= Event::MAX_OPEN_DRAFTS) {
            return redirect()->route('events.index')->with('status', 'draft-limit');
        }

        $data = $request->validated();
        $preferredTemplateId = $data['preferred_invitation_template_id'] ?? null;
        unset($data['preferred_invitation_template_id']);

        $newPath = null;

        try {
            if ($request->hasFile('cover_image')) {
                $newPath = $this->storeCoverImage($request->file('cover_image'));
                $data['cover_image'] = $newPath;
            }

            $data['user_id'] = (int) $request->user()->id;
            $data['is_published'] = false;

            $productKind = EventProductKind::from((string) $data['product_kind']);
            if ($productKind === EventProductKind::Ticketed) {
                $data['ticketing_status'] = TicketingStatus::Draft;
                $data['commission_mode'] = CommissionMode::Absorb;
                $data['is_public'] = true;
            } else {
                $data['ticketing_status'] = TicketingStatus::NotApplicable;
                $data['commission_mode'] = null;
            }

            $event = Event::create($data);
        } catch (\Throwable $e) {
            if ($newPath) {
                Storage::disk('public')->delete($newPath);
            }

            throw $e;
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

        return view('events.edit', compact(
            'event',
            'invitationMerged',
            'templateFingerprint',
            'customizationToken',
            'publishCostsCredit',
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

        $stagedCover = StagedMedia::query()
            ->ownedBy($event->id, $request->user()->id)
            ->where('slot', StagedMedia::SLOT_COVER)
            ->whereIn('id', array_map('intval', (array) $request->input('staged_media', [])))
            ->latest('id')
            ->first();

        // "Publish event" submits this same form, so the pending edits are saved
        // in the same request rather than being discarded by a separate publish post.
        $shouldPublish = $request->boolean('publish');
        $needsPublishCredit = false;
        $chargeable = false;

        try {
            if ($stagedCover !== null) {
                $previousCover = $event->cover_image;
                $newCoverPath = $stagedCover->path;
            } elseif ($request->hasFile('cover_image')) {
                $previousCover = $event->cover_image;
                $newCoverPath = $this->storeCoverImage($request->file('cover_image'));
                $coverIsRollbackable = true;
            }

            DB::transaction(function () use ($request, &$event, $newCoverPath, $stagedCover, $shouldPublish, $credits, &$previousCover, &$needsPublishCredit, &$chargeable): void {
                $event = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
                $data = $request->validated();

                // Never a column — it is the receipt for an upload that already happened.
                unset($data['staged_media']);

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

            throw $e;
        }

        if ($shouldPublish) {
            return redirect()->route('events.public', $event->slug)->with('status', 'published');
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

        $cover = $event->cover_image;
        $customization = $event->invitation_customization;

        DB::transaction(function () use ($event): void {
            $event->delete();
        });

        DB::afterCommit(function () use ($cover, $customization): void {
            if ($cover) {
                Storage::disk('public')->delete($cover);
            }

            if (is_array($customization)) {
                foreach ($customization['media']['gallery'] ?? [] as $path) {
                    Storage::disk('public')->delete($path);
                }
                $hero = $customization['media']['hero_portrait'] ?? null;
                if (is_string($hero) && $hero !== '') {
                    Storage::disk('public')->delete($hero);
                }
                foreach ($customization['media']['couple_photos'] ?? [] as $path) {
                    if (is_string($path) && $path !== '') {
                        Storage::disk('public')->delete($path);
                    }
                }
                $video = $customization['effects']['video_background'] ?? null;
                $audio = $customization['effects']['audio_track'] ?? null;
                if (is_string($video) && $video !== '' && ! InvitationVideoBackground::isYoutube($video)) {
                    Storage::disk('public')->delete($video);
                }
                if (is_string($audio) && $audio !== '') {
                    Storage::disk('public')->delete($audio);
                }
            }
        });

        return redirect()->route('events.index')->with('status', 'event-deleted');
    }

    public function publish(Request $request, Event $event, EventCreditService $credits): RedirectResponse
    {
        $this->authorize('update', $event);

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

    /**
     * Shared with the staging endpoint so a cover uploaded on pick and a cover
     * uploaded with the form land in the same shape and the same directory.
     */
    private function storeCoverImage(UploadedFile $file): string
    {
        return InvitationMediaStager::storeCover($file);
    }
}
