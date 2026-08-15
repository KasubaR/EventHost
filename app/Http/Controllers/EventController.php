<?php

namespace App\Http\Controllers;

use App\Enums\RsvpStatus;
use App\Exceptions\InsufficientCreditsException;
use App\Http\Requests\StoreEventRequest;
use App\Http\Requests\UpdateEventRequest;
use App\Models\CreditTransaction;
use App\Models\Event;
use App\Models\InvitationTemplate;
use App\Services\DashboardAnalyticsService;
use App\Services\EventCreditService;
use App\Services\InvitationCustomizationService;
use App\Support\InvitationVideoBackground;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Intervention\Image\ImageManager;

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
        if (! $request->user()->canCreateEvent()) {
            return redirect()->route('billing.show')->with('status', 'no-event-credits');
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

    public function store(StoreEventRequest $request, EventCreditService $credits): RedirectResponse
    {
        if (! $request->user()->canCreateEvent()) {
            return redirect()->route('billing.show')->with('status', 'no-event-credits');
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

            // The check above is only a friendly redirect — it can be passed by two
            // concurrent submits holding the same balance. The real gate is the row
            // lock inside spend(), and the event is created in the same transaction
            // so a refused spend cannot leave a free event behind.
            $event = DB::transaction(function () use ($request, $data, $credits): Event {
                $event = Event::create($data);

                $credits->spend(
                    $request->user(),
                    CreditTransaction::REASON_EVENT_CREATED,
                    $event
                );

                return $event;
            });
        } catch (\Throwable $e) {
            if ($newPath) {
                Storage::disk('public')->delete($newPath);
            }

            if ($e instanceof InsufficientCreditsException) {
                return redirect()->route('billing.show')->with('status', 'no-event-credits');
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

        if ($event->invitation_template_id !== null) {
            $invitationMerged = $customizationService->merge($event);

            $resolvedTemplate = $event->getRelation('invitationTemplate');
            $templateFingerprint = $customizationService->templateFingerprint($resolvedTemplate);
            $customizationToken = md5(json_encode($event->invitation_customization) ?: '');
        }

        return view('events.edit', compact('event', 'invitationMerged', 'templateFingerprint', 'customizationToken'));
    }

    public function update(
        UpdateEventRequest $request,
        Event $event,
        EventCreditService $credits
    ): RedirectResponse|JsonResponse {
        $newCoverPath = null;
        $previousCover = null;

        // "Publish event" submits this same form, so the pending edits are saved
        // in the same request rather than being discarded by a separate publish post.
        $shouldPublish = $request->boolean('publish');

        // The credit bought one occurrence. Rewriting what the event *is* after
        // it has happened is a second occurrence, so it costs another credit.
        // Everything else — and every edit before the date — stays free.
        $chargeable = $event->isLocked() && $event->identityChangedBy($request->validated());

        try {
            if ($request->hasFile('cover_image')) {
                $previousCover = $event->cover_image;
                $newCoverPath = $this->storeCoverImage($request->file('cover_image'));
            }

            DB::transaction(function () use ($request, $event, $newCoverPath, $shouldPublish, $chargeable, $credits, &$previousCover): void {
                $data = $request->validated();

                if ($newCoverPath !== null) {
                    $data['cover_image'] = $newCoverPath;
                }

                if ($shouldPublish) {
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

                if ($previousCover) {
                    DB::afterCommit(function () use ($previousCover): void {
                        Storage::disk('public')->delete($previousCover);
                    });
                }
            });
        } catch (\Throwable $e) {
            if ($newCoverPath !== null) {
                Storage::disk('public')->delete($newCoverPath);
            }

            if ($e instanceof InsufficientCreditsException) {
                $message = 'Changing the name, type or date of an event that has already taken place '
                    .'uses 1 event credit, and you have none left.';

                // The edit page saves over fetch(), which would follow a redirect
                // and report it as success — it needs a 422 to show the message.
                if ($request->expectsJson()) {
                    return response()->json(['message' => $message], 422);
                }

                return redirect()
                    ->route('billing.show')
                    ->with('status', 'no-credits-to-redefine');
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

    public function publish(Event $event): RedirectResponse
    {
        $this->authorize('update', $event);

        // Publishing does not change what the event is, so it stays free even
        // for a past event — only identity changes are chargeable.
        $event->update(['is_published' => true]);

        return redirect()->route('events.public', $event->slug)->with('status', 'published');
    }

    private function storeCoverImage(UploadedFile $file): string
    {
        $manager = extension_loaded('imagick')
            ? ImageManager::imagick()
            : ImageManager::gd();
        $image = $manager->read($file->getRealPath());
        $image->cover(1200, 630);
        $webp = $image->toWebp(85);

        $path = 'events/'.uniqid('event_', true).'.webp';
        Storage::disk('public')->put($path, $webp->toString());

        return $path;
    }
}
